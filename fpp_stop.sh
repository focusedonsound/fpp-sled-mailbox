#!/bin/bash
# fpp_stop.sh — called by FPP at shutdown to stop the SLED daemon
PLUGIN_DIR="$(dirname "$0")"
bash "${PLUGIN_DIR}/callbacks.sh" pluginStop
