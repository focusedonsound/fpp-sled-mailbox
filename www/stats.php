<?php
// stats.php — SLED analytics JSON API
// GET params:
//   days   = integer 7–365 (default 30)
//   period = "day" | "week" | "month" (default "day")
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$DB_PATH  = "/home/fpp/media/logs/sled.db";
$CFG_PATH = "/home/fpp/media/config/sled.json";
$PID_FILE = "/home/fpp/media/logs/sled_daemon.pid";

// ── Helpers ──────────────────────────────────────────────────────────────────

function daemon_running($pf) {
    if (!file_exists($pf)) return false;
    $pid = trim(@file_get_contents($pf));
    if (!$pid || !is_numeric($pid)) return false;
    if (function_exists('posix_kill')) return posix_kill((int)$pid, 0);
    return is_dir("/proc/$pid");
}

function load_cfg($path) {
    if (!file_exists($path)) return [];
    $j = @json_decode(file_get_contents($path), true);
    return is_array($j) ? $j : [];
}

// ── Request params ────────────────────────────────────────────────────────────

$days   = isset($_GET['days'])   ? (int)$_GET['days']   : 30;
$days   = min(365, max(7, $days));
$period = isset($_GET['period']) ? $_GET['period']       : 'day';
if (!in_array($period, ['day', 'week', 'month'], true)) $period = 'day';

// ── Config ────────────────────────────────────────────────────────────────────

$cfg        = load_cfg($CFG_PATH);
$carEnabled = !empty($cfg['ld2410']['enabled']);
$labelTow   = $cfg['direction']['label_toward'] ?? 'Inbound';

// ── Skeleton response ─────────────────────────────────────────────────────────

$out = [
    'daemon_running' => daemon_running($PID_FILE),
    'car_enabled'    => $carEnabled,
    'label_inbound'  => $labelTow,
    'label_outbound' => $cfg['direction']['label_away'] ?? 'Outbound',
    'refresh_ts'     => date('Y-m-d H:i:s'),
    'period'         => $period,
    'days'           => $days,
    'today'          => ['letters' => 0, 'donations' => 0, 'inbound' => 0, 'outbound' => 0],
    'alltime'        => ['letters' => 0, 'donations' => 0, 'cars' => 0],
    'chart'          => ['labels' => [], 'letters' => [], 'donations' => [], 'inbound' => [], 'outbound' => []],
    'recent'         => [],
];

if (!file_exists($DB_PATH)) {
    echo json_encode($out);
    exit;
}

// ── SQLite queries ────────────────────────────────────────────────────────────

