#!/bin/bash
# fpp_install.sh — SLED Santa Mailbox plugin installer
# Called by FPP when the plugin is installed or updated.

PLUGIN_DIR="$(dirname "$0")"
LOGFILE="/home/fpp/media/logs/SledMailbox_install.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOGFILE"; }

log "=== SLED Santa Mailbox install started ==="

# ── System packages ──────────────────────────────────────────────
log "Installing system packages..."
apt-get install -y --no-install-recommends \
    mpv \
    python3-serial \
    python3-paho-mqtt \
    python3-gpiozero \
    >> "$LOGFILE" 2>&1 || log "WARN: some apt packages may have failed (non-fatal)"

# ── Optional: Adafruit DHT support ────────────────────────────────
# Only install if pip3 is available; not strictly required.
# Uses --break-system-packages for Ubuntu 24.04+ (PEP 668).
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
chmod +x "${PLUGIN_DIR}/callbacks.sh" 2>/dev/null || true

# ── Create media directories ─────────────────────────────────────
log "Creating media directories..."
mkdir -p /home/fpp/media/logs
mkdir -p /home/fpp/media/config
mkdir -p /home/fpp/media/plugins/fpp-sled-mailbox/videos

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

# ── Chart.js (local copy for analytics dashboard) ───────────────
# Served via FPP's file= mechanism so the dashboard works offline.
CHARTJS_DIR="${PLUGIN_DIR}/js"
CHARTJS_FILE="${CHARTJS_DIR}/chart.umd.min.js"
mkdir -p "$CHARTJS_DIR"
if [[ ! -f "$CHARTJS_FILE" ]]; then
    log "Downloading Chart.js for analytics dashboard..."
    wget -q --timeout=30 \
        "https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" \
        -O "$CHARTJS_FILE" >> "$LOGFILE" 2>&1 \
        && log "Chart.js downloaded OK" \
        || { log "WARN: Chart.js download failed — dashboard will use CDN fallback"; rm -f "$CHARTJS_FILE"; }
fi

log "=== SLED Santa Mailbox install complete ==="
exit 0
