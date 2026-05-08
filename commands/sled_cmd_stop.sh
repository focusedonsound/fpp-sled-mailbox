#!/bin/bash
# FPP Command: SLED - Stop Daemon

CMD_QUEUE="/home/fpp/media/logs/sled_trigger.cmd"
LOG_FILE="/home/fpp/media/logs/SledMailbox.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [fpp-cmd] $*" >> "$LOG_FILE"; }

log "TRIGGER: stop"
echo "stop" > "$CMD_QUEUE"
