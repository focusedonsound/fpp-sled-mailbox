"""
sled_ld2410.py — HLK-LD2410B radar driver for SLED Santa Mailbox.

Exports (used by sensors.py / sled_daemon.py):
  Basic frame parsing (backward-compat):
    extract_report_frames, decode_report_frame, Ld2410Report

  Engineering frame parsing:
    extract_all_frames, decode_eng_frame, Ld2410EngReport

  Device configuration (requires an open serial.Serial):
    Ld2410Config
    ld2410_enter_config(ser)       -> bool
    ld2410_exit_config(ser)        -> bool
    ld2410_enable_eng(ser)         -> bool
    ld2410_disable_eng(ser)        -> bool
    ld2410_read_config(ser)        -> Optional[Ld2410Config]
    ld2410_write_config(ser, cfg)  -> bool

Protocol reference: HLK-LD2410B serial protocol v1.05
  Baud: 256000, 8N1
  Config frame:  FD FC FB FA [len u16le] [cmd u16le] [params] 04 03 02 01
  Data frame:    F4 F3 F2 F1 [len u16le] [type u8] [payload] F8 F7 F6 F5
"""
from __future__ import annotations

import struct
import time
from dataclasses import dataclass, field
from typing import List, Optional, Tuple

# ── Frame delimiters ───────────────────────────────────────────────────────────
HDR_RPT = b"\xF4\xF3\xF2\xF1"
FTR_RPT = b"\xF8\xF7\xF6\xF5"
HDR_CFG = b"\xFD\xFC\xFB\xFA"
FTR_CFG = b"\x04\x03\x02\x01"

# ── Target status codes ────────────────────────────────────────────────────────
STATUS_NONE     = 0x00
STATUS_MOVING   = 0x01
STATUS_STATIC   = 0x02
STATUS_BOTH     = 0x03

NUM_GATES = 9          # gates 0..8, each ~0.75 m  →  0..6.75 m total
GATE_WIDTH_M = 0.75    # metres per gate

# ── Default sensitivities (vehicle-optimised) ──────────────────────────────────
# Gates 0-1 (0-1.5 m) get high thresholds to suppress mailbox/mounting noise.
# Gates 2-6 (1.5-5.25 m) are the driveway detection zone.
# Gates 7-8 stay moderate for early warning.
DEFAULT_MOVE_SENS   = [50, 50, 40, 40, 30, 30, 20, 20, 20]
DEFAULT_STATIC_SENS = [0,  0,  30, 30, 20, 20, 15, 15, 15]


# =============================================================================
# Data classes
# =============================================================================

@dataclass
class Ld2410Report:
    """Basic presence report (normal operating mode)."""
    target_status:  int = STATUS_NONE
    move_dist_cm:   Optional[int] = None
    move_energy:    int = 0
    still_dist_cm:  Optional[int] = None
    still_energy:   int = 0
    detect_dist_cm: Optional[int] = None

    @property
    def present(self) -> bool:
        return self.target_status != STATUS_NONE

    @property
    def status_str(self) -> str:
        return {STATUS_NONE: "none", STATUS_MOVING: "moving",
                STATUS_STATIC: "static", STATUS_BOTH: "both"}.get(
                    self.target_status, "unknown")


@dataclass
class Ld2410EngReport:
    """Engineering mode report — includes per-gate energy arrays."""
    target_status:      int = STATUS_NONE
    move_dist_cm:       int = 0
    move_energy:        int = 0
    static_dist_cm:     int = 0
    static_energy:      int = 0
    detect_dist_cm:     int = 0
    max_move_gate:      int = 8
    max_static_gate:    int = 8
    gate_move_energy:   List[int] = field(default_factory=lambda: [0] * NUM_GATES)
    gate_static_energy: List[int] = field(default_factory=lambda: [0] * NUM_GATES)

    @property
    def present(self) -> bool:
        return self.target_status != STATUS_NONE

    @property
    def status_str(self) -> str:
        return {STATUS_NONE: "none", STATUS_MOVING: "moving",
                STATUS_STATIC: "static", STATUS_BOTH: "both"}.get(
                    self.target_status, "unknown")


