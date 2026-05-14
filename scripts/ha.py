"""
ha.py — MQTT + Home Assistant Discovery publisher for SLED Santa Mailbox.

Topic layout (base defaults to "sled"):
  {base}/status                   ← LWT: "online" / "offline"
  {base}/status/{metric}          ← retained state values
  {base}/event/{kind}             ← transient event JSON payloads

HA discovery:
  homeassistant/{component}/{dev_id}_{obj_id}/config   ← retained config
  (cleared by remove_discovery() for clean plugin uninstall)

FPP MQTT settings are read from /home/fpp/media/settings (flat key=value).
SLED can override any field via sled.json → mqtt section.
"""
from __future__ import annotations

import json
import logging
import os
import socket
import time
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional, Tuple

try:
    from paho.mqtt import client as mqtt
    PAHO_AVAILABLE = True
except ImportError:
    PAHO_AVAILABLE = False   # type: ignore
    mqtt = None              # type: ignore

_LOGGER = logging.getLogger(__name__)

# FPP stores settings as a flat "KEY=value" file
_FPP_SETTINGS_FILE = "/home/fpp/media/settings"
# Some FPP builds also write a JSON version; try it as fallback
_FPP_MQTT_JSON = "/home/fpp/media/config/mqtt_settings.json"


# ---------------------------------------------------------------------------
# FPP settings helpers
# ---------------------------------------------------------------------------

def load_fpp_mqtt_settings() -> Dict[str, Any]:
    """
    Read MQTT credentials from FPP's own settings.
    Tries the flat settings file first, then the JSON fallback.
    Returns a dict with keys: host, port, username, password.
    """
    # 1 — Flat file  (newer / all current FPP versions)
    if os.path.exists(_FPP_SETTINGS_FILE):
        kv: Dict[str, str] = {}
        try:
            with open(_FPP_SETTINGS_FILE) as f:
                for line in f:
                    line = line.strip()
                    if "=" in line and not line.startswith("#"):
                        key, _, val = line.partition("=")
                        kv[key.strip()] = val.strip().strip("\"'")
        except Exception as exc:
            _LOGGER.debug("FPP settings read error: %s", exc)

        if kv.get("MQTTServer"):
            return {
                "host":     kv.get("MQTTServer", ""),
                "port":     int(kv.get("MQTTPort", 1883) or 1883),
                "username": kv.get("MQTTUsername", ""),
                "password": kv.get("MQTTPassword", ""),
            }

    # 2 — JSON fallback
    if os.path.exists(_FPP_MQTT_JSON):
        try:
            with open(_FPP_MQTT_JSON) as f:
                data = json.load(f)
            if isinstance(data, dict):
                return data
        except Exception as exc:
            _LOGGER.debug("FPP mqtt JSON read error: %s", exc)

    return {}


# ---------------------------------------------------------------------------
# HAMqtt
# ---------------------------------------------------------------------------

