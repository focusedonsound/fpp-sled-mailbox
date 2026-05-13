#!/usr/bin/env python3
# =============================================================================
# sled_daemon.py — SLED Santa Mailbox daemon (FPP plugin edition)
#
# Architecture: this daemon handles only what FPP cannot do natively:
#   • LD2410 radar serial I/O and car-detection state machine
#   • GPIO letter / donation sensors
#   • MQTT telemetry (Home Assistant discovery)
#   • SQLite event/counter persistence
#
# Video playback is delegated entirely to FPP via its local REST API.
# The daemon tells FPP which playlist to start; FPP owns the screen.
# =============================================================================

from __future__ import annotations

import json
import logging
import os
import signal
import sys
import time
import threading
import datetime as dt
import urllib.request
import urllib.parse
from typing import Any, Dict, List, Optional

# ── Ensure our scripts/ dir and vendor dir are importable ─────────────────────
_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
_VENDOR_DIR  = os.path.join(_SCRIPT_DIR, "vendor")
sys.path.insert(0, _SCRIPT_DIR)
if os.path.isdir(_VENDOR_DIR):
    sys.path.insert(0, _VENDOR_DIR)

from ha import HAMqtt
from sensors import MockInputs, GPIOInputs
from sled_db import SledDB
from sled_telemetry import SledTelemetry

# Optional DHT11 support
try:
    import adafruit_dht
    import board as adafruit_board
    DHT_AVAILABLE = True
except ImportError:
    DHT_AVAILABLE = False

# ── Paths ──────────────────────────────────────────────────────────────────────
CONFIG_FILE    = "/home/fpp/media/config/sled.json"
LOG_FILE       = "/home/fpp/media/logs/SledMailbox.log"
CMD_QUEUE_FILE = "/home/fpp/media/logs/sled_trigger.cmd"
PID_FILE       = "/home/fpp/media/logs/sled_daemon.pid"

# ── Logging ────────────────────────────────────────────────────────────────────
# Ensure the log directory exists BEFORE opening the FileHandler.
# Without this the daemon dies silently if systemd starts it before
# /home/fpp/media/logs is created (the FileNotFoundError is never logged).
_LOG_DIR = os.path.dirname(LOG_FILE)
try:
    os.makedirs(_LOG_DIR, exist_ok=True)
except OSError:
    pass  # best effort; FileHandler fallback below handles the error case

_log_handlers: list = []
try:
    _log_handlers.append(logging.FileHandler(LOG_FILE))
except OSError:
    # Fall back to stderr so there is *some* output (visible in systemd journal)
    _log_handlers.append(logging.StreamHandler(sys.stderr))

logging.basicConfig(
    level=logging.INFO,
    format="[%(asctime)s] [%(name)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    handlers=_log_handlers,
)
log = logging.getLogger("sled")


# =============================================================================
# FPP Player — thin wrapper around FPP's local REST API
# =============================================================================

class FPPPlayer:
    """
    Controls FPP's built-in media player via the local REST API.
    All methods are silent on error — the daemon never dies because FPP is
    slow or restarting.
    """

    _API = "http://localhost"

    def _cmd(self, command: str, args: List[str] = None) -> Optional[str]:
        """GET an FPP command via /api/command/{cmd}/{arg1}/{arg2}/...
        FPP 10.x uses GET with path-based arguments (POST returns 500)."""
        try:
            parts = [urllib.parse.quote(str(command), safe="")]
            for a in (args or []):
                parts.append(urllib.parse.quote(str(a), safe=""))
            url = f"{self._API}/api/command/" + "/".join(parts)
            with urllib.request.urlopen(url, timeout=3) as r:
                return r.read().decode()
        except Exception as exc:
            log.debug("[FPP] command %r failed: %s", command, exc)
            return None

    def _status(self) -> Optional[dict]:
        """GET FPP's current status."""
        try:
            with urllib.request.urlopen(f"{self._API}/api/fppd/status", timeout=3) as r:
                return json.loads(r.read())
        except Exception as exc:
            log.debug("[FPP] status check failed: %s", exc)
            return None

    def current_playlist(self) -> str:
        """Return the name of the currently-playing FPP playlist, or ''."""
        status = self._status()
        if not status:
            return ""
        return status.get("current_playlist", {}).get("playlist", "")

    def start_playlist(self, name: str, repeat: bool = False) -> None:
        """Start a named FPP playlist (non-blocking).
        Pass repeat=True for the idle loop so it runs continuously."""
        if not name:
            return
        log.info("[FPP] start playlist: %s (repeat=%s)", name, repeat)
        self._cmd("Start Playlist", [name, "true" if repeat else "false"])

    def stop(self) -> None:
        """Stop FPP playback immediately."""
        log.info("[FPP] stop")
        self._cmd("Stop Now")

    def play_once(self, name: str, timeout_s: int = 120) -> None:
        """
        Start a playlist and block until it finishes (or timeout/shutdown).
        Polls FPP status every 0.5 s.  After the playlist ends (or on timeout)
        control returns to the caller, which decides whether to restart idle.
        """
        if not name:
            return
        self.start_playlist(name)

        # Wait up to 2 s for FPP to actually begin the playlist
        t0 = time.time()
        while time.time() - t0 < 2.0 and not _shutdown.is_set():
            if self.current_playlist() == name:
                break
            time.sleep(0.2)

        # Now wait for it to finish
        deadline = time.time() + timeout_s
        while time.time() < deadline and not _shutdown.is_set():
            if self.current_playlist() != name:
                return          # playlist ended normally
            time.sleep(0.5)

        # Timeout — stop gracefully so we don't get stuck
        if self.current_playlist() == name:
            log.warning("[FPP] play_once timeout for %r — stopping", name)
            self.stop()


