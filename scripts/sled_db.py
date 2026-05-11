"""
sled_db.py — SQLite persistence layer for SLED Santa Mailbox.

Tracks:
  - Event log  (events table)     — letter / donation / car events
  - Counters   (counters table)   — total + today counts, today's date
"""
from __future__ import annotations

import json
import sqlite3
import time
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

DB_PATH = "/home/fpp/media/logs/sled.db"

# ---------------------------------------------------------------------------
# Schema
# ---------------------------------------------------------------------------

_DDL = """
CREATE TABLE IF NOT EXISTS events (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    ts   TEXT    NOT NULL,
    kind TEXT    NOT NULL,
    data TEXT    DEFAULT '{}'
);

CREATE TABLE IF NOT EXISTS counters (
    key     TEXT PRIMARY KEY,
    value   INTEGER NOT NULL DEFAULT 0,
    updated TEXT
);
"""

# Counter keys
KEY_CAR_TOTAL       = "car_total"
KEY_CAR_TODAY       = "car_today"
KEY_INBOUND_TODAY   = "inbound_today"
KEY_OUTBOUND_TODAY  = "outbound_today"
KEY_LETTER_TOTAL    = "letter_total"
KEY_DONATION_TOTAL  = "donation_total"
KEY_TODAY_DATE      = "today_date"   # stored as "YYYY-MM-DD"

# Event kind constants
KIND_LETTER   = "letter"
KIND_DONATION = "donation"
KIND_CAR      = "car"
KIND_PARKED   = "parked"    # car has been stationary > parked_timeout_s


def _now_iso() -> str:
    return datetime.now(timezone.utc).astimezone().isoformat()


def _today_str() -> str:
    return datetime.now().strftime("%Y-%m-%d")


class SledDB:
    def __init__(self, path: str = DB_PATH) -> None:
        self.path = path
        self._con: Optional[sqlite3.Connection] = None
        self._open()

    def _open(self) -> None:
        self._con = sqlite3.connect(self.path, check_same_thread=False)
        self._con.row_factory = sqlite3.Row
        self._con.execute("PRAGMA journal_mode=WAL")
        self._con.executescript(_DDL)
        self._con.commit()

    def _cur(self) -> sqlite3.Cursor:
        assert self._con is not None
        return self._con.cursor()

    def close(self) -> None:
        if self._con:
            self._con.close()
            self._con = None

    # ------------------------------------------------------------------
    # Counter helpers
    # ------------------------------------------------------------------

    def get_counter(self, key: str, default: int = 0) -> int:
        cur = self._cur()
        cur.execute("SELECT value FROM counters WHERE key=?", (key,))
        row = cur.fetchone()
        return int(row["value"]) if row else default

    def set_counter(self, key: str, value: int) -> None:
        assert self._con
        self._con.execute(
            "INSERT INTO counters(key,value,updated) VALUES(?,?,?) "
            "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated=excluded.updated",
            (key, value, _now_iso()),
        )
        self._con.commit()

    def increment_counter(self, key: str, by: int = 1) -> int:
        cur_val = self.get_counter(key, 0)
        new_val = cur_val + by
        self.set_counter(key, new_val)
        return new_val

    # ------------------------------------------------------------------
    # Midnight reset
    # ------------------------------------------------------------------

    def check_midnight_reset(self) -> bool:
        """
        If the stored date differs from today, reset all *_today counters.
        Returns True if a reset was performed.
        """
        stored = self.get_counter(KEY_TODAY_DATE, 0)  # type: ignore[arg-type]
        # stored_date via raw query
        assert self._con
        cur = self._cur()
        cur.execute("SELECT value FROM counters WHERE key=?", (KEY_TODAY_DATE,))
        row = cur.fetchone()
        stored_date = str(row["value"]) if row else ""

        today = _today_str()
        if stored_date == today:
            return False

        # Reset today counters
        for key in (KEY_CAR_TODAY, KEY_INBOUND_TODAY, KEY_OUTBOUND_TODAY):
            self.set_counter(key, 0)
        self.set_counter(KEY_TODAY_DATE, today)  # type: ignore[arg-type]
        return True

    # ------------------------------------------------------------------
    # All-in-one counters snapshot
    # ------------------------------------------------------------------

    def snapshot(self) -> Dict[str, Any]:
        """Return current counter values as a dict."""
        keys = [
            KEY_CAR_TOTAL, KEY_CAR_TODAY, KEY_INBOUND_TODAY, KEY_OUTBOUND_TODAY,
            KEY_LETTER_TOTAL, KEY_DONATION_TOTAL,
        ]
        return {k: self.get_counter(k, 0) for k in keys}

    # ------------------------------------------------------------------
    # Event log
    # ------------------------------------------------------------------

    def log_event(self, kind: str, data: Optional[Dict[str, Any]] = None) -> None:
        assert self._con
        self._con.execute(
            "INSERT INTO events(ts, kind, data) VALUES(?,?,?)",
            (_now_iso(), kind, json.dumps(data or {})),
        )
        self._con.commit()

    def recent_events(self, limit: int = 50, kind: Optional[str] = None) -> List[Dict[str, Any]]:
        cur = self._cur()
        if kind:
            cur.execute(
                "SELECT id,ts,kind,data FROM events WHERE kind=? ORDER BY id DESC LIMIT ?",
                (kind, limit),
            )
        else:
            cur.execute(
                "SELECT id,ts,kind,data FROM events ORDER BY id DESC LIMIT ?",
                (limit,),
            )
        rows = cur.fetchall()
        return [
            {"id": r["id"], "ts": r["ts"], "kind": r["kind"],
             "data": json.loads(r["data"] or "{}")}
            for r in rows
        ]

    def get_today_count(self, kind: str) -> int:
        """Count events of a given kind recorded today (Pi local date)."""
        today = _today_str()
        cur = self._cur()
        cur.execute(
            "SELECT COUNT(*) FROM events WHERE kind=? AND substr(ts,1,10)=?",
            (kind, today),
        )
        row = cur.fetchone()
        return int(row[0]) if row else 0

    def today_events(self, kind: Optional[str] = None) -> List[Dict[str, Any]]:
        today = _today_str()
        cur = self._cur()
        if kind:
            cur.execute(
                "SELECT id,ts,kind,data FROM events WHERE kind=? AND ts LIKE ? ORDER BY id DESC",
                (kind, today + "%"),
            )
        else:
            cur.execute(
                "SELECT id,ts,kind,data FROM events WHERE ts LIKE ? ORDER BY id DESC",
                (today + "%",),
            )
        rows = cur.fetchall()
        return [
            {"id": r["id"], "ts": r["ts"], "kind": r["kind"],
             "data": json.loads(r["data"] or "{}")}
            for r in rows
        ]
