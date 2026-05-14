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

# Now that the dir exists, copy /tmp log into media log
cat "$LOGFILE" >> "$MEDIA_LOG" 2>/dev/null || true

# ── System packages ──────────────────────────────────────────────
log "Installing system packages via apt-get..."
if apt-get install -y --no-install-recommends \
    python3-serial \
    python3-paho-mqtt \
    python3-gpiozero \
    >> "$LOGFILE" 2>&1; then
    log "apt-get packages installed OK"
else
    log "WARN: apt-get failed or partial — will try pip3 fallbacks"
fi

# ── Vendor directory for pip packages (no root required) ─────────────────
# Install critical packages into scripts/vendor/ so they work regardless
# of whether apt-get had root access to install system-wide.
VENDOR_DIR="${PLUGIN_DIR}/scripts/vendor"
mkdir -p "$VENDOR_DIR"

# Install critical packages into vendor dir using our pip-free downloader
INSTALL_PY="${PLUGIN_DIR}/scripts/install_vendor.py"
if [[ -f "$INSTALL_PY" ]]; then
    log "Running install_vendor.py to fetch pyserial and paho-mqtt..."
    python3 "$INSTALL_PY" "$VENDOR_DIR" >> "$LOGFILE" 2>&1 \
        && log "install_vendor.py completed" \
        || log "WARN: install_vendor.py had errors (check log above)"
else
    log "WARN: install_vendor.py not found — pyserial/paho-mqtt may be missing"
fi

# ── Optional: Adafruit DHT support ────────────────────────────────
if python3 -m pip --version >/dev/null 2>&1; then
    log "Installing optional Adafruit DHT library..."
    python3 -m pip install --quiet --target="$VENDOR_DIR" adafruit-circuitpython-dht >> "$LOGFILE" 2>&1 \
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
  "playlists": {
    "idle": "sled_idle",
    "letter": ["sled_letter"],
    "donation": [],
    "play_timeout_s": 120
  },
  "schedule": {
    "start": "16:00",
    "end": "22:00"
  },
  "pins": {
    "letter": 17,
    "donation": null
  },
  "car": {
    "sequence_window_s": 0.8,
    "cooldown_s": 1.5,
    "parked_timeout_s": 180,
    "direction_window_s": 10.0
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
    "A": {"port": "/dev/ttyUSB0", "min_energy": 20},
    "B": {"port": "/dev/ttyUSB1", "min_energy": 20}
  },
  "mqtt": {
    "enabled": false,
    "base": "sled",
    "device_name": "SLED Santa Mailbox"
  },
  "telemetry": {
    "opt_in": true,
    "install_id": ""
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
