<?php
// reset.php — SLED counter reset endpoint
// POST body: action=reset_day
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($ok, $msg) {
    echo json_encode(['status' => $ok ? 'OK' : 'ERROR', 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'POST required');
}

$DB_PATH = "/home/fpp/media/plugins/fpp-sled-mailbox/state/sled.db";
$action  = trim($_POST['action'] ?? '');

if (!file_exists($DB_PATH)) respond(false, 'Database not found — has the daemon run yet?');

if ($action !== 'reset_day') respond(false, 'Unknown action');

try {
    $db = new SQLite3($DB_PATH, SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(3000);
    $db->exec("PRAGMA journal_mode=WAL");

    $today = date('Y-m-d');
    $now   = date('c');

    // ── Zero today's car counters ─────────────────────────────────────────────
    foreach (['car_today', 'inbound_today', 'outbound_today'] as $key) {
        $db->exec(
            "INSERT INTO counters(key,value,updated) VALUES('{$key}',0,'{$now}')
             ON CONFLICT(key) DO UPDATE SET value=0, updated='{$now}'"
        );
    }

    // ── Delete today's events ─────────────────────────────────────────────────
    $stmt = $db->prepare(
        "DELETE FROM events WHERE substr(ts,1,10) = :today"
    );
    $stmt->bindValue(':today', $today, SQLITE3_TEXT);
    $stmt->execute();
    $deleted = $db->changes();

    // ── Recalculate all-time totals from remaining events ─────────────────────
    // (keeps counters table consistent with events table as source of truth)
    foreach (['letter', 'donation', 'car'] as $kind) {
        $map = ['letter' => 'letter_total', 'donation' => 'donation_total', 'car' => 'car_total'];
        $key = $map[$kind];
        $cnt = $db->querySingle("SELECT COUNT(*) FROM events WHERE kind='{$kind}'") ?? 0;
        $db->exec(
            "INSERT INTO counters(key,value,updated) VALUES('{$key}',{$cnt},'{$now}')
             ON CONFLICT(key) DO UPDATE SET value={$cnt}, updated='{$now}'"
        );
    }

    $db->close();
    respond(true, "Today's data cleared. {$deleted} event(s) removed and totals recalculated.");

} catch (Exception $e) {
    respond(false, $e->getMessage());
}
