"""
sensors.py — Input layer for SLED Santa Mailbox (FPP plugin edition).

Events emitted via get_event(timeout):
  "l"     = letter slot triggered
  "d"     = donation slot triggered
  "a"     = radar A presence rising edge  (car arrived / presence started)
  "a_off" = radar A presence falling edge (car left  / presence ended)
  "b"     = radar B presence rising edge
  "b_off" = radar B presence falling edge
  "q"     = quit (mock only)
"""
from __future__ import annotations

import json
import os
import sys
import time
import threading
import queue
from typing import Any, Dict, Optional

# ── Optional hardware imports ─────────────────────────────────────────────────
try:
    from gpiozero import Button
except ImportError:
    Button = None  # type: ignore

try:
    import serial  # pyserial
except ImportError:
    serial = None  # type: ignore

# LD2410 frame parser — bundled with this plugin
try:
    from sled_ld2410 import (
        extract_all_frames, decode_report_frame, decode_eng_frame,
        ld2410_enter_config, ld2410_exit_config,
        ld2410_enable_eng, ld2410_disable_eng,
        ld2410_read_config, ld2410_write_config,
        Ld2410Config,
    )
    LD2410_AVAILABLE = True
except ImportError:
    LD2410_AVAILABLE = False  # type: ignore


# =============================================================================
# Mock inputs (keyboard test harness)
# =============================================================================

class MockInputs:
    """
    Stdin-based mock for testing without hardware.
    Keys: l=letter  d=donation  a=radarA  b=radarB  q=quit
    """
    def __init__(self) -> None:
        self._q: "queue.Queue[str]" = queue.Queue()
        threading.Thread(target=self._reader, daemon=True, name="MockInputs").start()

    def _reader(self) -> None:
        print("[MockInputs] l=letter  d=donation  a=A  b=B  q=quit", flush=True)
        while True:
            ch = sys.stdin.read(1)
            if not ch:
                time.sleep(0.05)
                continue
            ch = ch.strip().lower()
            if ch in ("l", "d", "a", "b", "q"):
                try:
                    self._q.put_nowait(ch)
                except queue.Full:
                    pass

    def get_event(self, timeout: float = 0.1) -> Optional[str]:
        try:
            return self._q.get(timeout=timeout)
        except queue.Empty:
            return None


# =============================================================================
# LD2410 USB reader thread
# =============================================================================

