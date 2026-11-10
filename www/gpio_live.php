<?php
/**
 * gpio_live.php — Live GPIO sensor-state proxy
 *
 * Returns the most recent GPIO snapshot written by the SLED daemon
 * (state/sled_gpio.json), so the dashboard can show each sensor's live level —
 * e.g. to confirm a PIR changes from LOW to HIGH when it detects a visitor.
 *
 * Adds an "active" flag — false when the snapshot is stale (> 5 seconds old),
 * which normally means the daemon is stopped.
 *
 * Response shape:
 * {
 *   "active":  bool,           // snapshot is fresh (daemon running)
 *   "ts":      float,          // Unix timestamp of snapshot
 *   "sensors": [
 *     { "label": str, "pin": int, "code": str,
 *       "active_high": bool, "raw": "HIGH"|"LOW"|null, "active": bool|null }
 *   ]
 * }
 * On error: { "active": false, "sensors": [], "error": "..." }
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Content-Type-Options: nosniff');

$path = "/home/fpp/media/plugins/fpp-sled-mailbox/state/sled_gpio.json";

if (!file_exists($path)) {
    echo json_encode([
        'active'  => false,
        'sensors' => [],
        'error'   => 'No GPIO data — start the SLED daemon and wait a moment.',
    ]);
    exit;
}

$raw = @file_get_contents($path);
if ($raw === false) {
    echo json_encode(['active' => false, 'sensors' => [], 'error' => 'Could not read snapshot file']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['active' => false, 'sensors' => [], 'error' => 'Snapshot parse error']);
    exit;
}

// Mark active only if the snapshot is fresh. The daemon writes at ~2 Hz, so a
// file older than 5 s means it has stopped.
$age = microtime(true) - (float)($data['ts'] ?? 0);
$data['active']  = ($age < 5.0);
$data['sensors'] = $data['sensors'] ?? [];

echo json_encode($data);