# =============================================================================
# Config helpers
# =============================================================================

def load_cfg() -> Dict[str, Any]:
    try:
        with open(CONFIG_FILE) as f:
            return json.load(f)
    except FileNotFoundError:
        log.warning("Config file not found (%s) — using defaults", CONFIG_FILE)
        return {}
    except json.JSONDecodeError as exc:
        log.error("Config file is invalid JSON (%s) — using defaults: %s", CONFIG_FILE, exc)
        return {}


def in_window(cfg: Dict[str, Any], now: Optional[dt.datetime] = None) -> bool:
    now = now or dt.datetime.now()
    sched = cfg.get("schedule", {})
    start_str = sched.get("start", "00:00")
    end_str   = sched.get("end",   "23:59")
    s = dt.datetime.strptime(start_str, "%H:%M").time()
    e = dt.datetime.strptime(end_str,   "%H:%M").time()
    return s <= now.time() < e


# =============================================================================
# DHT11 background thread (optional)
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
# Command queue  (trigger.php → daemon IPC)
# =============================================================================

def poll_cmd_queue() -> Optional[str]:
    """
    Check CMD_QUEUE_FILE for a pending command written by trigger.php.
    Returns the command string (consuming the file), or None.

    Commands:
      letter         — inject letter event
      donation       — inject donation event
      stop           — shut daemon down
      cleanup        — remove HA discovery entities
      diag_start_a/b — enter radar diagnostic mode
      diag_stop      — exit radar diagnostic mode
      diag_set_a/b:  — write radar config JSON
    """
    if not os.path.isfile(CMD_QUEUE_FILE):
        return None
    try:
        with open(CMD_QUEUE_FILE) as f:
            cmd = f.read().strip()
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
# Parked-car state tracker
# =============================================================================

class ParkedState:
    """
    Tracks continuous radar presence. Once presence exceeds parked_timeout_s
    the radar is considered 'parked' and further car events are suppressed
    until the car leaves and a new rising edge arrives.
    """

    def __init__(self, side: str, timeout_s: float) -> None:
        self.side      = side
        self.timeout_s = float(timeout_s)
        self._parked   = False
        self._since: Optional[float] = None

    def on_presence(self, now: float) -> None:
        if self._since is None:
            self._since = now
        self._parked = False

    def on_absence(self, now: float) -> None:
        self._since  = None
        self._parked = False

    def tick(self, now: float, db: SledDB) -> None:
        if self._since is None or self._parked:
            return
        if (now - self._since) >= self.timeout_s:
            self._parked = True
            log.info("[Parked] Radar %s parked after %.0f s continuous presence",
                     self.side, self.timeout_s)
            db.log_event("parked", {"radar": self.side})

    @property
    def is_parked(self) -> bool:
        return self._parked

    def allow_trigger(self) -> bool:
        return not self._parked


# =============================================================================
# Main
# =============================================================================