class Ld2410UsbReader(threading.Thread):
    """
    Background thread that owns a single LD2410B serial connection.

    Normal mode:
      Emits event_code   ("a" or "b") on presence rising edge.
      Emits event_code + "_off"       on presence falling edge.

    Diagnostic mode (activated via set_diag_mode(True)):
      Switches radar to engineering-mode streaming.
      Writes a JSON snapshot to diag_output_path every ~200 ms.
      Car-detection events are suppressed while in diagnostic mode.

    Parked-car tracking:
      presence_since  — timestamp when current presence began (None = absent)
      Daemon reads this to implement parked-car timeout logic.
    """

    def __init__(
        self,
        port: str,
        name: str,
        event_code: str,
        out_q: "queue.Queue[str]",
        min_energy: int = 20,
        cooldown_s: float = 0.3,
        diag_output_path: str = "",
    ) -> None:
        super().__init__(daemon=True, name=f"LD2410-{name}")
        self.port             = port
        self.sensor_name      = name
        self.event_code       = event_code          # "a" or "b"
        self.out_q            = out_q
        self.min_energy       = int(min_energy)
        self.cooldown_s       = float(cooldown_s)
        self.diag_output_path = diag_output_path    # written in diag mode

        # Threading controls
        self._stop_ev   = threading.Event()
        self._lock      = threading.Lock()

        # Diagnostic mode state (guarded by _lock)
        self._diag_mode    = False
        self._diag_pending = False   # set True to request mode change
        self._diag_target  = False   # desired diag state after pending change
        self._new_cfg: Optional[Ld2410Config] = None  # pending config write

        # Presence tracking (written by thread, read by daemon — lock not
        # needed because CPython GIL makes float/None assignment atomic)
        self.presence_since: Optional[float] = None   # None = no presence
        self._last_event_ts: float = 0.0

    # ── Public API called by daemon ────────────────────────────────────────

    def stop(self) -> None:
        self._stop_ev.set()

    def set_diag_mode(self, enabled: bool) -> None:
        """Request enter/exit diagnostic mode. Applied on next loop iteration."""
        with self._lock:
            self._diag_pending = True
            self._diag_target  = enabled

    def request_config_write(self, cfg: Ld2410Config) -> None:
        """Queue a config write to be applied on next loop iteration."""
        with self._lock:
            self._new_cfg = cfg

    @property
    def in_diag_mode(self) -> bool:
        with self._lock:
            return self._diag_mode

    # ── Internal helpers ───────────────────────────────────────────────────

    def _emit(self, code: str) -> None:
        try:
            self.out_q.put_nowait(code)
        except queue.Full:
            pass

    def _apply_diag_change(self, ser, target: bool) -> bool:
        """Switch radar to/from engineering mode. Returns True on success."""
        ok = ld2410_enter_config(ser)
        if not ok:
            print(f"[LD2410-{self.sensor_name}] enter_config failed", flush=True)
            return False
        if target:
            ok = ld2410_enable_eng(ser)
        else:
            ok = ld2410_disable_eng(ser)
        ld2410_exit_config(ser)
        if ok:
            print(f"[LD2410-{self.sensor_name}] engineering mode {'ON' if target else 'OFF'}", flush=True)
        return ok

    def _apply_config_write(self, ser, cfg: Ld2410Config) -> bool:
        """Write config to device flash. Returns True on success."""
        ok = ld2410_enter_config(ser)
        if not ok:
            return False
        ok = ld2410_write_config(ser, cfg)
        ld2410_exit_config(ser)
        print(f"[LD2410-{self.sensor_name}] config write {'OK' if ok else 'FAILED'}", flush=True)
        return ok

    def _read_device_config(self, ser) -> Optional[Ld2410Config]:
        ok = ld2410_enter_config(ser)
        if not ok:
            return None
        cfg = ld2410_read_config(ser)
        ld2410_exit_config(ser)
        return cfg

    def _write_diag_snapshot(
        self,
        report,   # Ld2410EngReport or Ld2410Report
        device_cfg: Optional[Ld2410Config],
        ser,
    ) -> None:
        """Write the latest engineering snapshot to the JSON output file."""
        if not self.diag_output_path:
            return
        try:
            from sled_ld2410 import Ld2410EngReport, STATUS_NONE
            if isinstance(report, Ld2410EngReport):
                gme = report.gate_move_energy
                gse = report.gate_static_energy
            else:
                # Basic frame — fill with zeros
                gme = [0] * 9
                gse = [0] * 9

            thresholds = {}
            if device_cfg:
                thresholds = {
                    "moving":     list(device_cfg.move_sensitivity),
                    "stationary": list(device_cfg.static_sensitivity),
                }

            snapshot = {
                "ts":       time.time(),
                "status":   report.status_str if hasattr(report, "status_str") else "unknown",
                "distance": round(
                    (report.detect_dist_cm if isinstance(report, Ld2410EngReport)
                     else (report.move_dist_cm or 0)) / 100.0, 2
                ),
                "gates": {
                    "moving":     gme,
                    "stationary": gse,
                },
                "thresholds": thresholds,
                "max_gate":   (device_cfg.max_move_gate if device_cfg else 8),
                "timeout":    (device_cfg.timeout_s     if device_cfg else 5),
            }
            tmp = self.diag_output_path + ".tmp"
            with open(tmp, "w") as f:
                json.dump(snapshot, f)
            os.replace(tmp, self.diag_output_path)
        except Exception as exc:
            print(f"[LD2410-{self.sensor_name}] diag write error: {exc}", flush=True)

    # ── Main thread loop ───────────────────────────────────────────────────

    def run(self) -> None:
        if serial is None:
            print(f"[LD2410-{self.sensor_name}] pyserial not installed — radar disabled.", flush=True)
            return
        if not LD2410_AVAILABLE:
            print(f"[LD2410-{self.sensor_name}] sled_ld2410 not available — radar disabled.", flush=True)
            return

        try:
            ser = serial.Serial(self.port, baudrate=256000, timeout=0.05)
            print(f"[LD2410-{self.sensor_name}] opened {self.port} @ 256000", flush=True)
        except Exception as exc:
            print(f"[LD2410-{self.sensor_name}] cannot open {self.port}: {exc}", flush=True)
            return

        buf             = bytearray()
        last_present    = False
        last_diag_write = 0.0
        device_cfg: Optional[Ld2410Config] = None
        diag_cfg_loaded = False

        try:
            while not self._stop_ev.is_set():

                # ── Handle pending mode change ─────────────────────────────
                with self._lock:
                    do_diag_change = self._diag_pending
                    diag_target    = self._diag_target
                    pending_cfg    = self._new_cfg
                    if do_diag_change:
                        self._diag_pending = False
                    if pending_cfg is not None:
                        self._new_cfg = None

                if do_diag_change and diag_target != self._diag_mode:
                    if self._apply_diag_change(ser, diag_target):
                        with self._lock:
                            self._diag_mode = diag_target
                        diag_cfg_loaded = False
                        buf.clear()

                if pending_cfg is not None:
                    self._apply_config_write(ser, pending_cfg)
                    device_cfg   = pending_cfg
                    diag_cfg_loaded = True

                # ── Load device config once when entering diag mode ────────
                if self._diag_mode and not diag_cfg_loaded:
                    device_cfg      = self._read_device_config(ser)
                    diag_cfg_loaded = True

                # ── Read serial data ───────────────────────────────────────
                try:
                    chunk = ser.read(ser.in_waiting or 64)
                except Exception as exc:
                    print(f"[LD2410-{self.sensor_name}] read error: {exc}", flush=True)
                    time.sleep(0.5)
                    continue

                if chunk:
                    buf.extend(chunk)
                else:
                    time.sleep(0.01)
                    continue

                for frame in extract_all_frames(buf):
                    now = time.time()

                    # ── Diagnostic mode: parse engineering frame ───────────
                    if self._diag_mode:
                        report = decode_eng_frame(frame)
                        if report is None:
                            report = decode_report_frame(frame)
                        if report is not None and (now - last_diag_write) >= 0.2:
                            last_diag_write = now
                            self._write_diag_snapshot(report, device_cfg, ser)
                        continue   # skip normal event logic in diag mode

                    # ── Normal mode: basic frame ───────────────────────────
                    rep = decode_report_frame(frame)
                    if rep is None:
                        continue

                    energy  = max(rep.move_energy, rep.still_energy)
                    present = energy >= self.min_energy

                    if present and not last_present:
                        # Rising edge — presence started
                        self.presence_since = now
                        if (now - self._last_event_ts) > self.cooldown_s:
                            self._last_event_ts = now
                            self._emit(self.event_code)

                    elif not present and last_present:
                        # Falling edge — presence ended
                        self.presence_since = None
                        self._emit(self.event_code + "_off")

                    last_present = present

        finally:
            # Restore normal mode if we were in diag mode
            if self._diag_mode:
                try:
                    self._apply_diag_change(ser, False)
                except Exception:
                    pass
            try:
                if ser.is_open:
                    ser.close()
            except Exception:
                pass
            print(f"[LD2410-{self.sensor_name}] stopped.", flush=True)


