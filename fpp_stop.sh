#!/bin/bash
# fpp_stop.sh — called by FPP at shutdown to stop the SLED daemon
# Also handles systemd-managed service if present.
# Uses 'sudo systemctl' so this works when called as the fpp user;
# fpp_install.sh writes /etc/sudoers.d/sled-mailbox for passwordless access.
if sudo systemctl is-active --quiet sled-mailbox 2>/dev/null; then
    sudo systemctl stop sled-mailbox
else
    PLUGIN_DIR="$(dirname "$0")"
    bash "${PLUGIN_DIR}/callbacks.sh" pluginStop
fi
