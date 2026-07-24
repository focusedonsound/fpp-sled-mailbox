#!/bin/bash
# FPP Command: SLED - Trigger Letter
# Injects a "letter" event into the running SLED daemon via the command queue.

CMD_QUEUE="/home/fpp/media/plugins/fpp-sled-mailbox/state/sled_trigger.cmd"
mkdir -p "$(dirname "$CMD_QUEUE")" 2>/dev/null || true
LOG_FILE="/home/fpp/media/logs/plugin-fpp-sled-mailbox.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [fpp-cmd] $*" >> "$LOG_FILE"; }

log "TRIGGER: letter"
echo "letter" > "$CMD_QUEUE"
