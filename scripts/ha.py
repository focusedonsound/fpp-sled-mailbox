"""
ha.py — MQTT + Home Assistant Discovery publisher for SLED Santa Mailbox.
Adapted from the standalone sled-mailbox ha.py; reads FPP MQTT settings
as a fallback when use_fpp_settings is enabled in sled.json.
"""
from __future__ import annotations

import json
import logging
import socket
import time
from datetime import datetime, timezone
from typing import Any, Dict, Optional

try:
    from paho.mqtt import client as mqtt
    PAHO_AVAILABLE = True
except ImportError:
    PAHO_AVAILABLE = False  # type: ignore
    mqtt = None  # type: ignore

_LOGGER = logging.getLogger(__name__)

FPP_MQTT_CFG = "/home/fpp/media/config/mqtt_settings.json"


def _iso_now() -> str:
    return datetime.now(timezone.utc).astimezone().isoformat()


def _load_fpp_mqtt_settings() -> Dict[str, Any]:
    """
    Try to read FPP's own MQTT settings from mqtt_settings.json.
    Returns an empty dict if the file doesn't exist or is unparseable.
    """
    try:
        with open(FPP_MQTT_CFG) as f:
            return json.load(f)
    except Exception:
        return {}


class HAMqtt:
    def __init__(self, cfg: Dict[str, Any]) -> None:
        self.cfg = cfg
        mqtt_cfg = cfg.get("mqtt", {})

        self.base: str = mqtt_cfg.get("base", "sled").rstrip("/")
        dev_name: str = mqtt_cfg.get("device_name") or "SLED Santa Mailbox"
        self.dev_id: str = mqtt_cfg.get("device_id") or socket.gethostname()

        self.device = {
            "identifiers": [self.dev_id],
            "name": dev_name,
            "manufacturer": "FocusedOnSound",
            "model": "SLED Santa Mailbox",
            "sw_version": "2.0.0",
        }

        # Resolve host/port/credentials:
        # If use_fpp_settings is true AND no explicit host set, try FPP's config.
        use_fpp = bool(mqtt_cfg.get("use_fpp_settings", True))
        fpp = _load_fpp_mqtt_settings() if use_fpp else {}

        self.host: str = mqtt_cfg.get("host") or fpp.get("server") or fpp.get("host") or ""
        self.port: int = int(mqtt_cfg.get("port") or fpp.get("port") or 1883)
        self.username: Optional[str] = mqtt_cfg.get("username") or fpp.get("username") or None
        self.password: Optional[str] = mqtt_cfg.get("password") or fpp.get("password") or None

        self.connected: bool = False
        self._enabled: bool = bool(self.host)

        if not PAHO_AVAILABLE:
            _LOGGER.warning("paho-mqtt not installed — MQTT disabled")
            self._enabled = False

        if not self._enabled:
            _LOGGER.info("MQTT disabled (no broker host configured)")
            return

        client_id = f"{self.dev_id}-sled"
        self.cli = mqtt.Client(client_id=client_id, clean_session=True)  # type: ignore[union-attr]
        if self.username:
            self.cli.username_pw_set(self.username, self.password)
        self.cli.will_set(f"{self.base}/status", "offline", qos=0, retain=False)
        self.cli.on_connect = self._on_connect
        self.cli.on_disconnect = self._on_disconnect

        _LOGGER.info("Connecting to MQTT broker %s:%s", self.host, self.port)
        try:
            self.cli.connect(self.host, self.port, keepalive=60)
            self.cli.loop_start()
        except Exception as exc:
            _LOGGER.warning("MQTT initial connect failed: %s", exc)

    # ------------------------------------------------------------------
    # Callbacks
    # ------------------------------------------------------------------

    def _on_connect(self, client, userdata, flags, rc: int) -> None:
        if rc == 0:
            self.connected = True
            _LOGGER.info("MQTT connected")
            self.pub(f"{self.base}/status", "online", retain=True)
            self._send_discovery()
        else:
            _LOGGER.warning("MQTT connect failed rc=%s", rc)

    def _on_disconnect(self, client, userdata, rc: int) -> None:
        self.connected = False
        _LOGGER.warning("MQTT disconnected rc=%s", rc)

    # ------------------------------------------------------------------
    # Discovery
    # ------------------------------------------------------------------

    def _disc_topic(self, comp: str, obj_id: str) -> str:
        return f"homeassistant/{comp}/{self.dev_id}_{obj_id}/config"

    def _disc_common(self) -> Dict[str, Any]:
        return {
            "availability": [{"topic": f"{self.base}/status",
                               "payload_available": "online",
                               "payload_not_available": "offline"}],
            "device": self.device,
        }

    def _publish_config(self, comp: str, obj_id: str, name: str,
                        state_topic: str, extra: Optional[Dict[str, Any]] = None) -> None:
        cfg: Dict[str, Any] = {
            "name": name,
            "unique_id": f"{self.dev_id}_{obj_id}",
            "state_topic": state_topic,
        }
        cfg.update(self._disc_common())
        if extra:
            cfg.update(extra)
        self.pub(self._disc_topic(comp, obj_id), json.dumps(cfg), retain=True)

    def _send_discovery(self) -> None:
        if not self.cfg.get("mqtt", {}).get("discovery", True):
            return
        b = self.base

        # Binary sensors
        for obj_id, name, icon, topic in (
            ("letter",   "Letter Detected",   "mdi:email",   f"{b}/state/letter"),
            ("donation", "Donation Detected",  "mdi:gift",    f"{b}/state/donation"),
        ):
            self._publish_config("binary_sensor", obj_id, name, topic,
                                 {"device_class": "occupancy",
                                  "payload_on": "ON", "payload_off": "OFF",
                                  "icon": icon})

        # Timestamp sensors
        for obj_id, name, icon, topic in (
            ("last_letter",    "Last Letter",    "mdi:email-clock",   f"{b}/state/last_letter"),
            ("last_donation",  "Last Donation",  "mdi:gift-outline",  f"{b}/state/last_donation"),
            ("last_car_time",  "Last Car",       "mdi:car-clock",     f"{b}/state/last_car_time"),
        ):
            self._publish_config("sensor", obj_id, name, topic,
                                 {"device_class": "timestamp", "icon": icon})

        # String sensor
        self._publish_config("sensor", "last_dir", "Last Direction",
                             f"{b}/state/last_dir", {"icon": "mdi:swap-horizontal-bold"})

        # Count sensors
        count_sensors = [
            ("car_total",       "Cars (Total)",      "cars",      "total_increasing", "mdi:car-multiple"),
            ("car_today",       "Cars (Today)",      "cars",      "measurement",      "mdi:car"),
            ("inbound_today",   "Inbound (Today)",   "cars",      "measurement",      "mdi:arrow-right-bold"),
            ("outbound_today",  "Outbound (Today)",  "cars",      "measurement",      "mdi:arrow-left-bold"),
            ("letter_total",    "Letters (Total)",   "letters",   "total_increasing", "mdi:email-mark-as-unread"),
            ("donation_total",  "Donations (Total)", "donations", "total_increasing", "mdi:gift-open"),
        ]
        for obj_id, name, unit, state_class, icon in count_sensors:
            self._publish_config("sensor", obj_id, name, f"{b}/state/{obj_id}",
                                 {"unit_of_measurement": unit, "state_class": state_class, "icon": icon})

        # Environment sensors
        self._publish_config("sensor", "temp_c", "Mailbox Temperature",
                             f"{b}/state/temp_c",
                             {"unit_of_measurement": "°C", "device_class": "temperature",
                              "state_class": "measurement", "icon": "mdi:thermometer"})
        self._publish_config("sensor", "humidity", "Mailbox Humidity",
                             f"{b}/state/humidity",
                             {"unit_of_measurement": "%", "device_class": "humidity",
                              "state_class": "measurement", "icon": "mdi:water-percent"})

    # ------------------------------------------------------------------
    # Core publish
    # ------------------------------------------------------------------

    def pub(self, topic: str, payload: str, retain: bool = False) -> None:
        if not self._enabled or not self.connected:
            return
        try:
            self.cli.publish(topic, payload, qos=0, retain=retain)
        except Exception as exc:
            _LOGGER.debug("MQTT publish failed %s: %s", topic, exc)

    # ------------------------------------------------------------------
    # State updaters
    # ------------------------------------------------------------------

    def pulse_letter(self) -> None:
        t = f"{self.base}/state/letter"
        self.pub(t, "ON"); time.sleep(0.2); self.pub(t, "OFF")

    def pulse_donation(self) -> None:
        t = f"{self.base}/state/donation"
        self.pub(t, "ON"); time.sleep(0.2); self.pub(t, "OFF")

    def set_last_letter(self, iso: str) -> None:
        self.pub(f"{self.base}/state/last_letter", iso, retain=True)

    def set_last_donation(self, iso: str) -> None:
        self.pub(f"{self.base}/state/last_donation", iso, retain=True)

    def set_last_car_time(self, iso: str) -> None:
        self.pub(f"{self.base}/state/last_car_time", iso, retain=True)

    def set_last_dir(self, label: str) -> None:
        self.pub(f"{self.base}/state/last_dir", label)

    def set_car_total(self, n: int) -> None:
        self.pub(f"{self.base}/state/car_total", str(n), retain=True)

    def set_car_today(self, n: int) -> None:
        self.pub(f"{self.base}/state/car_today", str(n))

    def set_inbound_today(self, n: int) -> None:
        self.pub(f"{self.base}/state/inbound_today", str(n))

    def set_outbound_today(self, n: int) -> None:
        self.pub(f"{self.base}/state/outbound_today", str(n))

    def set_letter_total(self, n: int) -> None:
        self.pub(f"{self.base}/state/letter_total", str(n), retain=True)

    def set_donation_total(self, n: int) -> None:
        self.pub(f"{self.base}/state/donation_total", str(n), retain=True)

    def set_env(self, temp_c: float, hum: float) -> None:
        if temp_c is not None:
            self.pub(f"{self.base}/state/temp_c", f"{temp_c:.1f}")
        if hum is not None:
            self.pub(f"{self.base}/state/humidity", f"{hum:.1f}")

    def event(self, kind: str, data: Dict[str, Any]) -> None:
        self.pub(f"{self.base}/event/{kind}", json.dumps(data))

    def close(self) -> None:
        if not self._enabled:
            return
        try:
            if self.connected:
                self.pub(f"{self.base}/status", "offline")
                time.sleep(0.1)
        finally:
            try:
                self.connected = False
                self.cli.loop_stop()
                self.cli.disconnect()
            except Exception:
                pass
