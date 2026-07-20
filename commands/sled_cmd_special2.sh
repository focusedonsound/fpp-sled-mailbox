#!/bin/bash
# FPP Command: SLED - Trigger Special Message 2
# Injects a "special_2" event into the running SLED daemon via the command queue.

CMD_QUEUE="/home/fpp/media/logs/sled_trigger.cmd"
LOG_FILE="/home/fpp/media/logs/plugin-fpp-sled-mailbox.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [fpp-cmd] $*" >> "$LOG_FILE"; }

log "TRIGGER: special_2"
echo "special_2" > "$CMD_QUEUE"
