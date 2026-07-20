#!/bin/bash
# fpp_uninstall.sh — SLED Santa Mailbox plugin uninstaller
# Called by FPP when the plugin is removed. Mirrors fpp_install.sh's
# systemd service setup in reverse. Safe to run twice.

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

log "=== SLED Santa Mailbox uninstall started ==="

if command -v systemctl >/dev/null 2>&1; then
    if systemctl is-active --quiet sled-mailbox 2>/dev/null; then
        systemctl stop sled-mailbox || true
    fi
    systemctl disable sled-mailbox 2>/dev/null || true

    SERVICE_DST="/etc/systemd/system/sled-mailbox.service"
    if [[ -f "$SERVICE_DST" ]]; then
        rm -f "$SERVICE_DST"
        systemctl daemon-reload
        log "Removed sled-mailbox.service"
    fi
fi

log "=== SLED Santa Mailbox uninstall complete. Config and media left in place. ==="
exit 0