def main() -> None:
    log.info("=== SLED Santa Mailbox daemon starting ===")

    with open(PID_FILE, "w") as f:
        f.write(str(os.getpid()))

    cfg = load_cfg()
    if not cfg.get("enabled", True):
        log.info("Plugin disabled in config — exiting")
        return

    # ── Direction config ───────────────────────────────────────────────────────
    dir_cfg    = cfg.get("direction", {})
    toward_ref = (dir_cfg.get("toward_reference") or "AB").upper()
    label_tow  = dir_cfg.get("label_toward", "Inbound")
    label_away = dir_cfg.get("label_away",   "Outbound")

    def label_for(seq: str) -> str:
        return label_tow if seq == toward_ref else label_away

    # ── Timings ────────────────────────────────────────────────────────────────
    car_cfg          = cfg.get("car", {})
    seq_window_s     = float(car_cfg.get("direction_window_s",
                             car_cfg.get("sequence_window_s", 10.0)))
    car_cooldown_s   = float(car_cfg.get("cooldown_s", 1.5))

    ld_cfg           = cfg.get("ld2410", {}) or {}
    parked_timeout_s = float(ld_cfg.get("parked_car_timeout", 180))

    letter_cd_s   = float(cfg.get("letter",   {}).get("cooldown_s", 3.0))
    donation_cd_s = float(cfg.get("donation", {}).get("cooldown_s", 5.0))

    # ── Playlist config ────────────────────────────────────────────────────────
    pl_cfg       = cfg.get("playlists", {})
    pl_idle      = pl_cfg.get("idle",     "sled_idle")
    pl_letters   = pl_cfg.get("letter",   ["sled_letter"])
    pl_donations = pl_cfg.get("donation", [])
    pl_timeout   = int(pl_cfg.get("play_timeout_s", 120))

    # Normalise to lists
    if isinstance(pl_letters,   str): pl_letters   = [pl_letters]
    if isinstance(pl_donations, str): pl_donations = [pl_donations]

    # Fallback: donation uses letter playlist if none configured
    if not pl_donations:
        pl_donations = pl_letters[:1]

    # ── Subsystems ─────────────────────────────────────────────────────────────
    fpp = FPPPlayer()
    ha  = HAMqtt(cfg)
    db  = SledDB()

    telemetry = SledTelemetry(cfg, db)
    telemetry.start()

    start_dht_thread(cfg, ha)

    # Restore persisted counters to MQTT
    snap = db.snapshot()
    ha.set_car_total(snap["car_total"])
    ha.set_car_today(snap["car_today"])
    ha.set_inbound_today(snap["inbound_today"])
    ha.set_outbound_today(snap["outbound_today"])
    ha.set_letter_total(snap["letter_total"])
    ha.set_donation_total(snap["donation_total"])

    letter_today_count:   int = db.get_today_count("letter")
    donation_today_count: int = db.get_today_count("donation")
    ha.set_letter_today(letter_today_count)
    ha.set_donation_today(donation_today_count)

    # ── Sensors ────────────────────────────────────────────────────────────────
    debug_cfg = cfg.get("debug", {})
    use_mock  = bool(debug_cfg.get("use_mock_inputs", False))
    if use_mock:
        ins = MockInputs()
        log.info("Using mock inputs")
    else:
        pins_cfg     = cfg.get("pins", {})
        letter_pin   = int(pins_cfg.get("letter", 17))
        donation_raw = pins_cfg.get("donation")
        # Guard against None, empty string, or "null" saved from the UI
        donation_pin = int(donation_raw) if donation_raw not in (None, "", "null") else None
        ins = GPIOInputs(letter_pin, donation_pin, cfg)
        log.info("Using GPIO inputs (letter=GPIO%d, donation=%s)",
                 letter_pin, f"GPIO{donation_pin}" if donation_pin else "none")

    # ── FSM / timing state ─────────────────────────────────────────────────────
    tA: Optional[float] = None
    tB: Optional[float] = None
    last_car_time:      float = 0.0
    last_letter_time:   float = 0.0
    last_donation_time: float = 0.0
    next_letter_idx:    int   = 0
    next_donation_idx:  int   = 0
    last_sched_check:   float = 0.0
    last_idle_start_ts: float = 0.0
    sched_inside:       Optional[bool] = None

    parked_a = ParkedState("A", parked_timeout_s)
    parked_b = ParkedState("B", parked_timeout_s)

    # ── Initial playback state ─────────────────────────────────────────────────
    # Initialise sched_inside to the current window state so the first schedule
    # tick doesn't redundantly "enter" the window and call start_playlist again.
    sched_inside: Optional[bool] = in_window(cfg)
    if sched_inside:
        # Only start idle if FPP isn't already playing it (e.g. daemon restarted
        # while fppd was mid-playlist — no need to stop/restart).
        if fpp.current_playlist() != pl_idle:
            fpp.start_playlist(pl_idle, repeat=True)
            last_idle_start_ts = time.time()
    else:
        if fpp.current_playlist() != "":
            fpp.stop()

    log.info("SLED daemon running (PID=%d)", os.getpid())

    try:
        while not _shutdown.is_set():
            now_ts = time.time()

            # ── Midnight reset ─────────────────────────────────────────────────
            if db.check_midnight_reset():
                log.info("Midnight reset: today counters cleared")
                letter_today_count   = 0
                donation_today_count = 0
                ha.set_car_today(0)
                ha.set_inbound_today(0)
                ha.set_outbound_today(0)
                ha.set_letter_today(0)
                ha.set_donation_today(0)

            # ── Schedule tick (every ~5 s) ─────────────────────────────────────
            if now_ts - last_sched_check >= 5.0:
                last_sched_check = now_ts
                now_inside = in_window(cfg)
                if now_inside != sched_inside:
                    # Window boundary crossed — start or stop idle
                    sched_inside = now_inside
                    if now_inside:
                        fpp.start_playlist(pl_idle, repeat=True)
                        last_idle_start_ts = time.time()
                        log.info("[Schedule] Entered active window")
                    else:
                        fpp.stop()
                        log.info("[Schedule] Left active window")
                elif now_inside:
                    # Inside the window — check whether idle is genuinely stopped.
                    # Read full status so we can inspect status_name: if fppd timed
                    # out or is mid-start (status="playing") don't restart.
                    # Also skip the check within 10s of our last start_playlist(idle)
                    # call to avoid false-positive watchdog fires during VLC startup.
                    _cooldown_ok = (now_ts - last_idle_start_ts) > 10.0
                    _raw = fpp._status()
                    if _raw is not None and _cooldown_ok:
                        _cur   = _raw.get("current_playlist", {}).get("playlist", "")
                        _sname = _raw.get("status_name", "")
                        if _cur != pl_idle and _sname not in ("playing", "stopping"):
                            log.info("[Schedule] Idle stopped (status=%s) — resuming", _sname)
                            fpp.start_playlist(pl_idle, repeat=True)
                            last_idle_start_ts = time.time()

            # ── Parked-car timeout ticks ───────────────────────────────────────
            parked_a.tick(now_ts, db)
            parked_b.tick(now_ts, db)

            # ── Command queue ──────────────────────────────────────────────────
            queued = poll_cmd_queue()
            if queued:
                queued_lower = queued.lower()

                if queued_lower == "letter":
                    ins._q.put_nowait("l") if hasattr(ins, "_q") else None  # type: ignore

                elif queued_lower == "donation":
                    ins._q.put_nowait("d") if hasattr(ins, "_q") else None  # type: ignore

                elif queued_lower == "stop":
                    log.info("[CmdQueue] STOP — shutting down")
                    _shutdown.set()
                    break

                elif queued_lower == "cleanup":
                    log.info("[CmdQueue] CLEANUP — removing HA discovery entities")
                    ha.remove_discovery()

                elif queued_lower in ("diag_start_a", "diag_start_b"):
                    side   = "A" if queued_lower.endswith("_a") else "B"
                    reader = ins.get_reader(side) if hasattr(ins, "get_reader") else None
                    if reader:
                        reader.set_diag_mode(True)
                        log.info("[Diag] Entered diagnostic mode for Radar %s", side)
                    else:
                        log.warning("[Diag] No reader for Radar %s", side)

                elif queued_lower == "diag_stop":
                    for side in ("A", "B"):
                        reader = ins.get_reader(side) if hasattr(ins, "get_reader") else None
                        if reader and reader.in_diag_mode:
                            reader.set_diag_mode(False)
                    log.info("[Diag] Exited diagnostic mode")

                elif (queued_lower.startswith("diag_set_a:")
                      or queued_lower.startswith("diag_set_b:")):
                    side       = "A" if queued.lower().startswith("diag_set_a:") else "B"
                    prefix_len = len("diag_set_a:") if side == "A" else len("diag_set_b:")
                    try:
                        from sled_ld2410 import Ld2410Config
                        cfg_dict = json.loads(queued[prefix_len:])
                        new_cfg  = Ld2410Config.from_dict(cfg_dict)
                        reader   = ins.get_reader(side) if hasattr(ins, "get_reader") else None
                        if reader:
                            reader.request_config_write(new_cfg)
                            log.info("[Diag] Config write queued for Radar %s", side)
                        else:
                            log.warning("[Diag] No reader for Radar %s", side)
                    except Exception as exc:
                        log.warning("[Diag] diag_set parse error: %s", exc)

            # ── Poll hardware inputs ───────────────────────────────────────────
            ev = ins.get_event(timeout=0.1)
            if not ev:
                continue

            if ev == "q":
                log.info("Quit via mock input")
                break

            # ── LETTER ────────────────────────────────────────────────────────
            if ev == "l":
                if now_ts - last_letter_time < letter_cd_s:
                    log.debug("[Letter] Ignored duplicate (cooldown)")
                    continue
                last_letter_time = now_ts

                pl = pl_letters[next_letter_idx % len(pl_letters)] if pl_letters else pl_idle
                next_letter_idx += 1
                log.info("[Letter] Playing playlist: %s", pl)

                fpp.stop()
                fpp.play_once(pl, timeout_s=pl_timeout)

                now_iso = dt.datetime.now(dt.timezone.utc).astimezone().isoformat()
                ha.pulse_letter()
                ha.set_last_letter(now_iso)
                ha.event("letter", {"playlist": pl, "ts": now_iso})

                lt = db.increment_counter("letter_total")
                letter_today_count += 1
                ha.set_letter_total(lt)
                ha.set_letter_today(letter_today_count)
                db.log_event("letter", {"playlist": pl})

                if in_window(cfg):
                    fpp.start_playlist(pl_idle, repeat=True)
                    last_idle_start_ts = time.time()
                else:
                    fpp.stop()
                continue

            # ── DONATION ──────────────────────────────────────────────────────
            if ev == "d":
                if now_ts - last_donation_time < donation_cd_s:
                    log.debug("[Donation] Ignored duplicate (cooldown)")
                    continue
                last_donation_time = now_ts

                pl = pl_donations[next_donation_idx % len(pl_donations)] if pl_donations else pl_idle
                next_donation_idx += 1
                log.info("[Donation] Playing playlist: %s", pl)

                fpp.stop()
                fpp.play_once(pl, timeout_s=pl_timeout)

                now_iso = dt.datetime.now(dt.timezone.utc).astimezone().isoformat()
                ha.pulse_donation()
                ha.set_last_donation(now_iso)
                ha.event("donation", {"playlist": pl, "ts": now_iso})

                dt_ = db.increment_counter("donation_total")
                donation_today_count += 1
                ha.set_donation_total(dt_)
                ha.set_donation_today(donation_today_count)
                db.log_event("donation", {"playlist": pl})

                if in_window(cfg):
                    fpp.start_playlist(pl_idle, repeat=True)
                    last_idle_start_ts = time.time()
                else:
                    fpp.stop()
                continue

            # ── RADAR A ───────────────────────────────────────────────────────
            if ev == "a":
                parked_a.on_presence(now_ts)
                tA = now_ts
                if (tB is not None
                        and 0 < (tA - tB) <= seq_window_s
                        and (now_ts - last_car_time) > car_cooldown_s
                        and parked_a.allow_trigger()):
                    last_car_time = now_ts
                    _record_car(db, ha, label_for("BA"), label_tow, "BA")
                continue

            if ev == "a_off":
                parked_a.on_absence(now_ts)
                continue

            # ── RADAR B ───────────────────────────────────────────────────────
            if ev == "b":
                parked_b.on_presence(now_ts)
                tB = now_ts
                if (tA is not None
                        and 0 < (tB - tA) <= seq_window_s
                        and (now_ts - last_car_time) > car_cooldown_s
                        and parked_b.allow_trigger()):
                    last_car_time = now_ts
                    _record_car(db, ha, label_for("AB"), label_tow, "AB")
                continue

            if ev == "b_off":
                parked_b.on_absence(now_ts)
                continue

    finally:
        log.info("SLED daemon shutting down...")
        telemetry.stop()
        fpp.stop()
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
    try:
        main()
    except Exception as exc:
        # Log the full traceback so it appears in SledMailbox.log rather than
        # disappearing silently.  This catches any unhandled crash in main().
        import traceback
        msg = traceback.format_exc()
        try:
            log.critical("UNHANDLED EXCEPTION — daemon exiting:\n%s", msg)
        except Exception:
            sys.stderr.write(f"SLED daemon UNHANDLED EXCEPTION:\n{msg}\n")
        sys.exit(1)