class HAMqtt:
    def __init__(self, cfg: Dict[str, Any]) -> None:
        self.cfg = cfg
        mqtt_cfg  = cfg.get("mqtt", {})

        self.base: str    = mqtt_cfg.get("base", "sled").rstrip("/")
        dev_name: str     = mqtt_cfg.get("device_name") or "SLED Santa Mailbox"
        self.dev_id: str  = mqtt_cfg.get("device_id")  or socket.gethostname()

        self.device: Dict[str, Any] = {
            "identifiers":  [self.dev_id],
            "name":         dev_name,
            "manufacturer": "FocusedOnSound",
            "model":        "SLED Santa Mailbox FPP Plugin",
            "sw_version":   "2.1.0",
        }

        # Resolve credentials: sled.json fields take priority over FPP settings
        use_fpp = bool(mqtt_cfg.get("use_fpp_settings", True))
        fpp     = load_fpp_mqtt_settings() if use_fpp else {}

        self.host: str          = mqtt_cfg.get("host")     or fpp.get("host", "")
        self.port: int          = int(mqtt_cfg.get("port") or fpp.get("port", 1883) or 1883)
        self.username: Optional[str] = mqtt_cfg.get("username") or fpp.get("username") or None
        self.password: Optional[str] = mqtt_cfg.get("password") or fpp.get("password") or None

        self.connected: bool = False
        self._enabled: bool  = bool(self.host)

        if not PAHO_AVAILABLE:
            _LOGGER.warning("paho-mqtt not installed — MQTT disabled")
            self._enabled = False

        if not self._enabled:
            _LOGGER.info("MQTT disabled (no broker host configured)")
            return

        client_id   = f"{self.dev_id}-sled"
        self.cli    = mqtt.Client(client_id=client_id, clean_session=True)  # type: ignore[union-attr]
        if self.username:
            self.cli.username_pw_set(self.username, self.password)

        # LWT — broker publishes "offline" if we disconnect uncleanly
        self.cli.will_set(f"{self.base}/status", "offline", qos=1, retain=True)

        self.cli.on_connect    = self._on_connect
        self.cli.on_disconnect = self._on_disconnect

        _LOGGER.info("Connecting to MQTT broker %s:%s", self.host, self.port)
        try:
            self.cli.connect(self.host, self.port, keepalive=60)
            self.cli.loop_start()
        except Exception as exc:
            _LOGGER.warning("MQTT initial connect failed: %s", exc)

    # ------------------------------------------------------------------
    # Internal callbacks
    # ------------------------------------------------------------------

    def _on_connect(self, client, userdata, flags, rc: int) -> None:
        if rc == 0:
            self.connected = True
            _LOGGER.info("MQTT connected to %s:%s", self.host, self.port)
            self.pub(f"{self.base}/status", "online", retain=True)
            self._send_discovery()
        else:
            _LOGGER.warning("MQTT connect failed rc=%s", rc)

    def _on_disconnect(self, client, userdata, rc: int) -> None:
        self.connected = False
        _LOGGER.warning("MQTT disconnected rc=%s", rc)

    # ------------------------------------------------------------------
    # HA Discovery helpers
    # ------------------------------------------------------------------

    def _disc_topic(self, component: str, obj_id: str) -> str:
        return f"homeassistant/{component}/{self.dev_id}_{obj_id}/config"

    def _disc_common(self) -> Dict[str, Any]:
        return {
            "availability": [
                {
                    "topic":                f"{self.base}/status",
                    "payload_available":    "online",
                    "payload_not_available":"offline",
                }
            ],
            "device": self.device,
        }

    def _publish_config(self, component: str, obj_id: str, name: str,
                        state_topic: str, extra: Optional[Dict[str, Any]] = None) -> None:
        payload: Dict[str, Any] = {
            "name":       name,
            "unique_id":  f"{self.dev_id}_{obj_id}",
            "state_topic": state_topic,
        }
        payload.update(self._disc_common())
        if extra:
            payload.update(extra)
        self.pub(self._disc_topic(component, obj_id), json.dumps(payload), retain=True)

    def _all_discovery_entities(self) -> List[Tuple[str, str]]:
        """Return (component, obj_id) for every registered HA entity."""
        return [
            ("binary_sensor", "letter"),
            ("binary_sensor", "donation"),
            ("sensor", "last_letter"),
            ("sensor", "last_donation"),
            ("sensor", "last_car_time"),
            ("sensor", "last_dir"),
            ("sensor", "car_total"),
            ("sensor", "car_today"),
            ("sensor", "inbound_today"),
            ("sensor", "outbound_today"),
            ("sensor", "letter_total"),
            ("sensor", "donation_total"),
            ("sensor", "letter_today"),
            ("sensor", "donation_today"),
            ("sensor", "temp_c"),
            ("sensor", "humidity"),
        ]

    def _send_discovery(self) -> None:
        if not self.cfg.get("mqtt", {}).get("discovery", True):
            return
        b = self.base

        # ── Pulse binary sensors (letter / donation triggers) ─────────────
        for obj_id, name, icon in (
            ("letter",   "Letter Detected",  "mdi:email"),
            ("donation", "Donation Detected", "mdi:gift"),
        ):
            self._publish_config(
                "binary_sensor", obj_id, name,
                f"{b}/status/{obj_id}",
                {"device_class": "occupancy",
                 "payload_on": "ON", "payload_off": "OFF",
                 "icon": icon},
            )

        # ── Timestamp sensors ─────────────────────────────────────────────
        for obj_id, name, icon in (
            ("last_letter",   "Last Letter",   "mdi:email-clock"),
            ("last_donation", "Last Donation", "mdi:gift-outline"),
            ("last_car_time", "Last Car",      "mdi:car-clock"),
        ):
            self._publish_config("sensor", obj_id, name, f"{b}/status/{obj_id}",
                                 {"device_class": "timestamp", "icon": icon})

        # ── String sensor ─────────────────────────────────────────────────
        self._publish_config("sensor", "last_dir", "Last Direction",
                             f"{b}/status/last_dir",
                             {"icon": "mdi:swap-horizontal-bold"})

        # ── Counter sensors ───────────────────────────────────────────────
        counters = [
            # obj_id          name                    unit         state_class          icon
            ("car_total",       "Cars (Total)",       "cars",     "total_increasing", "mdi:car-multiple"),
            ("car_today",       "Cars Today",         "cars",     "measurement",      "mdi:car"),
            ("inbound_today",   "Inbound Today",      "cars",     "measurement",      "mdi:arrow-right-bold"),
            ("outbound_today",  "Outbound Today",     "cars",     "measurement",      "mdi:arrow-left-bold"),
            ("letter_total",    "Letters (Total)",    "letters",  "total_increasing", "mdi:email-mark-as-unread"),
            ("donation_total",  "Donations (Total)",  "donations","total_increasing", "mdi:gift-open"),
            ("letter_today",    "Letters Today",      "letters",  "measurement",      "mdi:email"),
            ("donation_today",  "Donations Today",    "donations","measurement",      "mdi:gift"),
        ]
        for obj_id, name, unit, state_class, icon in counters:
            self._publish_config(
                "sensor", obj_id, name, f"{b}/status/{obj_id}",
                {"unit_of_measurement": unit, "state_class": state_class, "icon": icon},
            )

        # ── Environment sensors (DHT11, optional) ─────────────────────────
        self._publish_config("sensor", "temp_c", "Mailbox Temperature",
                             f"{b}/status/temp_c",
                             {"unit_of_measurement": "°C", "device_class": "temperature",
                              "state_class": "measurement", "icon": "mdi:thermometer"})
        self._publish_config("sensor", "humidity", "Mailbox Humidity",
                             f"{b}/status/humidity",
                             {"unit_of_measurement": "%", "device_class": "humidity",
                              "state_class": "measurement", "icon": "mdi:water-percent"})

        _LOGGER.info("MQTT HA discovery published (%d entities)", len(self._all_discovery_entities()))

    # ------------------------------------------------------------------
    # Clean-uninstall helper
    # ------------------------------------------------------------------

    def remove_discovery(self) -> None:
        """
        Remove all HA discovery entities by publishing empty retained messages.
        Call this before uninstalling the plugin so HA doesn't keep stale entities.
        Also accessible via the MQTT settings page "Remove HA Entities" button.
        """
        if not self._enabled:
            _LOGGER.info("MQTT not enabled — nothing to remove")
            return
        count = 0
        for component, obj_id in self._all_discovery_entities():
            topic = self._disc_topic(component, obj_id)
            try:
                self.cli.publish(topic, "", qos=1, retain=True)
                count += 1
            except Exception as exc:
                _LOGGER.debug("remove_discovery publish error %s: %s", topic, exc)
        # Brief pause so the broker receives the empties before we disconnect
        time.sleep(0.3)
        _LOGGER.info("MQTT HA discovery removed (%d topics cleared)", count)

    # ------------------------------------------------------------------
    # Core publish
    # ------------------------------------------------------------------

    def pub(self, topic: str, payload: str, retain: bool = False) -> None:
        if not self._enabled or not self.connected:
            return
        try:
            self.cli.publish(topic, payload, qos=0, retain=retain)
        except Exception as exc:
            _LOGGER.debug("MQTT publish error %s: %s", topic, exc)

    # ------------------------------------------------------------------
    # State updaters
    # ------------------------------------------------------------------

    # ── Letter / Donation ─────────────────────────────────────────────
    def pulse_letter(self) -> None:
        t = f"{self.base}/status/letter"
        self.pub(t, "ON"); time.sleep(0.2); self.pub(t, "OFF")

    def pulse_donation(self) -> None:
        t = f"{self.base}/status/donation"
        self.pub(t, "ON"); time.sleep(0.2); self.pub(t, "OFF")

    def set_last_letter(self, iso: str) -> None:
        self.pub(f"{self.base}/status/last_letter", iso, retain=True)

    def set_last_donation(self, iso: str) -> None:
        self.pub(f"{self.base}/status/last_donation", iso, retain=True)

    def set_letter_total(self, n: int) -> None:
        self.pub(f"{self.base}/status/letter_total", str(n), retain=True)

    def set_donation_total(self, n: int) -> None:
        self.pub(f"{self.base}/status/donation_total", str(n), retain=True)

    def set_letter_today(self, n: int) -> None:
        self.pub(f"{self.base}/status/letter_today", str(n))

    def set_donation_today(self, n: int) -> None:
        self.pub(f"{self.base}/status/donation_today", str(n))

    # ── Car counter ───────────────────────────────────────────────────
    def set_last_car_time(self, iso: str) -> None:
        self.pub(f"{self.base}/status/last_car_time", iso, retain=True)

    def set_last_dir(self, label: str) -> None:
        self.pub(f"{self.base}/status/last_dir", label)

    def set_car_total(self, n: int) -> None:
        self.pub(f"{self.base}/status/car_total", str(n), retain=True)

    def set_car_today(self, n: int) -> None:
        self.pub(f"{self.base}/status/car_today", str(n))

    def set_inbound_today(self, n: int) -> None:
        self.pub(f"{self.base}/status/inbound_today", str(n))

    def set_outbound_today(self, n: int) -> None:
        self.pub(f"{self.base}/status/outbound_today", str(n))

    # ── Environment ───────────────────────────────────────────────────
    def set_env(self, temp_c: float, hum: float) -> None:
        if temp_c is not None:
            self.pub(f"{self.base}/status/temp_c", f"{temp_c:.1f}")
        if hum is not None:
            self.pub(f"{self.base}/status/humidity", f"{hum:.1f}")

    # ── Generic event payload ─────────────────────────────────────────
    def event(self, kind: str, data: Dict[str, Any]) -> None:
        self.pub(f"{self.base}/event/{kind}", json.dumps(data))

    # ------------------------------------------------------------------
    # Lifecycle
    # ------------------------------------------------------------------

    def close(self) -> None:
        if not self._enabled:
            return
        try:
            if self.connected:
                self.pub(f"{self.base}/status", "offline", retain=True)
                time.sleep(0.15)
        finally:
            try:
                self.connected = False
                self.cli.loop_stop()
                self.cli.disconnect()
            except Exception:
                pass
