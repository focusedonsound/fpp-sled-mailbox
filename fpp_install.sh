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

# ── Git state self-repair ─────────────────────────────────────────
# fpp_install.sh is called after FPP's git pull attempt (whether or not
# it succeeded). This block repairs common failure modes so the plugin
# always ends up at the latest origin/main and future updates work.
#
# Failures handled:
#  1. SSH remote  — Pi has no GitHub SSH key; switch to HTTPS
#  2. Detached HEAD — git pull refuses to run; reset to origin/main
#  3. No upstream  — git pull has nothing to pull from; set tracking
#  4. Execute-bit drift — fpp_install.sh chmod+x makes git see tracked
#     shell/py files as locally modified; git pull refuses to overwrite.
#     (Caused by commits made from /tmp which strips execute bits.)
(
    cd "$PLUGIN_DIR" 2>/dev/null || exit 0

    EXPECTED_URL="https://github.com/focusedonsound/fpp-sled-mailbox.git"
    CURRENT_URL=$(git remote get-url origin 2>/dev/null || echo "")

    # 1. Fix SSH → HTTPS remote so updates work without GitHub SSH keys
    if [[ "$CURRENT_URL" == git@github.com:* ]] || \
       [[ -n "$CURRENT_URL" && "$CURRENT_URL" != "$EXPECTED_URL" ]]; then
        log "Fixing git remote: $CURRENT_URL → $EXPECTED_URL"
        git remote set-url origin "$EXPECTED_URL" 2>/dev/null \
            && log "Remote fixed" || log "WARN: could not fix remote URL"
    fi

    # Fetch latest so origin/main ref is up-to-date for all checks below
    git fetch origin main 2>/dev/null || true

    BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")

    if [[ "$BRANCH" == "HEAD" || -z "$BRANCH" ]]; then
        # 2. Detached HEAD — create/reset local main branch
        log "Detached HEAD — resetting to origin/main"
        git checkout -B main --track origin/main 2>/dev/null \
            && git reset --hard origin/main 2>/dev/null \
            && log "Repair OK: $(git log --oneline -1 2>/dev/null)" \
            || log "WARN: HEAD repair failed"
    else
        # Ensure main tracks origin/main
        git branch --set-upstream-to=origin/main main 2>/dev/null || true

        # 3+4. Reset any local modifications (missing upstream OR execute-bit
        # drift) then fast-forward to origin/main if still behind.
        if ! git diff --quiet 2>/dev/null || ! git diff --cached --quiet 2>/dev/null; then
            log "Local modifications detected (execute-bit drift?) — resetting tracked files"
            git reset --hard origin/main 2>/dev/null \
                && log "Reset OK: $(git log --oneline -1 2>/dev/null)" \
                || log "WARN: reset failed"
        fi

        LOCAL=$(git rev-parse HEAD 2>/dev/null || echo "local")
        REMOTE=$(git rev-parse origin/main 2>/dev/null || echo "remote")
        if [[ "$LOCAL" != "$REMOTE" ]]; then
            log "Behind origin/main — fast-forwarding"
            git merge --ff-only origin/main 2>/dev/null \
                && log "Updated: $(git log --oneline -1 2>/dev/null)" \
                || log "WARN: fast-forward failed"
        fi

        log "Git OK: $(git log --oneline -1 2>/dev/null)"
    fi
)

# ── Create media directories ─────────────────────────────────────
# Do this FIRST so the media log path is available.
mkdir -p /home/fpp/media/logs
mkdir -p /home/fpp/media/config

# Now that the dir exists, copy /tmp log into media log
cat "$LOGFILE" >> "$MEDIA_LOG" 2>/dev/null || true

# pluginInfo.json's dependencies.packages block already declares
# python3-serial, python3-paho-mqtt, and python3-gpiozero, so FPP 10+
# installs them before this script runs (FPP_DEPS_RESOLVED=1 is exported in
# that case). Only install them by hand here as a fallback for FPP 9, which
# silently ignores the dependencies block.
if [ -z "${FPP_DEPS_RESOLVED:-}" ]; then
    log "Installing system packages via apt-get..."
    if apt-get install -y --no-install-recommends \
        python3-serial \
        python3-paho-mqtt \
        python3-gpiozero \
        >> "$LOGFILE" 2>&1; then
        log "apt-get packages installed OK"
    else
        log "WARN: apt-get failed or partial — pyserial/paho-mqtt/gpiozero may be missing"
    fi
else
    log "Dependencies already resolved by FPP (FPP_DEPS_RESOLVED=1); skipping manual apt-get."
fi

# ── Optional: Adafruit DHT support ────────────────────────────────
# Not in pluginInfo.json's dependencies (DHT11 support is optional, not
# needed by everyone), and not on apt -- pip is the right tool here per the
# plugin guidelines' ad-hoc install rule. Installs into FPP's system Python
# like FPP's own dependency resolution does, not a plugin-local vendor dir.
if python3 -m pip --version >/dev/null 2>&1; then
    log "Installing optional Adafruit DHT library..."
    python3 -m pip install --quiet --break-system-packages adafruit-circuitpython-dht >> "$LOGFILE" 2>&1 \
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
    "opt_in": false,
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
