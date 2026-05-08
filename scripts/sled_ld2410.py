"""
sled_ld2410.py — Minimal LD2410B frame parser extracted from rd03e_scope.py.
Only the three symbols needed by sensors.py are exported:
  extract_report_frames, decode_report_frame, Ld2410Report
"""
from __future__ import annotations
from dataclasses import dataclass
from typing import List, Optional

HDR_RPT = b"\xF4\xF3\xF2\xF1"
FTR_RPT = b"\xF8\xF7\xF6\xF5"


@dataclass
class Ld2410Report:
    move_energy: int = 0
    move_dist_cm: Optional[int] = None
    still_energy: int = 0
    still_dist_cm: Optional[int] = None


def _u16le(b: bytes, i: int) -> int:
    return int(b[i]) | (int(b[i + 1]) << 8)


def _energy8(x: int) -> int:
    return int(x) & 0x7F


def extract_report_frames(buf: bytearray) -> List[bytes]:
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


def decode_report_frame(frame: bytes) -> Optional[Ld2410Report]:
    if not (frame.startswith(HDR_RPT) and frame.endswith(FTR_RPT)):
        return None
    body = frame[len(HDR_RPT):-len(FTR_RPT)]
    if len(body) < 8:
        return None

    rep = Ld2410Report()
    for i in range(len(body)):
        if body[i] != 0xAA:
            continue
        j = i + 1
        if j < len(body) and body[j] in (0x02, 0x03, 0x82, 0x83, 0xD2, 0xDA, 0xCA, 0xEA):
            j += 1
        if j + 6 > len(body):
            continue
        d1 = _u16le(body, j);     e1 = _energy8(body[j + 2])
        d2 = _u16le(body, j + 3); e2 = _energy8(body[j + 5])
        if (0 <= d1 <= 6000) and (0 <= d2 <= 6000) and (0 <= e1 <= 100) and (0 <= e2 <= 100):
            rep.move_dist_cm, rep.move_energy = d1, e1
            rep.still_dist_cm, rep.still_energy = d2, e2
            return rep

    return None
