"""
sled_telemetry.py — Anonymous usage telemetry for SLED Santa Mailbox.

Sends a lightweight ping to the SLED telemetry backend once on startup
(after a 30-second delay) and then every 24 hours.

Completely non-blocking — all errors are silently swallowed so telemetry
can never affect normal plugin operation.

Opt-in is controlled by cfg["telemetry"]["opt_in"]  (default: True).
install_id is a stable UUID stored in sled.json under cfg["telemetry"]["install_id"].
"""
from __future__ import annotations

import json
import threading
import urllib.request
import urllib.error
from typing import Any, Dict, Optional

TELEMETRY_URL      = "https://sled-telemetry.nscilingo.workers.dev"
TELEMETRY_INTERVAL = 86400   # 24 hours in seconds
PLUGIN_NAME        = "fpp-sled-mailbox"
PLUGIN_VERSION     = "1.0.0"
TELEMETRY_VERSION  = 1

# Delay before first ping after daemon startup (seconds)
# Gives the system time to settle before hitting the network
STARTUP_DELAY = 30


# ── System info helpers ────────────────────────────────────────────────────────

def _get_pi_model() -> str:
    """Read Pi model from /proc/cpuinfo (e.g. 'Raspberry Pi 3 Model B Plus Rev 1.3')."""
    try:
        with open("/proc/cpuinfo") as f:
            for line in f:
                if line.startswith("Model"):
                    return line.split(":", 1)[1].strip()
    except Exception:
        pass
    return ""


def _get_fpp_version() -> str:
    """Read FPP version from /home/fpp/media/settings."""
    try:
        with open("/home/fpp/media/settings") as f:
            for line in f:
                line = line.strip()
                if line.startswith("fppVersion"):
                    return line.split("=", 1)[1].strip()
    except Exception:
        pass
    return ""


# ── HTTP helper ────────────────────────────────────────────────────────────────

def _send_ping(payload: Dict[str, Any]) -> bool:
    """
    Fire-and-forget POST to the telemetry endpoint.
    Returns True on success, False on any error.
    """
    try:
        data = json.dumps(payload).encode("utf-8")
        req = urllib.request.Request(
            TELEMETRY_URL,
            data=data,
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=10) as resp:
            return resp.status == 200
    except Exception:
        return False


# ── Main class ─────────────────────────────────────────────────────────────────

class SledTelemetry:
    """
    Manages periodic telemetry pings in a background daemon thread.

    Usage:
        tel = SledTelemetry(cfg, db)
        tel.start()   # non-blocking — starts background thread if opt-in
        tel.stop()    # signal shutdown (thread exits cleanly)
    """

    def __init__(self, cfg: Dict[str, Any], db: Any) -> None:
        self._cfg     = cfg
        self._db      = db
        self._stop_ev = threading.Event()
        self._thread: Optional[threading.Thread] = None

    # ── Public API ─────────────────────────────────────────────────────────

    def start(self) -> None:
        """Start the background thread — does nothing if opt-in is disabled."""
        if not self._is_opt_in() or not self._install_id():
            return
        self._thread = threading.Thread(
            target=self._run, daemon=True, name="SledTelemetry"
        )
        self._thread.start()

    def stop(self) -> None:
        """Signal the background thread to stop."""
        self._stop_ev.set()

    # ── Internals ──────────────────────────────────────────────────────────

    def _is_opt_in(self) -> bool:
        return bool(self._cfg.get("telemetry", {}).get("opt_in", True))

    def _install_id(self) -> str:
        return str(self._cfg.get("telemetry", {}).get("install_id", "")).strip()

    def _build_payload(self) -> Dict[str, Any]:
        cfg = self._cfg

        # Event counts from SQLite
        event_counts: Dict[str, int] = {}
        try:
            event_counts = {
                "cars":      self._db.get_counter("car_total",      0),
                "letters":   self._db.get_counter("letter_total",   0),
                "donations": self._db.get_counter("donation_total", 0),
            }
        except Exception:
            pass

        # Feature flags — booleans only, no values
        features = {
            "radar": bool(cfg.get("ld2410", {}).get("enabled", False)),
            "mqtt":  bool(cfg.get("mqtt",   {}).get("enabled", False) or
                         cfg.get("mqtt",   {}).get("use_fpp_settings", False)),
            "dht11": bool(cfg.get("dht11",  {}).get("enabled", False)),
        }

        return {
            "install_id":        self._install_id(),
            "plugin":            PLUGIN_NAME,
            "plugin_version":    PLUGIN_VERSION,
            "fpp_version":       _get_fpp_version(),
            "pi_model":          _get_pi_model(),
            "features":          features,
            "event_counts":      event_counts,
            "uptime_days":       0,
            "telemetry_version": TELEMETRY_VERSION,
        }

    def _run(self) -> None:
        # Wait before first ping so startup noise settles
        if self._stop_ev.wait(STARTUP_DELAY):
            return   # stopped during startup delay

        _send_ping(self._build_payload())

        # Then ping every 24 hours until stopped
        while not self._stop_ev.wait(TELEMETRY_INTERVAL):
            _send_ping(self._build_payload())
