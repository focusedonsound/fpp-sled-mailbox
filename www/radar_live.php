<?php
/**
 * radar_live.php — Live radar snapshot proxy
 *
 * Returns the most recent JSON snapshot written by the LD2410 diagnostic reader.
 * Adds an "active" flag — false when the snapshot is stale (> 2 seconds old).
 *
 * Query parameters:
 *   side=a|b   (default: a)
 *
 * Response shape:
 * {
 *   "active":    bool,
 *   "ts":        float,   // Unix timestamp of snapshot
 *   "status":    string,  // "none"|"moving"|"static"|"both"
 *   "distance":  float,   // metres to nearest target
 *   "gates":     { "moving": [9 ints], "stationary": [9 ints] },
 *   "thresholds":{ "moving": [9 ints], "stationary": [9 ints] },
 *   "max_gate":  int,
 *   "timeout":   int
 * }
 * On error: { "active": false, "error": "..." }
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Content-Type-Options: nosniff');

$side = strtolower(trim((string)($_GET['side'] ?? 'a')));
if (!in_array($side, ['a', 'b'], true)) {
    echo json_encode(['active' => false, 'error' => 'Invalid side — use a or b']);
    exit;
}

$path = "/home/fpp/media/logs/sled_radar_{$side}.json";

if (!file_exists($path)) {
    echo json_encode([
        'active' => false,
        'error'  => "No diagnostic data for Radar " . strtoupper($side) .
                    " — open Radar Diagnostics and wait a moment.",
    ]);
    exit;
}

$raw = @file_get_contents($path);
if ($raw === false) {
    echo json_encode(['active' => false, 'error' => 'Could not read snapshot file']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['active' => false, 'error' => 'Snapshot parse error']);
    exit;
}

// Mark active if snapshot is fresher than 2 seconds
$age = microtime(true) - (float)($data['ts'] ?? 0);
$data['active'] = ($age < 2.0);

echo json_encode($data);
