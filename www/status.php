<?php
// status.php — Live status API for SLED dashboard
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($ok, $data = []) {
  echo json_encode(array_merge(["status" => $ok ? "OK" : "ERROR"], $data));
  exit;
}

// ── Daemon status ─────────────────────────────────────────────────────────
$pidFile = "/home/fpp/media/logs/sled_daemon.pid";
$daemonRunning = false;
if (file_exists($pidFile)) {
  $pid = trim(@file_get_contents($pidFile));
  if ($pid && is_numeric($pid)) {
    $daemonRunning = (posix_kill((int)$pid, 0) === true);
  }
}

// ── SQLite counters + recent events ──────────────────────────────────────
$dbPath = "/home/fpp/media/logs/sled.db";
$counters = [
  "letter_total"   => 0,
  "donation_total" => 0,
  "car_total"      => 0,
  "car_today"      => 0,
  "inbound_today"  => 0,
  "outbound_today" => 0,
];
$events      = [];
$lastLetter  = null;
$lastDonation= null;
$lastCarTime = null;
$lastDir     = null;

if (file_exists($dbPath)) {
  try {
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Counters
    $rows = $db->query("SELECT key, value FROM counters")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
      if (array_key_exists($r['key'], $counters)) {
        $counters[$r['key']] = (int)$r['value'];
      }
    }

    // Last timestamps from event log
    $stmt = $db->prepare("SELECT ts FROM events WHERE kind=? ORDER BY id DESC LIMIT 1");

    $stmt->execute(['letter']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $lastLetter = $row['ts'];

    $stmt->execute(['donation']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $lastDonation = $row['ts'];

    $stmt->execute(['car']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $lastCarTime = $row['ts'];
      // get direction
      $row2 = $db->query("SELECT data FROM events WHERE kind='car' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
      if ($row2) {
        $d = json_decode($row2['data'] ?? '{}', true);
        $lastDir = $d['dir'] ?? null;
      }
    }

    // Recent events (last 50)
    $rows2 = $db->query(
      "SELECT id, ts, kind, data FROM events ORDER BY id DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows2 as $r) {
      $events[] = [
        "id"   => (int)$r['id'],
        "ts"   => $r['ts'],
        "kind" => $r['kind'],
        "data" => json_decode($r['data'] ?? '{}', true),
      ];
    }
  } catch (Exception $e) {
    // DB not ready yet — return empty data
  }
}

respond(true, [
  "daemon_running" => $daemonRunning,
  "counters"       => $counters,
  "last_letter"    => $lastLetter,
  "last_donation"  => $lastDonation,
  "last_car_time"  => $lastCarTime,
  "last_dir"       => $lastDir,
  "events"         => $events,
]);