# =============================================================================
# Real GPIO + LD2410 inputs
# =============================================================================

class GPIOInputs:
    """
    Combined input handler:
      - Letter slot   (GPIO pull-up, active-low beam break)
      - Donation slot (GPIO, optional)
      - Car radars A and B (LD2410B USB, optional)

    Emits: "l", "d", "a", "a_off", "b", "b_off"
    """

    def __init__(self, letter_pin: int, donation_pin: Optional[int], cfg: Dict[str, Any]) -> None:
        self._q: "queue.Queue[str]" = queue.Queue()
        self._threads: list = []
        self._readers: Dict[str, Ld2410UsbReader] = {}

        # ── GPIO buttons ──────────────────────────────────────────────────
        self._letter_btn   = None
        self._donation_btn = None
        if Button is None:
            print("[GPIOInputs] gpiozero not available — GPIO sensors disabled.", flush=True)
        else:
            try:
                self._letter_btn = Button(letter_pin, pull_up=True, bounce_time=0.05)
                self._letter_btn.when_pressed = lambda: self._emit("l")
                print(f"[GPIOInputs] Letter sensor on GPIO{letter_pin} OK", flush=True)

                if donation_pin is not None:
                    self._donation_btn = Button(donation_pin, pull_up=True, bounce_time=0.05)
                    self._donation_btn.when_pressed = lambda: self._emit("d")
                    print(f"[GPIOInputs] Donation sensor on GPIO{donation_pin} OK", flush=True)
            except Exception as exc:
                print(f"[GPIOInputs] WARNING: GPIO init failed ({exc}) — "
                      "letter/donation sensors disabled. Radar will still work.",
                      flush=True)
                self._letter_btn   = None
                self._donation_btn = None

        # ── LD2410 radar readers ───────────────────────────────────────────
        ld_cfg = cfg.get("ld2410", {}) or {}
        if ld_cfg.get("enabled", False):
            log_dir = "/home/fpp/media/logs"
            for side, event_code in (("A", "a"), ("B", "b")):
                side_cfg = ld_cfg.get(side, {}) or {}
                port = side_cfg.get("port", "")
                if not port:
                    continue
                min_e      = int(side_cfg.get("min_energy", 20))
                diag_path  = os.path.join(log_dir, f"sled_radar_{side.lower()}.json")
                reader = Ld2410UsbReader(
                    port             = port,
                    name             = side,
                    event_code       = event_code,
                    out_q            = self._q,
                    min_energy       = min_e,
                    cooldown_s       = 0.3,
                    diag_output_path = diag_path,
                )
                reader.start()
                self._threads.append(reader)
                self._readers[side] = reader
                print(f"[GPIOInputs] LD2410 side {side} on {port} (min_energy={min_e})", flush=True)
        else:
            print("[GPIOInputs] LD2410 disabled in config.", flush=True)

    def _emit(self, code: str) -> None:
        try:
            self._q.put_nowait(code)
        except queue.Full:
            pass

    def get_event(self, timeout: float = 0.1) -> Optional[str]:
        try:
            return self._q.get(timeout=timeout)
        except queue.Empty:
            return None

    def get_reader(self, side: str) -> Optional[Ld2410UsbReader]:
        """Return the Ld2410UsbReader for side 'A' or 'B', or None."""
        return self._readers.get(side.upper())

    def stop(self) -> None:
        for t in self._threads:
            if hasattr(t, "stop"):
                t.stop()