@dataclass
class Ld2410Config:
    """Radar gate configuration (read from / written to device)."""
    max_move_gate:      int = 8
    max_static_gate:    int = 8
    move_sensitivity:   List[int] = field(default_factory=lambda: list(DEFAULT_MOVE_SENS))
    static_sensitivity: List[int] = field(default_factory=lambda: list(DEFAULT_STATIC_SENS))
    timeout_s:          int = 5   # no-presence timeout in seconds

    def to_dict(self) -> dict:
        return {
            "max_move_gate":      self.max_move_gate,
            "max_static_gate":    self.max_static_gate,
            "move_sensitivity":   list(self.move_sensitivity),
            "static_sensitivity": list(self.static_sensitivity),
            "timeout_s":          self.timeout_s,
        }

    @classmethod
    def from_dict(cls, d: dict) -> "Ld2410Config":
        cfg = cls()
        cfg.max_move_gate      = int(d.get("max_move_gate", 8))
        cfg.max_static_gate    = int(d.get("max_static_gate", 8))
        cfg.move_sensitivity   = list(d.get("move_sensitivity",   DEFAULT_MOVE_SENS))[:NUM_GATES]
        cfg.static_sensitivity = list(d.get("static_sensitivity", DEFAULT_STATIC_SENS))[:NUM_GATES]
        cfg.timeout_s          = int(d.get("timeout_s", 5))
        return cfg

    @classmethod
    def vehicle_preset(cls) -> "Ld2410Config":
        """Optimised for car-sized moving targets; suppresses near-field noise."""
        return cls(
            max_move_gate=6, max_static_gate=6,
            move_sensitivity  =[50, 50, 40, 40, 30, 25, 20, 20, 20],
            static_sensitivity=[0,  0,  30, 30, 20, 15, 10, 10, 10],
            timeout_s=5,
        )

    @classmethod
    def person_preset(cls) -> "Ld2410Config":
        """Sensitive to smaller / slower targets (pedestrians at mailbox)."""
        return cls(
            max_move_gate=8, max_static_gate=8,
            move_sensitivity  =[30, 30, 25, 25, 20, 20, 15, 15, 15],
            static_sensitivity=[20, 20, 20, 20, 15, 15, 10, 10, 10],
            timeout_s=10,
        )


# =============================================================================
# Helpers
# =============================================================================

def _u16le(b: bytes, i: int) -> int:
    return int(b[i]) | (int(b[i + 1]) << 8)


def _u32le(b: bytes, i: int) -> int:
    return struct.unpack_from("<I", b, i)[0]


def _pack_cfg_frame(cmd: int, params: bytes = b"") -> bytes:
    """Build a configuration command frame."""
    payload = struct.pack("<H", cmd) + params
    return HDR_CFG + struct.pack("<H", len(payload)) + payload + FTR_CFG


def _read_cfg_response(ser, timeout: float = 0.5) -> Optional[bytes]:
    """
    Read one config-mode response frame from the serial port.
    Returns the inner payload bytes (between header and footer), or None.
    """
    deadline = time.time() + timeout
    buf = bytearray()
    while time.time() < deadline:
        chunk = ser.read(ser.in_waiting or 1)
        if chunk:
            buf.extend(chunk)
        start = buf.find(HDR_CFG)
        if start < 0:
            if len(buf) > 256:
                buf.clear()
            continue
        if start > 0:
            del buf[:start]
        end = buf.find(FTR_CFG, 4)
        if end < 0:
            continue
        end += len(FTR_CFG)
        frame = bytes(buf[:end])
        del buf[:end]
        body = frame[4:-4]
        if len(body) < 4:
            continue
        # body: [len u16le] [cmd+1 u16le] [status u16le] [data...]
        data_len = _u16le(body, 0)
        return body[2:2 + data_len]
    # Timeout — log first 32 bytes of what was received for debugging
    if buf:
        print(f"[LD2410] cfg_response timeout, buf={buf[:32].hex()}", flush=True)
    return None


# =============================================================================
# Config-mode commands
# =============================================================================

