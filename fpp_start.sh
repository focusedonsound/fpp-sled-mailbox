#!/bin/bash
# fpp_start.sh — called by FPP at startup to launch the SLED daemon
PLUGIN_DIR="$(dirname "$0")"
bash "${PLUGIN_DIR}/callbacks.sh" pluginStart
