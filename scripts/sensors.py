"""
sensors.py — Input layer for SLED Santa Mailbox (FPP plugin edition).

Events emitted via get_event(timeout):
  "l"     = letter slot triggered
  "d"     = donation slot triggered
  "s1"    = special message 1 triggered (optional GPIO, user-configurable)
  "s2"    = special message 2 triggered (optional GPIO, user-configurable)
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
# GPIO strategy (tried in order):
#
#  1. gpiozero + RPiGPIOFactory  — matches original SLED implementation exactly.
#     Forces gpiozero to use RPi.GPIO as its backend instead of the default
#     pigpio backend.  The pigpio backend requires pigpiod which FPP does not
#     run, causing [Errno 22] Invalid argument.
#
#  2. Raw RPi.GPIO  — direct /dev/gpiomem access, no daemon required.
#     Used when gpiozero is not installed.
#
#  Note: FPP must NOT have these GPIO pins configured in gpio.json.
#        If fppd claims a pin first, both backends fail with EINVAL.
#        save.php removes SLED entries from gpio.json to prevent this.
#
# ── lgpio cwd fix ─────────────────────────────────────────────────────────────
# On Raspberry Pi OS Bookworm, RPi.GPIO uses lgpio as its backend.  lgpio
# creates notification FIFOs using cwd-relative paths (.lgd-nfyN).  FPP sets
# the daemon's cwd to /opt/fpp/www which doesn't exist, so lgpio crashes at
# import time with FileNotFoundError.  Switching to /tmp before any GPIO import
# gives lgpio a writable directory for its FIFOs.
os.chdir('/tmp')

try:
    from gpiozero import Button as _GpiozeroButton          # type: ignore
    from gpiozero.pins.rpigpio import RPiGPIOFactory as _RPiGPIOFactory  # type: ignore
    _GPIOZERO_AVAILABLE = True
except ImportError:
    _GpiozeroButton  = None   # type: ignore
    _RPiGPIOFactory  = None   # type: ignore
    _GPIOZERO_AVAILABLE = False

try:
    import RPi.GPIO as _RPIGPIO  # type: ignore
    _RPIGPIO_AVAILABLE = True
except ImportError:
    _RPIGPIO = None  # type: ignore
    _RPIGPIO_AVAILABLE = False

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
    Keys: l=letter  d=donation  1=special1  2=special2  a=radarA  b=radarB  q=quit
    """

    # Map single keypress → event code
    _KEY_MAP = {
        "l": "l",
        "d": "d",
        "1": "s1",
        "2": "s2",
        "a": "a",
        "b": "b",
        "q": "q",
    }

    def __init__(self) -> None:
        self._q: "queue.Queue[str]" = queue.Queue()
        threading.Thread(target=self._reader, daemon=True, name="MockInputs").start()

    def _reader(self) -> None:
        print("[MockInputs] l=letter  d=donation  1=special1  2=special2  "
              "a=A  b=B  q=quit", flush=True)
        while True:
            ch = sys.stdin.read(1)
            if not ch:
                time.sleep(0.05)
                continue
            ch = ch.strip().lower()
            code = self._KEY_MAP.get(ch)
            if code is not None:
                try:
                    self._q.put_nowait(code)
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
        # Flush streaming data frames from the input buffer so the config
        # ACK isn't buried under 100 ms worth of radar frames.
        try:
            ser.reset_input_buffer()
        except Exception:
            pass
        time.sleep(0.05)  # let radar finish any in-flight frame
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
        try: ser.reset_input_buffer()
        except Exception: pass
        time.sleep(0.05)
        ok = ld2410_enter_config(ser)
        if not ok:
            return False
        ok = ld2410_write_config(ser, cfg)
        ld2410_exit_config(ser)
        print(f"[LD2410-{self.sensor_name}] config write {'OK' if ok else 'FAILED'}", flush=True)
        return ok

    def _read_device_config(self, ser) -> Optional[Ld2410Config]:
        try: ser.reset_input_buffer()
        except Exception: pass
        time.sleep(0.05)
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
      - Letter slot     (GPIO pull-up, active-low beam break)
      - Donation slot   (GPIO, optional)
      - Special slots 1 and 2 (GPIO, optional — user-configurable labels)
      - Car radars A and B (LD2410B USB, optional)

    Emits: "l", "d", "s1", "s2", "a", "a_off", "b", "b_off"

    special_pins — list of (bcm_pin, event_code, label) tuples for any extra
    GPIO inputs beyond letter/donation.  Pass None or [] to disable.
    """

    def __init__(
        self,
        letter_pin: int,
        donation_pin: Optional[int],
        cfg: Dict[str, Any],
        special_pins: Optional[list] = None,
    ) -> None:
        self._q: "queue.Queue[str]" = queue.Queue()
        self._threads: list = []
        self._readers: Dict[str, Ld2410UsbReader] = {}

        # Build the full ordered list of GPIO pins to initialise.
        # Each entry is (bcm_pin, event_code, human_label).
        pins_to_init: list = [(letter_pin, "l", "Letter")]
        if donation_pin is not None:
            pins_to_init.append((donation_pin, "d", "Donation"))
        if special_pins:
            pins_to_init.extend(special_pins)

        # ── GPIO sensors ──────────────────────────────────────────────────
        # Try gpiozero+RPiGPIOFactory first (original implementation).
        # Fall back to raw RPi.GPIO if gpiozero is not installed.
        self._gpio_pins: list  = []    # BCM pins registered via RPi.GPIO (for cleanup)
        self._gpiozero_btns: list = [] # gpiozero Button objects (keep alive — GC closes them)
        if _GPIOZERO_AVAILABLE:
            self._setup_gpiozero(pins_to_init)
        elif _RPIGPIO_AVAILABLE:
            self._setup_rpigpio(pins_to_init)
        else:
            print("[GPIOInputs] Neither gpiozero nor RPi.GPIO available — "
                  "GPIO sensors disabled.", flush=True)

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

    # ── GPIO setup helpers ─────────────────────────────────────────────────

    def _setup_rpigpio(self, pins_to_init: list) -> None:
        """Configure GPIO edge detection using RPi.GPIO (no pigpiod needed).

        pins_to_init — list of (bcm_pin, event_code, label) tuples, already
        built by __init__ to include letter, donation, and any special slots.

        Initialization pattern:
          1. Cleanup pin first — removes any dirty state left by a prior run
             that didn't call GPIO.cleanup() (e.g. daemon killed by SIGKILL).
          2. Setup pin with pull-up.
          3. Wait 500 ms for NPN beam-break sensor to stabilize — the IR AGC
             needs a moment to lock onto the beam after power-up; adding edge
             detection before it settles catches spurious falling edges.
          4. Read pin once to drain any latched edge in the kernel driver.
          5. Add edge detection.
        """
        try:
            _RPIGPIO.setmode(_RPIGPIO.BCM)
            _RPIGPIO.setwarnings(False)

            # Step 1 — pre-cleanup (handles stale state from unclean shutdown)
            for pin, _code, _label in pins_to_init:
                try:
                    _RPIGPIO.remove_event_detect(pin)
                except Exception:
                    pass
                try:
                    _RPIGPIO.cleanup(pin)
                except Exception:
                    pass

            # Step 2 — configure all pins as inputs with pull-up
            for pin, _code, _label in pins_to_init:
                _RPIGPIO.setup(pin, _RPIGPIO.IN, pull_up_down=_RPIGPIO.PUD_UP)

            # Step 3 — wait for sensor output to stabilize
            print("[GPIOInputs] Waiting 500 ms for beam sensors to stabilize...", flush=True)
            time.sleep(0.5)

            # Step 4+5 — drain pending edge state, then arm edge detection
            for pin, code, label in pins_to_init:
                initial = _RPIGPIO.input(pin)  # drain any latched edge
                print(f"[GPIOInputs] {label} sensor GPIO{pin} initial state: "
                      f"{'HIGH (beam OK)' if initial else 'LOW (beam broken or sensor unpowered)'}",
                      flush=True)
                _RPIGPIO.add_event_detect(
                    pin, _RPIGPIO.FALLING,
                    callback=lambda ch, c=code: self._emit(c),
                    bouncetime=50,
                )
                self._gpio_pins.append(pin)
                print(f"[GPIOInputs] {label} sensor on GPIO{pin} armed (RPi.GPIO)", flush=True)

        except Exception as exc:
            print(f"[GPIOInputs] WARNING: RPi.GPIO init failed ({exc}) — "
                  "GPIO sensors disabled.", flush=True)
            # Clean up any partial setup
            for pin in self._gpio_pins:
                try:
                    _RPIGPIO.remove_event_detect(pin)
                    _RPIGPIO.cleanup(pin)
                except Exception:
                    pass
            self._gpio_pins = []

    def _setup_gpiozero(self, pins_to_init: list) -> None:
        """Configure GPIO using gpiozero + RPiGPIOFactory (original implementation).

        pins_to_init — list of (bcm_pin, event_code, label) tuples, already
        built by __init__ to include letter, donation, and any special slots.

        Forces gpiozero to use RPi.GPIO as its pin backend instead of pigpio,
        so no pigpiod daemon is required and FPP's GPIO ownership is not fought.
        Matches the original: Button(pin, pull_up=True, bounce_time=0.05).
        """
        try:
            factory = _RPiGPIOFactory()

            # Wait for NPN beam-break sensors to stabilize after power-up
            print("[GPIOInputs] Waiting 500 ms for beam sensors to stabilize...", flush=True)
            time.sleep(0.5)

            for pin, code, label in pins_to_init:
                btn = _GpiozeroButton(pin, pull_up=True, bounce_time=0.05, pin_factory=factory)
                btn.when_pressed = lambda c=code: self._emit(c)
                self._gpiozero_btns.append(btn)   # must stay referenced or GC closes pin
                state = "HIGH (beam OK)" if not btn.is_pressed else "LOW (beam broken or sensor unpowered)"
                print(f"[GPIOInputs] {label} sensor on GPIO{pin} armed — "
                      f"initial state: {state} (gpiozero+RPiGPIO)", flush=True)

        except Exception as exc:
            print(f"[GPIOInputs] WARNING: gpiozero GPIO init failed ({exc}) — "
                  "falling back to RPi.GPIO.", flush=True)
            self._gpiozero_btns = []
            # Attempt raw RPi.GPIO fallback
            if _RPIGPIO_AVAILABLE:
                self._setup_rpigpio(pins_to_init)
            else:
                print("[GPIOInputs] RPi.GPIO also unavailable — GPIO sensors disabled.", flush=True)

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
        # Close gpiozero buttons (releases pins back to the OS)
        for btn in self._gpiozero_btns:
            try:
                btn.close()
            except Exception:
                pass
        self._gpiozero_btns = []
        # Clean up RPi.GPIO edge detection (used when gpiozero unavailable)
        if _RPIGPIO_AVAILABLE and self._gpio_pins:
            for pin in self._gpio_pins:
                try:
                    _RPIGPIO.remove_event_detect(pin)
                    _RPIGPIO.cleanup(pin)
                except Exception:
                    pass
            self._gpio_pins = []
