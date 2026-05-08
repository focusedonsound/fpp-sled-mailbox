"""
sensors.py — Input layer for SLED Santa Mailbox (FPP plugin edition).

Provides get_event(timeout) -> one of:
  "l" = letter slot triggered
  "d" = donation slot triggered
  "a" = radar A rising edge
  "b" = radar B rising edge
  "q" = quit (mock only)
"""
from __future__ import annotations

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
    from sled_ld2410 import extract_report_frames, decode_report_frame  # type: ignore
except ImportError:
    extract_report_frames = None  # type: ignore
    decode_report_frame = None  # type: ignore


# =============================================================================
# Mock inputs (keyboard test harness)
# =============================================================================

class MockInputs:
    """
    Stdin-based mock for testing without hardware.
    l=letter, d=donation, a=radarA, b=radarB, q=quit
    """
    def __init__(self) -> None:
        self._q: "queue.Queue[str]" = queue.Queue()
        t = threading.Thread(target=self._reader, daemon=True)
        t.start()

    def _reader(self) -> None:
        print("[MockInputs] Keyboard controls: l=letter, d=donation, a=A, b=B, q=quit", flush=True)
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
    Reads HLK-LD2410B radar over USB serial.
    Emits a single event on *moving* energy rising edge.
    """
    def __init__(
        self,
        port: str,
        name: str,
        event_code: str,
        out_q: "queue.Queue[str]",
        min_energy: int = 20,
        cooldown_s: float = 0.3,
    ) -> None:
        super().__init__(daemon=True, name=f"LD2410-{name}")
        self.port = port
        self.sensor_name = name
        self.event_code = event_code
        self.out_q = out_q
        self.min_energy = int(min_energy)
        self.cooldown_s = float(cooldown_s)
        self._stop_ev = threading.Event()

    def stop(self) -> None:
        self._stop_ev.set()

    def run(self) -> None:
        if serial is None:
            print(f"[LD2410-{self.sensor_name}] pyserial not installed — radar disabled.", flush=True)
            return
        if extract_report_frames is None or decode_report_frame is None:
            print(f"[LD2410-{self.sensor_name}] sled_ld2410 not available — radar disabled.", flush=True)
            return

        try:
            ser = serial.Serial(self.port, baudrate=256000, timeout=0.05)
            print(f"[LD2410-{self.sensor_name}] Opened {self.port} @ 256000", flush=True)
        except Exception as exc:
            print(f"[LD2410-{self.sensor_name}] Failed to open {self.port}: {exc}", flush=True)
            return

        buf = bytearray()
        last_present = False
        last_event_ts = 0.0

        try:
            while not self._stop_ev.is_set():
                try:
                    chunk = ser.read(ser.in_waiting or 64)
                    if chunk:
                        buf.extend(chunk)
                        for frame in extract_report_frames(buf):
                            rep = decode_report_frame(frame)
                            if rep is None:
                                continue
                            me = int(getattr(rep, "move_energy", 0))
                            present = me >= self.min_energy
                            now = time.time()
                            if present and not last_present and (now - last_event_ts) > self.cooldown_s:
                                try:
                                    self.out_q.put_nowait(self.event_code)
                                except queue.Full:
                                    pass
                                last_event_ts = now
                            last_present = present
                    else:
                        time.sleep(0.01)
                except Exception as exc:
                    print(f"[LD2410-{self.sensor_name}] Error: {exc}", flush=True)
                    time.sleep(0.5)
        finally:
            try:
                if ser.is_open:
                    ser.close()
            except Exception:
                pass
            print(f"[LD2410-{self.sensor_name}] Stopped.", flush=True)


# =============================================================================
# Real GPIO + LD2410 inputs
# =============================================================================

class GPIOInputs:
    """
    Combined input handler:
      - Letter slot  (GPIO pull-up, active-low beam break)
      - Donation slot (GPIO, optional)
      - Car radars A/B (LD2410B USB, optional)
    """
    def __init__(self, letter_pin: int, donation_pin: Optional[int], cfg: Dict[str, Any]) -> None:
        self._q: "queue.Queue[str]" = queue.Queue()
        self._threads: list = []

        if Button is None:
            print("[GPIOInputs] gpiozero not available — GPIO sensors disabled.", flush=True)
            self._letter_btn = None
            self._donation_btn = None
        else:
            self._letter_btn = Button(letter_pin, pull_up=True, bounce_time=0.05)
            self._letter_btn.when_pressed = lambda: self._emit("l")

            if donation_pin is not None:
                self._donation_btn = Button(donation_pin, pull_up=True, bounce_time=0.05)
                self._donation_btn.when_pressed = lambda: self._emit("d")
            else:
                self._donation_btn = None

        ld_cfg = cfg.get("ld2410", {}) or {}
        if ld_cfg.get("enabled", False):
            common_min = int(ld_cfg.get("min_energy", 20))
            for side, event_code in (("A", "a"), ("B", "b")):
                side_cfg = ld_cfg.get(side, {}) or {}
                port = side_cfg.get("port")
                if not port:
                    continue
                min_e = int(side_cfg.get("min_energy", common_min))
                reader = Ld2410UsbReader(
                    port=port,
                    name=side,
                    event_code=event_code,
                    out_q=self._q,
                    min_energy=min_e,
                    cooldown_s=0.3,
                )
                reader.start()
                self._threads.append(reader)
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

    def stop(self) -> None:
        for t in self._threads:
            if hasattr(t, "stop"):
                t.stop()
