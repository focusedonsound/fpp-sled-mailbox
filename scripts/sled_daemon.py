#!/usr/bin/env python3
# =============================================================================
# sled_daemon.py — SLED Santa Mailbox main daemon (FPP plugin edition)
# =============================================================================
# Adaptation of the standalone app.py for FPP:
#   - Config from JSON (not YAML)
#   - SQLite persistence via sled_db.py
#   - Manual triggers via command queue file (for FPP Commands)
#   - All paths under /home/fpp/media/

from __future__ import annotations

import json
import logging
import os
import signal
import subprocess
import sys
import time
import threading
import datetime as dt
from typing import Any, Dict, Optional

# ── Ensure our scripts/ dir is importable ──────────────────────────────────
_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, _SCRIPT_DIR)

from playback import Player
from ha import HAMqtt
from sensors import MockInputs, GPIOInputs
from sled_db import SledDB

# Optional DHT11 support
try:
    import adafruit_dht
    import board as adafruit_board
    DHT_AVAILABLE = True
except ImportError:
    DHT_AVAILABLE = False

# ── Paths ──────────────────────────────────────────────────────────────────
CONFIG_FILE    = "/home/fpp/media/config/sled.json"
LOG_FILE       = "/home/fpp/media/logs/SledMailbox.log"
CMD_QUEUE_FILE = "/home/fpp/media/logs/sled_trigger.cmd"
PID_FILE       = "/home/fpp/media/logs/sled_daemon.pid"

# ── Logging ────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="[%(asctime)s] [%(name)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stdout),
    ],
)
log = logging.getLogger("sled")


# =============================================================================
# Config helpers
# =============================================================================

def load_cfg() -> Dict[str, Any]:
    with open(CONFIG_FILE) as f:
        return json.load(f)


def in_window(cfg: Dict[str, Any], now: Optional[dt.datetime] = None) -> bool:
    now = now or dt.datetime.now()
    sched = cfg.get("schedule", {})
    start_str = sched.get("start", "00:00")
    end_str   = sched.get("end", "23:59")
    s = dt.datetime.strptime(start_str, "%H:%M").time()
    e = dt.datetime.strptime(end_str,   "%H:%M").time()
    return s <= now.time() < e


# =============================================================================
# Screen power management (kmsblank)
# =============================================================================

_kmsblank_proc: Optional[subprocess.Popen] = None


