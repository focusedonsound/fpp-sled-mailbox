#!/bin/bash
# fpp_install.sh — SLED Santa Mailbox plugin installer
# Called by FPP when the plugin is installed or updated.

PLUGIN_DIR="$(dirname "$0")"

# Log to /tmp first (always writable), then also try the media logs dir
LOGFILE="/tmp/SledMailbox_install.log"
MEDIA_LOG="/home/fpp/media/logs/SledMailbox_install.log"

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    echo "$msg" | tee -a "$LOGFILE"
    echo "$msg" >> "$MEDIA_LOG" 2>/dev/null || true
}

log "=== SLED Santa Mailbox install started (user=$(whoami), uid=$(id -u)) ==="

# ── Create media directories ─────────────────────────────────────
# Do this FIRST so the media log path is available.
mkdir -p /home/fpp/media/logs
mkdir -p /home/fpp/media/config
mkdir -p /home/fpp/media/plugins/fpp-sled-mailbox/videos

# Now that the dir exists, copy /tmp log into media log
cat "$LOGFILE" >> "$MEDIA_LOG" 2>/dev/null || true

# ── System packages ──────────────────────────────────────────────
log "Installing system packages via apt-get..."
if apt-get install -y --no-install-recommends \
    mpv \
    python3-serial \
    python3-paho-mqtt \
    python3-gpiozero \
    >> "$LOGFILE" 2>&1; then
    log "apt-get packages installed OK"
else
    log "WARN: apt-get failed or partial — will try pip3 fallbacks"
fi

# ── pip3 fallbacks for critical packages ────────────────────────
# pyserial is required for radar; install via pip3 if apt failed.
if ! python3 -c "import serial" 2>/dev/null; then
    log "pyserial not found — installing via pip3..."
    pip3 install --quiet --break-system-packages pyserial >> "$LOGFILE" 2>&1 \
        || pip3 install --quiet pyserial >> "$LOGFILE" 2>&1 \
        || log "WARN: pip3 pyserial install also failed — radar will be disabled"
else
    log "pyserial already available"
fi

if ! python3 -c "import paho.mqtt.client" 2>/dev/null; then
    log "paho-mqtt not found — installing via pip3..."
    pip3 install --quiet --break-system-packages paho-mqtt >> "$LOGFILE" 2>&1 \
        || pip3 install --quiet paho-mqtt >> "$LOGFILE" 2>&1 \
        || log "WARN: pip3 paho-mqtt install failed — MQTT will be disabled"
else
    log "paho-mqtt already available"
fi

# ── Optional: Adafruit DHT support ────────────────────────────────
if command -v pip3 >/dev/null 2>&1; then
    log "Installing optional Adafruit DHT library..."
    pip3 install --quiet --break-system-packages adafruit-circuitpython-dht >> "$LOGFILE" 2>&1 \
        || pip3 install --quiet adafruit-circuitpython-dht >> "$LOGFILE" 2>&1 \
        || log "WARN: adafruit-dht install failed — DHT11 sensor disabled (non-fatal)"
fi

# ── Make scripts executable ──────────────────────────────────────
log "Setting script permissions..."
chmod +x "${PLUGIN_DIR}/scripts/"*.py 2>/dev/null || true
chmod +x "${PLUGIN_DIR}/scripts/"*.sh 2>/dev/null || true
chmod +x "${PLUGIN_DIR}/commands/"*.sh 2>/dev/null || true
chmod +x "${PLUGIN_DIR}/callbacks.sh"  2>/dev/null || true
chmod +x "${PLUGIN_DIR}/fpp_start.sh"  2>/dev/null || true
chmod +x "${PLUGIN_DIR}/fpp_stop.sh"   2>/dev/null || true

# ── Write default config if none exists ─────────────────────────
CONFIG="/home/fpp/media/config/sled.json"
if [[ ! -f "$CONFIG" ]]; then
    log "Writing default config to $CONFIG"
    cp "${PLUGIN_DIR}/config/sled.json.example" "$CONFIG" 2>/dev/null || \
    cat > "$CONFIG" <<'JSONEOF'
{
  "enabled": true,
  "paths": {
    "videos": "/home/fpp/media/plugins/fpp-sled-mailbox/videos"
  },
  "schedule": {
    "start": "16:00",
    "end": "22:00"
  },
  "video": {
    "idle": "idle.mp4",
    "letter_clips": [],
    "donation_clips": [],
    "play_timeout_s": 65
  },
  "pins": {
    "letter": 17,
    "donation": null
  },
  "car": {
    "sequence_window_s": 0.8,
    "cooldown_s": 1.5
  },
  "letter": {
    "cooldown_s": 3.0
  },
  "donation": {
    "cooldown_s": 5.0
  },
  "direction": {
    "toward_reference": "AB",
    "label_toward": "Inbound",
    "label_away": "Outbound"
  },
  "ld2410": {
    "enabled": false,
    "min_energy": 20,
    "A": {"port": "/dev/ttyUSB0", "min_energy": 20},
    "B": {"port": "/dev/ttyUSB1", "min_energy": 20}
  },
  "dht11": {
    "enabled": false,
    "pin": 4,
    "interval_s": 60
  },
  "mqtt": {
    "use_fpp_settings": true,
    "host": "",
    "port": 1883,
    "username": "",
    "password": "",
    "base": "sled",
    "discovery": true,
    "device_name": "SLED Santa Mailbox",
    "device_id": "sled_mailbox"
  },
  "debug": {
    "use_mock_inputs": false
  }
}
JSONEOF
fi

# ── Chart.js (bundled in repo under js/) ────────────────────────
# chart.umd.min.js is committed to git — no download needed.

# ── Systemd service ─────────────────────────────────────────────
# Install and enable the SLED daemon as a systemd service so it
# starts automatically at boot regardless of FPP version.
SERVICE_SRC="${PLUGIN_DIR}/sled-mailbox.service"
SERVICE_DST="/etc/systemd/system/sled-mailbox.service"

if [[ -f "$SERVICE_SRC" ]]; then
    log "Installing systemd service..."
    cp "$SERVICE_SRC" "$SERVICE_DST" && log "Service file copied OK" || log "WARN: could not copy service file"
    systemctl daemon-reload >> "$LOGFILE" 2>&1 && log "systemctl daemon-reload OK" || log "WARN: daemon-reload failed"
    systemctl enable sled-mailbox >> "$LOGFILE" 2>&1 && log "sled-mailbox enabled OK" || log "WARN: enable failed"
    # Start (or restart if already running) the daemon immediately
    if systemctl restart sled-mailbox >> "$LOGFILE" 2>&1; then
        log "sled-mailbox service started OK"
    else
        log "WARN: could not start sled-mailbox service (non-fatal — daemon can be started manually)"
    fi
else
    log "WARN: sled-mailbox.service not found in plugin dir — skipping systemd install"
fi

log "=== SLED Santa Mailbox install complete ==="
exit 0
