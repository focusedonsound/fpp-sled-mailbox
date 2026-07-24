<?php
// seed.php — SLED Development Sample Data Tool
//
// Generates 45 days of realistic synthetic events so the analytics dashboard
// has something to look at during development. Every seeded row is tagged
// with "seeded":1 in its data JSON so it can be identified and removed
// independently of any real events you accumulate while testing.
//
// ACCESS:  plugin.php?plugin=fpp-sled-mailbox&page=www/seed.php
// CLEAR:   click "Clear Sample Data" before going live, or use reset.php
//          to wipe everything including real events.
//
// GET  → HTML tool page
// POST action=seed  → generate sample data (JSON response)
// POST action=clear → remove seeded rows only (JSON response)

ini_set('display_errors', '0');

$DB_PATH  = "/home/fpp/media/plugins/fpp-sled-mailbox/state/sled.db";
$CFG_PATH = "/home/fpp/media/config/sled.json";

// ── Schema (mirrors sled_db.py — safe to run if DB already exists) ────────────
define('SEED_DDL', "
CREATE TABLE IF NOT EXISTS events (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    ts   TEXT    NOT NULL,
    kind TEXT    NOT NULL,
    data TEXT    DEFAULT '{}'
);
CREATE TABLE IF NOT EXISTS counters (
    key     TEXT PRIMARY KEY,
    value   INTEGER NOT NULL DEFAULT 0,
    updated TEXT
);
");

// ── Config helpers ────────────────────────────────────────────────────────────

function seed_load_cfg($path) {
    if (!file_exists($path)) return [];
    $j = @json_decode(file_get_contents($path), true);
    return is_array($j) ? $j : [];
}

function seed_open_rw($dbPath) {
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->busyTimeout(3000);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec(SEED_DDL);
    return $db;
}

function seed_recalc_counters($db) {
    $now = date('c');
    foreach (['letter' => 'letter_total', 'donation' => 'donation_total', 'car' => 'car_total'] as $kind => $key) {
        $n = (int)($db->querySingle("SELECT COUNT(*) FROM events WHERE kind='{$kind}'") ?? 0);
        $db->exec("INSERT INTO counters(key,value,updated) VALUES('{$key}',{$n},'{$now}')
                   ON CONFLICT(key) DO UPDATE SET value={$n},updated='{$now}'");
    }
    // Recalculate today's letter/donation from events; keep car_today in sync too
    $today = date('Y-m-d');
    foreach (['letter'=>'letter_today', 'donation'=>'donation_today'] as $kind => $key) {
        $n = (int)($db->querySingle("SELECT COUNT(*) FROM events WHERE kind='{$kind}' AND substr(ts,1,10)='{$today}'") ?? 0);
        $db->exec("INSERT INTO counters(key,value,updated) VALUES('{$key}',{$n},'{$now}')
                   ON CONFLICT(key) DO UPDATE SET value={$n},updated='{$now}'");
    }
    foreach (['inbound_today','outbound_today','car_today'] as $key) {
        $db->exec("INSERT INTO counters(key,value,updated) VALUES('{$key}',0,'{$now}')
                   ON CONFLICT(key) DO NOTHING");
    }
}

// ── Seeded event count ────────────────────────────────────────────────────────

function seed_count($dbPath) {
    if (!file_exists($dbPath)) return 0;
    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
        $n = (int)($db->querySingle("SELECT COUNT(*) FROM events WHERE json_extract(data,'$.seeded')=1") ?? 0);
        $db->close();
        return $n;
    } catch (\Throwable $e) { return 0; }
}

function seed_real_count($dbPath) {
    if (!file_exists($dbPath)) return 0;
    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
        $n = (int)($db->querySingle("SELECT COUNT(*) FROM events WHERE json_extract(data,'$.seeded') IS NULL OR json_extract(data,'$.seeded')!=1") ?? 0);
        $db->close();
        return $n;
    } catch (\Throwable $e) { return 0; }
}

// ── POST handlers ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $action = trim($_POST['action'] ?? '');
    $cfg    = seed_load_cfg($CFG_PATH);
    $labelTow  = $cfg['direction']['label_toward'] ?? 'Inbound';
    $labelAway = $cfg['direction']['label_away']   ?? 'Outbound';

    try {
        $db = seed_open_rw($DB_PATH);

        // ── SEED ─────────────────────────────────────────────────────────────
        if ($action === 'seed') {
            $days    = 45;
            $today   = new DateTime();
            $inserted = 0;

            // Pre-compile insert statement once
            $ins = $db->prepare(
                "INSERT INTO events(ts,kind,data) VALUES(:ts,:kind,:data)"
            );

            $db->exec('BEGIN');
            for ($i = $days; $i >= 0; $i--) {
                $date = clone $today;
                $date->modify("-{$i} days");
                $y   = (int)$date->format('Y');
                $mo  = (int)$date->format('m');
                $d   = (int)$date->format('d');
                $dow = (int)$date->format('N'); // 1=Mon … 7=Sun
                $isWeekend = $dow >= 6;

                // Ramp factor: 0.25 at start → 1.0 at day 0
                // Simulates season building toward Christmas
                $ramp = 0.25 + 0.75 * (($days - $i) / $days);

                // ── Daily volumes ────────────────────────────────────────────
                $baseLetters = $isWeekend ? 18 : 9;
                $baseCars    = $isWeekend ? 80 : 40;

                $jitter = fn($lo, $hi) => $lo + mt_rand(0, $hi - $lo);

                $numLetters   = (int)round($baseLetters * $ramp * (0.6 + 0.8 * (mt_rand(0, 1000) / 1000)));
                $numDonations = (int)round($numLetters  * (mt_rand(5, 18) / 100)); // ~5–18% rate
                $numCars      = (int)round($baseCars    * $ramp * (0.7 + 0.6 * (mt_rand(0, 1000) / 1000)));
                $numInbound   = (int)round($numCars * (0.45 + 0.10 * (mt_rand(0, 1000) / 1000)));
                $numOutbound  = $numCars - $numInbound;

                // Schedule window for letters/donations: 16:00–22:00 (6-hour window)
                $winStart = 16 * 3600;
                $winEnd   = 22 * 3600 - 1;
                // Cars use a slightly wider window: 14:00–23:00
                $carStart = 14 * 3600;
                $carEnd   = 23 * 3600 - 1;

                $mkTs = function(int $winS, int $winE) use ($y, $mo, $d): string {
                    $secs = mt_rand($winS, $winE);
                    $ts   = mktime(intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60, $mo, $d, $y);
                    return date('Y-m-d\TH:i:sP', $ts);
                };

                // Letters
                for ($j = 0; $j < $numLetters; $j++) {
                    $ins->bindValue(':ts',   $mkTs($winStart, $winEnd));
                    $ins->bindValue(':kind', 'letter');
                    $ins->bindValue(':data', json_encode(['seeded' => 1, 'clip' => 'sample_letter.mp4']));
                    $ins->execute();
                    $inserted++;
                }

                // Donations
                for ($j = 0; $j < $numDonations; $j++) {
                    $ins->bindValue(':ts',   $mkTs($winStart, $winEnd));
                    $ins->bindValue(':kind', 'donation');
                    $ins->bindValue(':data', json_encode(['seeded' => 1, 'clip' => 'sample_donation.mp4']));
                    $ins->execute();
                    $inserted++;
                }

                // Cars — inbound
                for ($j = 0; $j < $numInbound; $j++) {
                    $ins->bindValue(':ts',   $mkTs($carStart, $carEnd));
                    $ins->bindValue(':kind', 'car');
                    $ins->bindValue(':data', json_encode(['seeded' => 1, 'dir' => $labelTow, 'dir_seq' => 'AB']));
                    $ins->execute();
                    $inserted++;
                }

                // Cars — outbound
                for ($j = 0; $j < $numOutbound; $j++) {
                    $ins->bindValue(':ts',   $mkTs($carStart, $carEnd));
                    $ins->bindValue(':kind', 'car');
                    $ins->bindValue(':data', json_encode(['seeded' => 1, 'dir' => $labelAway, 'dir_seq' => 'BA']));
                    $ins->execute();
                    $inserted++;
                }
            }
            $db->exec('COMMIT');

            seed_recalc_counters($db);
            $db->close();

            echo json_encode([
                'status'   => 'OK',
                'inserted' => $inserted,
                'message'  => "{$inserted} sample events generated across 45 days. All tagged seeded=1 for safe removal.",
            ]);

        // ── CLEAR ────────────────────────────────────────────────────────────
        } elseif ($action === 'clear') {
            $db->exec("DELETE FROM events WHERE json_extract(data,'$.seeded')=1");
            $deleted = $db->changes();
            seed_recalc_counters($db);
            $db->close();
            echo json_encode([
                'status'  => 'OK',
                'deleted' => $deleted,
                'message' => "{$deleted} sample event(s) removed. Only real events remain.",
            ]);

        } else {
            $db->close();
            echo json_encode(['status' => 'ERROR', 'message' => 'Unknown action']);
        }

    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// ── GET — HTML tool page ──────────────────────────────────────────────────────
$seededCount = seed_count($DB_PATH);
$realCount   = seed_real_count($DB_PATH);
$dbExists    = file_exists($DB_PATH);
?>

<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center gap-3 mb-3">
  <h2 class="mb-0">
    <i class="fas fa-fw fa-flask"></i> SLED Dev Tools &mdash; Sample Data
  </h2>
  <span class="badge bg-warning text-dark">Development Only</span>
</div>

<p class="text-muted mb-3">
  Populates the analytics dashboard with 45 days of realistic synthetic data so
  you can verify charts and counters before your display goes live. Every generated
  event is tagged <code>seeded:1</code> internally — real events you create while
  testing are <strong>never affected</strong> by the Clear action.
</p>

<!-- ── Status card ──────────────────────────────────────────────────────────── -->
<div class="fppTableWrapper fppTableWrapperAsTable mb-3">
  <div class="fppTableContents p-3">
    <div class="row g-3">
      <div class="col-auto text-center" style="min-width:120px;">
        <div style="font-size:2.4rem;font-weight:700;color:<?= $seededCount > 0 ? '#ffce56' : '#666' ?>;"
             id="seedCountVal"><?= number_format($seededCount) ?></div>
        <div class="text-muted small">sample events</div>
      </div>
      <div class="col-auto text-center" style="min-width:120px;">
        <div style="font-size:2.4rem;font-weight:700;color:#4bc0c0;"
             id="realCountVal"><?= number_format($realCount) ?></div>
        <div class="text-muted small">real events</div>
      </div>
      <div class="col d-flex align-items-center">
        <?php if ($seededCount > 0): ?>
        <div class="text-muted small">
          <i class="fas fa-fw fa-circle-check text-warning"></i>
          Sample data is present &mdash; analytics charts are populated.
          Click <strong>Clear Sample Data</strong> before going live.
        </div>
        <?php elseif ($realCount > 0): ?>
        <div class="text-muted small">
          <i class="fas fa-fw fa-circle-check text-success"></i>
          No sample data. <?= $realCount ?> real event(s) recorded.
        </div>
        <?php else: ?>
        <div class="text-muted small">
          <i class="fas fa-fw fa-circle-xmark" style="color:#666;"></i>
          Database is empty<?= $dbExists ? '' : ' (will be created on first seed)' ?>.
          Generate sample data to preview the analytics dashboard.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Actions ──────────────────────────────────────────────────────────────── -->
<div class="fppTableWrapper fppTableWrapperAsTable mb-3">
  <div class="fppTableContents p-3">
    <div class="d-flex align-items-start gap-4 flex-wrap">

      <!-- Generate -->
      <div style="max-width:380px;">
        <strong><i class="fas fa-fw fa-wand-magic-sparkles"></i>&nbsp;Generate Sample Data</strong>
        <div class="text-muted small mt-1 mb-2">
          Creates ~45 days of letter, donation, and car events with realistic
          weekday/weekend patterns and a ramp-up curve toward the current date.
          Safe to run multiple times — each run adds another 45-day batch.
        </div>
        <button class="buttons btn-outline-light" id="seedBtn" onclick="sledSeed()">
          <i class="fas fa-fw fa-seedling"></i>&nbsp;Generate 45 Days of Sample Data
        </button>
      </div>

      <!-- Clear -->
      <div style="max-width:340px;">
        <strong><i class="fas fa-fw fa-trash-can"></i>&nbsp;Clear Sample Data</strong>
        <div class="text-muted small mt-1 mb-2">
          Removes <em>only</em> seeded events (those tagged <code>seeded:1</code>).
          Real events — letters or car triggers you fired during testing — are
          untouched. Run this before going live.
        </div>
        <button class="buttons btn-outline-light" id="clearBtn" onclick="sledClearSeed()"
                <?= $seededCount === 0 ? 'disabled' : '' ?>>
          <i class="fas fa-fw fa-broom"></i>&nbsp;Clear Sample Data
        </button>
      </div>

    </div>
  </div>
</div>

<!-- ── What gets generated ──────────────────────────────────────────────────── -->
<div class="fppTableWrapper fppTableWrapperAsTable mb-4">
  <div class="fppTableContents">
    <table class="fppSelectableRowTable" style="width:100%;">
      <thead>
        <tr>
          <th colspan="3" style="padding:8px;">
            <i class="fas fa-fw fa-circle-info"></i>&nbsp;Sample Data Profile
          </th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="padding:8px;width:180px;"><i class="fas fa-fw fa-envelope" style="color:#36a2eb;"></i>&nbsp;<strong>Letters</strong></td>
          <td style="padding:8px;">5–20 / weekday &nbsp;&bull;&nbsp; 10–35 / weekend</td>
          <td style="padding:8px;color:#666;">Between 16:00–22:00 local time</td>
        </tr>
        <tr>
          <td style="padding:8px;"><i class="fas fa-fw fa-gift" style="color:#ff6384;"></i>&nbsp;<strong>Donations</strong></td>
          <td style="padding:8px;">~5–18% of letter volume per day</td>
          <td style="padding:8px;color:#666;">Between 16:00–22:00 local time</td>
        </tr>
        <tr>
          <td style="padding:8px;"><i class="fas fa-fw fa-car" style="color:#4bc0c0;"></i>&nbsp;<strong>Cars</strong></td>
          <td style="padding:8px;">28–60 / weekday &nbsp;&bull;&nbsp; 55–120 / weekend &nbsp;&bull;&nbsp; ~50/50 in/out</td>
          <td style="padding:8px;color:#666;">Between 14:00–23:00 local time</td>
        </tr>
        <tr>
          <td style="padding:8px;"><i class="fas fa-fw fa-chart-line" style="color:#ffce56;"></i>&nbsp;<strong>Trend</strong></td>
          <td style="padding:8px;">Ramps from ~25% volume at day&nbsp;−45 to full volume today</td>
          <td style="padding:8px;color:#666;">Simulates season build-up</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Back link ─────────────────────────────────────────────────────────────── -->
<div>
  <a href="javascript:history.back()" class="text-muted small">
    <i class="fas fa-fw fa-arrow-left"></i> Back to Analytics
  </a>
</div>

<!-- ── JS ────────────────────────────────────────────────────────────────────── -->
<script>
const SLED_BASE = (typeof pluginBase !== 'undefined' && pluginBase)
  ? pluginBase : 'plugin.php?plugin=fpp-sled-mailbox&';
const SLED_PAGE = SLED_BASE + 'nopage=1&page=www/';

async function sledPost(action) {
  const fd = new FormData(); fd.append('action', action);
  const r  = await fetch(SLED_PAGE + 'seed.php', { method: 'POST', body: fd, cache: 'no-store' });
  return r.json();
}

function sledSetBusy(busy) {
  document.getElementById('seedBtn').disabled  = busy;
  document.getElementById('clearBtn').disabled = busy;
}

async function sledSeed() {
  sledSetBusy(true);
  try {
    const j = await sledPost('seed');
    $.jGrowl(j.message || (j.status==='OK' ? 'Sample data generated.' : 'Failed.'),
             { themeState: j.status==='OK' ? 'success' : 'danger' });
    if (j.status === 'OK') setTimeout(() => location.reload(), 800);
  } catch(e) {
    $.jGrowl('Error: ' + e.message, { themeState: 'danger' });
    sledSetBusy(false);
  }
}

async function sledClearSeed() {
  if (!confirm("Remove all sample (seeded) events?\n\nReal events you triggered during testing will not be affected.")) return;
  sledSetBusy(true);
  try {
    const j = await sledPost('clear');
    $.jGrowl(j.message || (j.status==='OK' ? 'Sample data cleared.' : 'Failed.'),
             { themeState: j.status==='OK' ? 'success' : 'danger' });
    if (j.status === 'OK') setTimeout(() => location.reload(), 800);
  } catch(e) {
    $.jGrowl('Error: ' + e.message, { themeState: 'danger' });
    sledSetBusy(false);
  }
}
</script>