try {
    $db = new SQLite3($DB_PATH, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(2000);

    $today  = date('Y-m-d');
    $cutoff = date('Y-m-d', strtotime("-{$days} days"));

    // Today's letter/donation counts — derived from events table (authoritative)
    $stmt = $db->prepare(
        "SELECT kind, COUNT(*) AS cnt FROM events
         WHERE substr(ts,1,10)=:today AND kind IN ('letter','donation')
         GROUP BY kind"
    );
    $stmt->bindValue(':today', $today, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        if ($r['kind'] === 'letter')   $out['today']['letters']   = (int)$r['cnt'];
        if ($r['kind'] === 'donation') $out['today']['donations'] = (int)$r['cnt'];
    }

    // Today's car + all-time totals from counters table
    $res = $db->query(
        "SELECT key, value FROM counters
         WHERE key IN ('inbound_today','outbound_today','letter_total','donation_total','car_total')"
    );
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        switch ($r['key']) {
            case 'inbound_today':  $out['today']['inbound']     = (int)$r['value']; break;
            case 'outbound_today': $out['today']['outbound']    = (int)$r['value']; break;
            case 'letter_total':   $out['alltime']['letters']   = (int)$r['value']; break;
            case 'donation_total': $out['alltime']['donations'] = (int)$r['value']; break;
            case 'car_total':      $out['alltime']['cars']      = (int)$r['value']; break;
        }
    }

    // ── Build period bucket labels & zero-filled maps ──────────────────────────

    $labels    = [];
    $letterMap = [];
    $donateMap = [];
    $inMap     = [];
    $outMap    = [];

    if ($period === 'day') {
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = $d;
            $letterMap[$d] = $donateMap[$d] = $inMap[$d] = $outMap[$d] = 0;
        }
        $groupExpr = "substr(ts,1,10)";   // YYYY-MM-DD

    } elseif ($period === 'week') {
        // Build ISO week keys: YYYY-Www  (e.g. 2025-W50)
        $ptr = strtotime('monday this week', strtotime($cutoff));
        $end = strtotime('monday this week') + 7 * 86400;
        while ($ptr < $end) {
            $k = date('Y', $ptr) . '-W' . str_pad((int)date('W', $ptr), 2, '0', STR_PAD_LEFT);
            $labels[] = $k;
            $letterMap[$k] = $donateMap[$k] = $inMap[$k] = $outMap[$k] = 0;
            $ptr += 7 * 86400;
        }
        $groupExpr = "strftime('%Y-W%W', substr(ts,1,10))";

    } else { // month
        $ptr = mktime(0, 0, 0, (int)date('m', strtotime($cutoff)), 1, (int)date('Y', strtotime($cutoff)));
        $endM = mktime(0, 0, 0, (int)date('m'), 1, (int)date('Y'));
        while ($ptr <= $endM) {
            $k = date('Y-m', $ptr);
            $labels[] = $k;
            $letterMap[$k] = $donateMap[$k] = $inMap[$k] = $outMap[$k] = 0;
            $ptr = mktime(0, 0, 0, (int)date('m', $ptr) + 1, 1, (int)date('Y', $ptr));
        }
        $groupExpr = "substr(ts,1,7)";    // YYYY-MM
    }

    // ── Letter / donation daily/weekly/monthly ─────────────────────────────────

    $stmt = $db->prepare(
        "SELECT {$groupExpr} AS bucket, kind, COUNT(*) AS cnt
         FROM events
         WHERE kind IN ('letter','donation') AND substr(ts,1,10) >= :cutoff
         GROUP BY bucket, kind"
    );
    $stmt->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $k = $r['bucket'];
        if (!array_key_exists($k, $letterMap)) continue;
        if ($r['kind'] === 'letter')   $letterMap[$k] = (int)$r['cnt'];
        if ($r['kind'] === 'donation') $donateMap[$k] = (int)$r['cnt'];
    }

    // ── Car counts by direction ───────────────────────────────────────────────

    $stmt = $db->prepare(
        "SELECT {$groupExpr} AS bucket,
                json_extract(data,'$.dir') AS dir,
                COUNT(*) AS cnt
         FROM events
         WHERE kind='car' AND substr(ts,1,10) >= :cutoff
         GROUP BY bucket, dir"
    );
    $stmt->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $k = $r['bucket'];
        if (!array_key_exists($k, $inMap)) continue;
        if ($r['dir'] === $labelTow) $inMap[$k]  += (int)$r['cnt'];
        else                         $outMap[$k] += (int)$r['cnt'];
    }

    $out['chart'] = [
        'labels'    => $labels,
        'letters'   => array_values($letterMap),
        'donations' => array_values($donateMap),
        'inbound'   => array_values($inMap),
        'outbound'  => array_values($outMap),
    ];

    // ── Recent events (last 50) ───────────────────────────────────────────────

    $res = $db->query(
        "SELECT id, ts, kind, data FROM events ORDER BY id DESC LIMIT 50"
    );
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $data = @json_decode($r['data'], true) ?: [];
        $out['recent'][] = [
            'id'   => (int)$r['id'],
            'ts'   => $r['ts'],
            'kind' => $r['kind'],
            'dir'  => $data['dir']  ?? null,
            'clip' => $data['clip'] ?? null,
        ];
    }

    $db->close();

} catch (Exception $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out);