def screen_off() -> None:
    global _kmsblank_proc
    if _kmsblank_proc is not None and _kmsblank_proc.poll() is None:
        return
    try:
        _kmsblank_proc = subprocess.Popen(
            ["kmsblank"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
        )
        log.info("[Screen] HDMI OFF (kmsblank)")
    except FileNotFoundError:
        _kmsblank_proc = None


def screen_on() -> None:
    global _kmsblank_proc
    if _kmsblank_proc is None:
        return
    if _kmsblank_proc.poll() is not None:
        _kmsblank_proc = None
        return
    _kmsblank_proc.terminate()
    try:
        _kmsblank_proc.wait(timeout=2)
    except subprocess.TimeoutExpired:
        _kmsblank_proc.kill()
    _kmsblank_proc = None
    log.info("[Screen] HDMI ON")


# =============================================================================
# DHT11 background thread
# =============================================================================

def start_dht_thread(cfg: Dict[str, Any], ha: HAMqtt) -> None:
    dcfg = cfg.get("dht11", {})
    if not dcfg.get("enabled", False):
        return
    if not DHT_AVAILABLE:
        log.warning("[DHT11] adafruit_dht not installed — DHT11 disabled")
        return

    pin_num  = int(dcfg.get("pin", 4))
    interval = int(dcfg.get("interval_s", 60))

    pin_map = {4: "D4", 17: "D17", 27: "D27"}
    board_pin_name = pin_map.get(pin_num)
    if not board_pin_name or not hasattr(adafruit_board, board_pin_name):
        log.warning("[DHT11] Unsupported pin GPIO%d", pin_num)
        return

    dht_pin = getattr(adafruit_board, board_pin_name)
    dht = adafruit_dht.DHT11(dht_pin, use_pulseio=False)

    def loop() -> None:
        while True:
            try:
                temp_c = dht.temperature
                hum    = dht.humidity
                if temp_c is not None and hum is not None:
                    ha.set_env(temp_c, hum)
                    log.debug("[DHT11] temp=%.1f°C hum=%.1f%%", temp_c, hum)
            except RuntimeError as exc:
                log.debug("[DHT11] Read error: %s", exc)
            time.sleep(interval)

    threading.Thread(target=loop, daemon=True, name="DHT11").start()
    log.info("[DHT11] Started on GPIO%d, interval=%ds", pin_num, interval)


# =============================================================================
# Command queue (FPP Commands → daemon IPC)
# =============================================================================

def poll_cmd_queue() -> Optional[str]:
    """
    Check CMD_QUEUE_FILE for a pending event code.
    Returns the code and removes the file, or None if no pending command.
    Expected format: single line containing "letter", "donation", or "stop".
    """
    if not os.path.isfile(CMD_QUEUE_FILE):
        return None
    try:
        with open(CMD_QUEUE_FILE) as f:
            cmd = f.read().strip().lower()
        os.unlink(CMD_QUEUE_FILE)
        return cmd if cmd else None
    except Exception as exc:
        log.warning("[CmdQueue] Read error: %s", exc)
        return None


# =============================================================================
# Signal handling
# =============================================================================

_shutdown = threading.Event()


def _handle_signal(signum, frame) -> None:
    log.info("Signal %s received — shutting down", signum)
    _shutdown.set()


signal.signal(signal.SIGTERM, _handle_signal)
signal.signal(signal.SIGINT,  _handle_signal)


# =============================================================================
# Main
# =============================================================================

def main() -> None:
    log.info("=== SLED Santa Mailbox daemon starting ===")

    # Write PID file
    with open(PID_FILE, "w") as f:
        f.write(str(os.getpid()))

    cfg = load_cfg()
    if not cfg.get("enabled", True):
        log.info("Plugin disabled in config — exiting")
        return

    # Direction labels
    dir_cfg      = cfg.get("direction", {})
    toward_ref   = (dir_cfg.get("toward_reference") or "AB").upper()
    label_tow    = dir_cfg.get("label_toward", "Inbound")
    label_away   = dir_cfg.get("label_away",   "Outbound")

    def label_for(seq: str) -> str:
        return label_tow if seq == toward_ref else label_away

    # Timings
    car_cfg        = cfg.get("car", {})
    seq_window_s   = float(car_cfg.get("sequence_window_s", 0.8))
    car_cooldown_s = float(car_cfg.get("cooldown_s", 1.5))

    letter_cd_s   = float(cfg.get("letter",   {}).get("cooldown_s", 3.0))
    donation_cd_s = float(cfg.get("donation", {}).get("cooldown_s", 5.0))

    # Video config
    video_cfg    = cfg.get("video", {})
    video_dir    = cfg.get("paths", {}).get("videos",
                   "/home/fpp/media/plugins/fpp-sled-mailbox/videos")
    idle_name    = video_cfg.get("idle", "idle.mp4")
    letter_clips = video_cfg.get("letter_clips", [])
    donate_clips = video_cfg.get("donation_clips", [])
    clip_timeout = int(video_cfg.get("play_timeout_s", 65))

    # Subsystems
    player = Player(video_dir)
    ha     = HAMqtt(cfg)
    db     = SledDB()

    start_dht_thread(cfg, ha)

    # Restore persisted counters to MQTT
    snap = db.snapshot()
    ha.set_car_total(snap["car_total"])
    ha.set_car_today(snap["car_today"])
    ha.set_inbound_today(snap["inbound_today"])
    ha.set_outbound_today(snap["outbound_today"])
    ha.set_letter_total(snap["letter_total"])
    ha.set_donation_total(snap["donation_total"])

    # Sensors
    debug_cfg = cfg.get("debug", {})
    use_mock  = bool(debug_cfg.get("use_mock_inputs", False))
    if use_mock:
        ins = MockInputs()
        log.info("Using mock inputs")
    else:
        pins_cfg    = cfg.get("pins", {})
        letter_pin  = int(pins_cfg.get("letter", 17))
        donation_pin_raw = pins_cfg.get("donation")
        donation_pin = int(donation_pin_raw) if donation_pin_raw is not None else None
        ins = GPIOInputs(letter_pin, donation_pin, cfg)
        log.info("Using GPIO inputs (letter=GPIO%d, donation=%s)",
                 letter_pin, f"GPIO{donation_pin}" if donation_pin else "none")

    # FSM state
    tA:            Optional[float] = None
    tB:            Optional[float] = None
    last_car_time: float = 0.0

    last_letter_time:   float = 0.0
    last_donation_time: float = 0.0

    # Clip round-robin
    next_letter_idx:   int = 0
    next_donation_idx: int = 0

    # Schedule tracking
    last_sched_check: float = 0.0
    sched_inside:     Optional[bool] = None

    # Set initial screen/idle state
    if in_window(cfg):
        screen_on()
        player.start_idle(idle_name)
    else:
        player.stop_idle()
        screen_off()

    log.info("SLED daemon running (PID=%d)", os.getpid())

    try:
        while not _shutdown.is_set():
            now_ts = time.time()

            # Midnight reset
            if db.check_midnight_reset():
                log.info("Midnight reset: today counters cleared")
                ha.set_car_today(0)
                ha.set_inbound_today(0)
                ha.set_outbound_today(0)

            # Schedule tick (every ~5 s)
            if now_ts - last_sched_check >= 5.0:
                last_sched_check = now_ts
                now_inside = in_window(cfg)
                if now_inside != sched_inside:
                    sched_inside = now_inside
                    if now_inside:
                        screen_on()
                        player.start_idle(idle_name)
                        log.info("[Schedule] Entered active window → idle started")
                    else:
                        player.stop_idle()
                        screen_off()
                        log.info("[Schedule] Left active window → idle stopped")

            # Check FPP command queue (manual triggers)
            queued = poll_cmd_queue()
            if queued == "letter":
                ins._q.put_nowait("l") if hasattr(ins, "_q") else None  # type: ignore[attr-defined]
            elif queued == "donation":
                ins._q.put_nowait("d") if hasattr(ins, "_q") else None  # type: ignore[attr-defined]
            elif queued == "stop":
                log.info("[CmdQueue] STOP received — shutting down")
                _shutdown.set()
                break

            # Poll hardware / mock inputs
            ev = ins.get_event(timeout=0.1)
            if not ev:
                continue

            if ev == "q":
                log.info("Quit requested via mock input")
                break

            # ── LETTER ──────────────────────────────────────────────
            if ev == "l":
                if now_ts - last_letter_time < letter_cd_s:
                    log.debug("[Letter] Ignored duplicate (cooldown)")
                    continue
                last_letter_time = now_ts

                screen_on()
                player.stop_idle()

                clip = letter_clips[next_letter_idx % len(letter_clips)] if letter_clips else idle_name
                next_letter_idx += 1
                log.info("[Letter] Playing %s", clip)
                player.play_once(clip, timeout=clip_timeout)

                now_iso = dt.datetime.now(dt.timezone.utc).astimezone().isoformat()
                ha.pulse_letter()
                ha.set_last_letter(now_iso)
                ha.event("letter", {"clip": clip, "ts": now_iso})

                lt = db.increment_counter("letter_total")
                ha.set_letter_total(lt)
                db.log_event("letter", {"clip": clip})

                if in_window(cfg):
                    player.start_idle(idle_name)
                else:
                    player.stop_idle()
                    screen_off()
                continue

            # ── DONATION ─────────────────────────────────────────────
            if ev == "d":
                if now_ts - last_donation_time < donation_cd_s:
                    log.debug("[Donation] Ignored duplicate (cooldown)")
                    continue
                last_donation_time = now_ts

                screen_on()
                player.stop_idle()

                if donate_clips:
                    clip = donate_clips[next_donation_idx % len(donate_clips)]
                elif letter_clips:
                    clip = letter_clips[0]
                else:
                    clip = idle_name
                next_donation_idx += 1
                log.info("[Donation] Playing %s", clip)
                player.play_once(clip, timeout=clip_timeout)

                now_iso = dt.datetime.now(dt.timezone.utc).astimezone().isoformat()
                ha.pulse_donation()
                ha.set_last_donation(now_iso)
                ha.event("donation", {"clip": clip, "ts": now_iso})

                dt_ = db.increment_counter("donation_total")
                ha.set_donation_total(dt_)
                db.log_event("donation", {"clip": clip})

                if in_window(cfg):
                    player.start_idle(idle_name)
                else:
                    player.stop_idle()
                    screen_off()
                continue

            # ── CAR RADAR A ──────────────────────────────────────────
            if ev == "a":
                tA = now_ts
                if (tB and 0 < (tA - tB) <= seq_window_s
                        and (now_ts - last_car_time) > car_cooldown_s):
                    last_car_time = now_ts
                    dir_seq  = "BA"
                    friendly = label_for(dir_seq)
                    _record_car(db, ha, friendly, label_tow, dir_seq)
                continue

            # ── CAR RADAR B ──────────────────────────────────────────
            if ev == "b":
                tB = now_ts
                if (tA and 0 < (tB - tA) <= seq_window_s
                        and (now_ts - last_car_time) > car_cooldown_s):
                    last_car_time = now_ts
                    dir_seq  = "AB"
                    friendly = label_for(dir_seq)
                    _record_car(db, ha, friendly, label_tow, dir_seq)
                continue

    finally:
        log.info("SLED daemon shutting down...")
        player.stop_idle()
        screen_on()
        ha.close()
        db.close()
        try:
            os.unlink(PID_FILE)
        except Exception:
            pass
        log.info("=== SLED daemon stopped ===")


def _record_car(db: SledDB, ha: HAMqtt, friendly: str, label_tow: str, dir_seq: str) -> None:
    snap = db.snapshot()
    ct   = db.increment_counter("car_total")
    ctd  = db.increment_counter("car_today")
    if friendly == label_tow:
        itd = db.increment_counter("inbound_today")
        otd = snap["outbound_today"]
    else:
        otd = db.increment_counter("outbound_today")
        itd = snap["inbound_today"]

    now_iso = dt.datetime.now(dt.timezone.utc).astimezone().isoformat()
    ha.set_last_car_time(now_iso)
    ha.set_last_dir(friendly)
    ha.set_car_total(ct)
    ha.set_car_today(ctd)
    ha.set_inbound_today(itd)
    ha.set_outbound_today(otd)
    ha.event("car", {"dir_seq": dir_seq, "dir": friendly, "ts": now_iso})
    db.log_event("car", {"dir_seq": dir_seq, "dir": friendly})

    log.info("[Car] %s  total=%d today=%d (in=%d out=%d)",
             friendly, ct, ctd, itd, otd)


if __name__ == "__main__":
    main()