def ld2410_enter_config(ser) -> bool:
    """
    Switch radar into configuration mode.
    Must be called before any set/get config commands.
    Returns True on ACK.
    """
    # Send exit-config first to ensure a known state (idempotent if not in cfg mode).
    ser.write(_pack_cfg_frame(0x00FE))
    ser.flush()
    time.sleep(0.05)
    try:
        ser.reset_input_buffer()
    except Exception:
        pass

    # Now request config mode (up to 3 attempts).
    for attempt in range(3):
        ser.write(_pack_cfg_frame(0x00FF, b"\x01\x00"))
        ser.flush()
        rsp = _read_cfg_response(ser, timeout=1.5)
        if rsp is not None and len(rsp) >= 4 and rsp[2:4] == b"\x00\x00":
            return True
        time.sleep(0.2)
        try:
            ser.reset_input_buffer()
        except Exception:
            pass
    return False


def ld2410_exit_config(ser) -> bool:
    """Exit configuration mode and return to data-output mode."""
    frame = _pack_cfg_frame(0x00FE)
    ser.write(frame)
    rsp = _read_cfg_response(ser)
    return rsp is not None and len(rsp) >= 4 and rsp[2:4] == b"\x00\x00"


def ld2410_enable_eng(ser) -> bool:
    """
    Enable engineering mode (streams per-gate energy in every data frame).
    Radar must be in config mode first.
    """
    ser.write(_pack_cfg_frame(0x0062))
    rsp = _read_cfg_response(ser)
    return rsp is not None and len(rsp) >= 4 and rsp[2:4] == b"\x00\x00"


def ld2410_disable_eng(ser) -> bool:
    """Disable engineering mode. Radar must be in config mode first."""
    ser.write(_pack_cfg_frame(0x0063))
    rsp = _read_cfg_response(ser)
    return rsp is not None and len(rsp) >= 4 and rsp[2:4] == b"\x00\x00"


def ld2410_read_config(ser) -> Optional[Ld2410Config]:
    """
    Read all gate sensitivities, max gates, and no-presence timeout.
    Radar must be in config mode.
    Returns Ld2410Config or None on failure.
    """
    ser.write(_pack_cfg_frame(0x0061))
    rsp = _read_cfg_response(ser, timeout=1.0)
    if rsp is None or len(rsp) < 4:
        return None
    # rsp layout after cmd echo + status:
    # [cmd_echo 2] [status 2] [head 1=0x01] [max_move_gate 1] [max_static_gate 1]
    # [move_sens 0..8 × 1] [static_sens 0..8 × 1] [timeout 2]
    data = rsp[4:]  # skip cmd echo + status
    if len(data) < 1 + 1 + 1 + NUM_GATES + NUM_GATES + 2:
        return None
    i = 0
    # head byte (0x01 according to protocol)
    i += 1
    max_move   = data[i];   i += 1
    max_static = data[i];   i += 1
    move_sens   = list(data[i:i + NUM_GATES]); i += NUM_GATES
    static_sens = list(data[i:i + NUM_GATES]); i += NUM_GATES
    timeout_s   = _u16le(data, i)
    return Ld2410Config(
        max_move_gate      = max_move,
        max_static_gate    = max_static,
        move_sensitivity   = move_sens,
        static_sensitivity = static_sens,
        timeout_s          = timeout_s,
    )


def ld2410_write_config(ser, cfg: Ld2410Config) -> bool:
    """
    Write max-gate range + timeout, then per-gate sensitivities.
    Radar must be in config mode.
    Sends: set_range_gate (0x0060) then set_gate_sensitivity (0x0064) × 9.
    Returns True only if all commands ACK.
    """
    # 1 — set max gates + timeout
    params = struct.pack("<III",
        cfg.max_move_gate,
        cfg.max_static_gate,
        cfg.timeout_s,
    )
    ser.write(_pack_cfg_frame(0x0060, params))
    rsp = _read_cfg_response(ser)
    if rsp is None or len(rsp) < 4 or rsp[2:4] != b"\x00\x00":
        return False

    # 2 — per-gate sensitivities
    for gate in range(NUM_GATES):
        move_s   = int(cfg.move_sensitivity[gate])   if gate < len(cfg.move_sensitivity)   else 0
        static_s = int(cfg.static_sensitivity[gate]) if gate < len(cfg.static_sensitivity) else 0
        params = struct.pack("<III", gate, move_s, static_s)
        ser.write(_pack_cfg_frame(0x0064, params))
        rsp = _read_cfg_response(ser)
        if rsp is None or len(rsp) < 4 or rsp[2:4] != b"\x00\x00":
            return False

    return True


