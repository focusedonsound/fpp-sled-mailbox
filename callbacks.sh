#!/bin/bash
# callbacks.sh — SLED Santa Mailbox FPP lifecycle hooks
#
# FPP calls this script with $1 = hook name:
#   pluginStart   — FPP has finished booting; start the daemon
#   pluginStop    — FPP is shutting down; stop the daemon

PLUGIN_DIR="$(dirname "$0")"
DAEMON="${PLUGIN_DIR}/scripts/sled_daemon.py"
PID_FILE="/home/fpp/media/logs/sled_daemon.pid"
LOG_FILE="/home/fpp/media/logs/SledMailbox.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [callbacks] $*" >> "$LOG_FILE"; }

daemon_start() {
    # At boot, FPP calls pluginStart very early — before /home/fpp/media/ is
    # guaranteed to be writable.  Wait up to 15 s for the logs directory to
    # become available so log writes and the daemon's FileHandler both work.
    local waited=0
    while [[ ! -w "/home/fpp/media/logs" ]] && (( waited < 15 )); do
        sleep 1
        (( waited++ )) || true
    done
    # Ensure the logs dir exists even on first boot
    mkdir -p "/home/fpp/media/logs" 2>/dev/null || true

    # Read enabled flag from config
    ENABLED=$(python3 -c "
import json, sys
try:
    cfg = json.load(open('/home/fpp/media/config/sled.json'))
    print('true' if cfg.get('enabled', True) else 'false')
except: print('true')
" 2>/dev/null || echo "true")

    if [[ "$ENABLED" != "true" ]]; then
        log "Plugin disabled in config — not starting daemon"
        return 0
    fi

    # Install / verify vendored Python packages (pyserial, paho-mqtt).
    # Runs every start but install_vendor.py skips packages already present,
    # so this is effectively instant after the first successful install.
    # Running here (not in fpp_install.sh) guarantees network is available.
    # Must run BEFORE starting via systemd so packages exist when the daemon
    # process launches (fpp_install.sh may have run without network).
    VENDOR_DIR="${PLUGIN_DIR}/scripts/vendor"
    INSTALL_PY="${PLUGIN_DIR}/scripts/install_vendor.py"
    if [[ -f "$INSTALL_PY" ]]; then
        log "Checking vendored Python packages..."
        python3 "$INSTALL_PY" "$VENDOR_DIR" >> "$LOG_FILE" 2>&1 \
            && log "Vendor packages OK" \
            || log "WARN: install_vendor.py had errors (non-fatal)"
    fi

    # ── Prefer systemd when the service is installed and enabled ─────────────
    # sled-mailbox.service is installed by fpp_install.sh and starts the daemon
    # automatically via systemd on every boot.  If we also start it here via
    # nohup we end up with two instances competing for the serial ports.
    # Delegate to systemctl when the service is enabled; fall back to nohup
    # only when systemd is not managing the service (e.g. older install).
    if systemctl is-enabled --quiet sled-mailbox 2>/dev/null; then
        if systemctl is-active --quiet sled-mailbox 2>/dev/null; then
            log "Daemon already running via systemd (skipping nohup start)"
            return 0
        fi
        log "Starting daemon via systemctl..."
        if systemctl start sled-mailbox >> "$LOG_FILE" 2>&1; then
            log "Daemon started via systemctl"
            return 0
        fi
        log "WARN: systemctl start failed — falling back to nohup"
    fi

    # ── Fallback: start directly via nohup ───────────────────────────────────
    # Check if already running via PID file
    if [[ -f "$PID_FILE" ]]; then
        PID=$(cat "$PID_FILE" 2>/dev/null || true)
        if [[ -n "$PID" ]] && kill -0 "$PID" 2>/dev/null; then
            # Verify it really is our daemon (stale PIDs survive reboots)
            if grep -q "sled_daemon" /proc/"$PID"/cmdline 2>/dev/null; then
                log "Daemon already running (PID=$PID)"
                return 0
            fi
        fi
        rm -f "$PID_FILE"
    fi

    if [[ ! -f "$DAEMON" ]]; then
        log "ERROR: daemon not found: $DAEMON"
        return 1
    fi

    log "Starting SLED daemon (nohup)..."
    export PYTHONPATH="${PLUGIN_DIR}/scripts:${PYTHONPATH:-}"
    nohup python3 "$DAEMON" >> "$LOG_FILE" 2>&1 &
    echo "$!" > "$PID_FILE"
    log "Daemon started (PID=$(cat "$PID_FILE"))"
}

daemon_stop() {
    if [[ ! -f "$PID_FILE" ]]; then
        log "No PID file found — daemon not running"
        # Fallback: kill by process name
        pkill -f "sled_daemon.py" 2>/dev/null || true
        return 0
    fi

    PID=$(cat "$PID_FILE" 2>/dev/null || true)
    if [[ -z "$PID" || ! "$PID" =~ ^[0-9]+$ ]]; then
        log "Invalid PID in file; removing"
        rm -f "$PID_FILE"
        return 0
    fi

    log "Stopping SLED daemon (PID=$PID)..."
    kill -TERM "$PID" 2>/dev/null || true

    # Wait up to 10 seconds for clean shutdown
    waited=0
    while kill -0 "$PID" 2>/dev/null && (( waited < 100 )); do
        sleep 0.1
        (( waited++ )) || true
    done

    if kill -0 "$PID" 2>/dev/null; then
        log "WARN: daemon did not stop cleanly; sending SIGKILL"
        kill -9 "$PID" 2>/dev/null || true
    fi

    rm -f "$PID_FILE"
    log "Daemon stopped"
}

case "${1:-}" in
    pluginStart)
        daemon_start
        ;;
    pluginStop)
        daemon_stop
        ;;
    getLinks)
        # Register this plugin in FPP's Content Setup navigation menu.
        # FPP calls this callback to discover plugin navigation entries.
        cat <<'JSON'
[
  {
    "menu": "content",
    "text": "SLED Smart Letters",
    "url":  "/plugin.php?plugin=fpp-sled-mailbox&page=www/index.php",
    "icon": "fas fa-fw fa-envelope-open-text"
  },
  {
    "menu": "content",
    "text": "SLED MQTT / Home Assistant",
    "url":  "/plugin.php?plugin=fpp-sled-mailbox&page=www/mqtt.php",
    "icon": "fas fa-fw fa-tower-broadcast"
  }
]
JSON
        ;;
    *)
        # FPP may call other hooks; ignore them silently
        exit 0
        ;;
esac
