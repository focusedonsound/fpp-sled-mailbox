#!/bin/bash
# fpp_stop.sh — called by FPP at shutdown to stop the SLED daemon
# Also handles systemd-managed service if present
if systemctl is-active --quiet sled-mailbox 2>/dev/null; then
    systemctl stop sled-mailbox
else
    PLUGIN_DIR="$(dirname "$0")"
    bash "${PLUGIN_DIR}/callbacks.sh" pluginStop
fi
