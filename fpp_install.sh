#!/bin/bash
# fpp_install.sh — SLED Santa Mailbox plugin installer
# Called by FPP when the plugin is installed or updated.

PLUGIN_DIR="$(dirname "$0")"

# Resolve FPP's logs directory the documented way (supports a relocated
# media directory) rather than hard-coding /home/fpp/media/logs, and use
# the single FPP-conformant log file (plugin-<repoName>.log) for both this
# install script and the daemon, per the plugin guidelines' logging rules.
: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common" 2>/dev/null || true
LOGDIR="$(getSetting logDirectory 2>/dev/null)"
LOGDIR="${LOGDIR:-/home/fpp/media/logs}"
LOGFILE="${LOGDIR}/plugin-fpp-sled-mailbox.log"

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    mkdir -p "$LOGDIR" 2>/dev/null || true
    echo "$msg" >> "$LOGFILE" 2>/dev/null || echo "$msg"
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
# (log() already mkdir -p's $LOGDIR on every call)
mkdir -p /home/fpp/media/config
mkdir -p /home/fpp/media/plugins/fpp-sled-mailbox/state

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

# ── Sudoers rule for fpp user ────────────────────────────────────
# FPP runs as root but its web UI (PHP) and plugin callbacks run as the
# 'fpp' user.  Without this rule, 'systemctl start/stop sled-mailbox'
# from callbacks.sh or fpp_stop.sh returns:
#   "Failed to start sled-mailbox.service: Interactive authentication required"
# and silently falls back to a bare nohup launch (no auto-restart on crash).
# This rule grants the fpp user passwordless control of this one service only.
SUDOERS_FILE="/etc/sudoers.d/sled-mailbox"
cat > "${SUDOERS_FILE}.tmp" << 'SUDOEOF'
# SLED Santa Mailbox — allow fpp user to control its own daemon service
# without a password prompt.  Scope is intentionally limited to this service.
fpp ALL=(ALL) NOPASSWD: \
    /bin/systemctl start sled-mailbox, \
    /bin/systemctl stop sled-mailbox, \
    /bin/systemctl restart sled-mailbox, \
    /bin/systemctl is-active sled-mailbox, \
    /bin/systemctl is-enabled sled-mailbox, \
    /usr/bin/systemctl start sled-mailbox, \
    /usr/bin/systemctl stop sled-mailbox, \
    /usr/bin/systemctl restart sled-mailbox, \
    /usr/bin/systemctl is-active sled-mailbox, \
    /usr/bin/systemctl is-enabled sled-mailbox
SUDOEOF
chmod 0440 "${SUDOERS_FILE}.tmp"
if visudo -cf "${SUDOERS_FILE}.tmp" >> "$LOGFILE" 2>&1; then
    mv "${SUDOERS_FILE}.tmp" "$SUDOERS_FILE"
    log "Sudoers rule installed: $SUDOERS_FILE"
else
    rm -f "${SUDOERS_FILE}.tmp"
    log "WARN: sudoers rule validation failed — rule not installed (daemon control may require manual start)"
fi

setSetting restartFlag 1 2>/dev/null || true

log "=== SLED Santa Mailbox install complete ==="
exit 0