# =============================================================================
# Basic data frame parsing  (backward-compatible with original implementation)
# =============================================================================

def extract_report_frames(buf: bytearray) -> List[bytes]:
    """Extract and remove all complete data frames from buf. Returns raw frame bytes."""
    frames: List[bytes] = []
    while True:
        start = buf.find(HDR_RPT)
        if start < 0:
            if len(buf) > 4096:
                del buf[:-64]
            break
        if start > 0:
            del buf[:start]
        end = buf.find(FTR_RPT, 4)
        if end < 0:
            break
        end += len(FTR_RPT)
        frames.append(bytes(buf[:end]))
        del buf[:end]
    return frames


# Alias for clarity
extract_all_frames = extract_report_frames


def decode_report_frame(frame: bytes) -> Optional[Ld2410Report]:
    """
    Parse a basic (non-engineering) data frame.
    Returns Ld2410Report or None.
    """
    if not (frame.startswith(HDR_RPT) and frame.endswith(FTR_RPT)):
        return None
    body = frame[4:-4]
    if len(body) < 6:
        return None

    # Skip length (2 bytes) and type byte
    offset = 3
    # Find 0xAA head marker
    while offset < len(body):
        if body[offset] == 0xAA:
            break
        offset += 1
    else:
        return None

    j = offset + 1
    # Skip target status byte
    if j >= len(body):
        return None
    status = body[j]; j += 1

    if j + 9 > len(body):
        return None

    move_dist   = _u16le(body, j);     move_energy  = body[j + 2]; j += 3
    still_dist  = _u16le(body, j);     still_energy = body[j + 2]; j += 3
    detect_dist = _u16le(body, j);                                  j += 2

    if not (0 <= move_dist <= 9000 and 0 <= still_dist <= 9000
            and 0 <= move_energy <= 100 and 0 <= still_energy <= 100):
        return None

    return Ld2410Report(
        target_status  = status,
        move_dist_cm   = move_dist   if move_dist  > 0 else None,
        move_energy    = move_energy,
        still_dist_cm  = still_dist  if still_dist > 0 else None,
        still_energy   = still_energy,
        detect_dist_cm = detect_dist if detect_dist > 0 else None,
    )


def decode_eng_frame(frame: bytes) -> Optional[Ld2410EngReport]:
    """
    Parse an engineering-mode data frame.
    Engineering frames are longer than basic ones — they carry per-gate
    energy arrays for both moving and stationary targets.
    Returns Ld2410EngReport or None if parsing fails.
    """
    if not (frame.startswith(HDR_RPT) and frame.endswith(FTR_RPT)):
        return None
    body = frame[4:-4]

    # body[0:2] = data length, body[2] = report type (0x01 = engineering)
    if len(body) < 3:
        return None
    # Accept engineering (0x01) frames; reject basic (0x02)
    report_type = body[2]

    # Find 0xAA head marker
    offset = 3
    while offset < len(body):
        if body[offset] == 0xAA:
            break
        offset += 1
    else:
        return None

    j = offset + 1
    if j + 2 + 9 + 9 > len(body):
        return None

    status = body[j]; j += 1

    if j + 8 > len(body):
        return None
    move_dist    = _u16le(body, j);   move_energy   = body[j + 2]; j += 3
    static_dist  = _u16le(body, j);   static_energy = body[j + 2]; j += 3
    detect_dist  = _u16le(body, j);                                 j += 2

    # Engineering-specific: max gates + per-gate arrays
    if report_type != 0x01 or j + 2 + NUM_GATES + NUM_GATES > len(body):
        # Frame doesn't contain engineering data — return None so
        # the caller can fall back to decode_report_frame
        return None

    max_move_gate   = body[j];     j += 1
    max_static_gate = body[j];     j += 1
    gate_move   = list(body[j:j + NUM_GATES]); j += NUM_GATES
    gate_static = list(body[j:j + NUM_GATES])

    return Ld2410EngReport(
        target_status      = status,
        move_dist_cm       = move_dist,
        move_energy        = move_energy,
        static_dist_cm     = static_dist,
        static_energy      = static_energy,
        detect_dist_cm     = detect_dist,
        max_move_gate      = max_move_gate,
        max_static_gate    = max_static_gate,
        gate_move_energy   = gate_move,
        gate_static_energy = gate_static,
    )
