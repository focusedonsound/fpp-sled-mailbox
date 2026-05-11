<?php
// status.php — SLED Analytics Dashboard
// Loaded by FPP with wrap=1 (FPP provides the page chrome)
ini_set('display_errors', '0');

$CFG_PATH = "/home/fpp/media/config/sled.json";
$PID_FILE = "/home/fpp/media/logs/sled_daemon.pid";

function sled_daemon_running($pf) {
    if (!file_exists($pf)) return false;
    $pid = trim(@file_get_contents($pf));
    if (!$pid || !is_numeric($pid)) return false;
    if (function_exists('posix_kill')) return posix_kill((int)$pid, 0);
    return is_dir("/proc/$pid");
}

$cfg = [];
if (file_exists($CFG_PATH)) {
    $j = @json_decode(file_get_contents($CFG_PATH), true);
    if (is_array($j)) $cfg = $j;
}

$running    = sled_daemon_running($PID_FILE);
$carEnabled = !empty($cfg['ld2410']['enabled']);
$labelIn    = htmlspecialchars($cfg['direction']['label_toward'] ?? 'Inbound');
$labelOut   = htmlspecialchars($cfg['direction']['label_away']   ?? 'Outbound');
?>

<!-- Chart.js — local copy installed by fpp_install.sh; CDN fallback if not yet present -->
<script src="plugin.php?plugin=fpp-sled-mailbox&file=js/chart.umd.min.js&nopage=1"
        onerror="var s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js';document.head.appendChild(s);console.warn('[SLED] Local Chart.js missing, falling back to CDN');"></script>

<!-- ── Page Header ──────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-3">
    <h2 class="mb-0">
      <i class="fas fa-fw fa-chart-line"></i> SLED Analytics
    </h2>
    <span id="daemonBadge" class="badge <?= $running ? 'bg-success' : 'bg-secondary' ?> fs-6">
      <i class="fas fa-fw <?= $running ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
      <?= $running ? 'Daemon Running' : 'Daemon Stopped' ?>
    </span>
  </div>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <small class="text-muted" id="sledLastRefresh">Loading&hellip;</small>
    <div class="input-group input-group-sm" style="width:140px;" title="Auto-refresh interval (seconds)">
      <span class="input-group-text"><i class="fas fa-fw fa-rotate"></i></span>
      <input type="number" id="sledInterval" class="form-control form-control-sm"
             min="10" max="3600" step="10" value="600"
             onchange="sledSaveInterval()" />
      <span class="input-group-text">s</span>
    </div>
    <div class="form-check form-switch mb-0 d-flex align-items-center gap-1">
      <input class="form-check-input" type="checkbox" id="sledAutoRefresh"
             onchange="sledToggleAuto()" checked />
      <label class="form-check-label small" for="sledAutoRefresh">Auto</label>
    </div>
    <button class="buttons btn-outline-light btn-sm" onclick="sledRefreshNow()" title="Refresh now">
      <i class="fas fa-fw fa-sync-alt"></i> Refresh
    </button>
  </div>
</div>

<!-- ── Summary Cards ────────────────────────────────────────────────────────── -->
<div class="row g-2 mb-3">
  <?php
  $cards = [
    ['id'=>'cardLetters',   'icon'=>'fa-envelope',   'label'=>'Letters Today',      'color'=>'#36a2eb'],
    ['id'=>'cardDonations', 'icon'=>'fa-gift',        'label'=>'Donations Today',    'color'=>'#ff6384'],
    ['id'=>'cardInbound',   'icon'=>'fa-arrow-right', 'label'=>$labelIn.' Today',    'color'=>'#4bc0c0'],
    ['id'=>'cardOutbound',  'icon'=>'fa-arrow-left',  'label'=>$labelOut.' Today',   'color'=>'#ffce56'],
  ];
  foreach ($cards as $c):
    $disabled = !$carEnabled && in_array($c['id'], ['cardInbound','cardOutbound']);
  ?>
  <div class="col-6 col-md-3">
    <div class="fppTableWrapper h-100 p-0">
      <div class="p-3 text-center" <?= $disabled ? 'style="opacity:0.4;"' : '' ?>>
        <div class="mb-1" style="color:<?= $c['color'] ?>;font-size:1.5rem;">
          <i class="fas fa-fw <?= $c['icon'] ?>"></i>
        </div>
        <div style="font-size:2.2rem;line-height:1;font-weight:700;color:<?= $c['color'] ?>;"
             id="<?= $c['id'] ?>Val">—</div>
        <div class="text-muted small mt-1"><?= $c['label'] ?></div>
        <div style="font-size:0.7rem;color:#666;margin-top:2px;" id="<?= $c['id'] ?>Sub">
          <?= $disabled ? 'Car Counter disabled' : '&mdash;' ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Time Range Selector ───────────────────────────────────────────────────── -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <span class="text-muted small me-1">View:</span>
  <?php
  $ranges = [
    ['label'=>'7 Days',    'days'=>7,   'period'=>'day'],
    ['label'=>'30 Days',   'days'=>30,  'period'=>'day'],
    ['label'=>'90 Days',   'days'=>90,  'period'=>'week'],
    ['label'=>'12 Months', 'days'=>365, 'period'=>'month'],
  ];
  foreach ($ranges as $r): ?>
  <button class="buttons btn-outline-light btn-sm sled-range-btn"
          data-days="<?= $r['days'] ?>" data-period="<?= $r['period'] ?>"
          onclick="sledSetRange(<?= $r['days'] ?>, '<?= $r['period'] ?>')">
    <?= $r['label'] ?>
  </button>
  <?php endforeach; ?>
</div>

<!-- ── Letters & Donations Chart (full width) ─────────────────────────────── -->
<div class="fppTableWrapper fppTableWrapperAsTable mb-3">
  <div class="fppTableContents p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <strong><i class="fas fa-fw fa-envelope"></i>&nbsp;Letters &amp; Donations</strong>
      <small class="text-muted" id="sledMailChartLabel">Last 30 Days</small>
    </div>
    <div style="position:relative;height:260px;">
      <canvas id="sledMailChart"></canvas>
    </div>
  </div>
</div>

<!-- ── Car Traffic + Activity Feed ──────────────────────────────────────────── -->
<div class="row g-3 mb-3">

  <!-- Car Chart -->
  <div class="col-12 col-lg-7">
    <div class="fppTableWrapper fppTableWrapperAsTable h-100">
      <div class="fppTableContents p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <strong><i class="fas fa-fw fa-car"></i>&nbsp;Car Traffic</strong>
          <?php if (!$carEnabled): ?>
          <span class="badge bg-secondary">Car Counter Not Enabled</span>
          <?php else: ?>
          <small class="text-muted" id="sledCarChartLabel">Last 30 Days</small>
          <?php endif; ?>
        </div>
        <div style="position:relative;height:220px;<?= !$carEnabled ? 'opacity:0.3;filter:grayscale(1);pointer-events:none;' : '' ?>">
          <canvas id="sledCarChart"></canvas>
        </div>
        <?php if (!$carEnabled): ?>
        <div class="text-muted text-center small mt-2">
          <i class="fas fa-fw fa-car-burst"></i>
          Enable the HLK-LD2410B radar in <strong>Settings</strong> to track car traffic
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Activity Feed -->
  <div class="col-12 col-lg-5">
    <div class="fppTableWrapper fppTableWrapperAsTable h-100">
      <div class="fppTableContents p-0">
        <div class="p-2 border-bottom d-flex justify-content-between align-items-center">
          <strong class="ps-1"><i class="fas fa-fw fa-list-ul"></i>&nbsp;Recent Activity</strong>
          <small class="text-muted pe-2" id="sledFeedCount"></small>
        </div>
        <div id="sledFeed" style="max-height:300px;overflow-y:auto;">
          <div class="text-muted p-3 text-center">
            <i class="fas fa-spinner fa-spin"></i> Loading&hellip;
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- ── Reset Controls ────────────────────────────────────────────────────────── -->
<div class="fppTableWrapper fppTableWrapperAsTable mb-4">
  <div class="fppTableContents p-3">
    <div class="d-flex align-items-start gap-3 flex-wrap">
      <div class="flex-grow-1">
        <strong><i class="fas fa-fw fa-trash-can"></i>&nbsp;Reset Today&rsquo;s Data</strong>
        <div class="text-muted small mt-1">
          Removes all events recorded today and recalculates all-time totals from the remaining history.
          Use this to clear test data before going live.
          Midnight resets happen automatically based on the Pi&rsquo;s local time.
        </div>
      </div>
      <button class="buttons btn-outline-light" id="sledResetBtn" onclick="sledConfirmReset()">
        <i class="fas fa-fw fa-rotate-left"></i>&nbsp;Reset Today
      </button>
    </div>
  </div>
</div>

<!-- ── Dev Tools footer ──────────────────────────────────────────────────────── -->
<div class="text-end mb-3">
  <a href="<?php
      $base = 'plugin.php?plugin=fpp-sled-mailbox&page=www/seed.php';
      echo htmlspecialchars($base);
  ?>" class="text-muted small">
    <i class="fas fa-fw fa-flask"></i> Dev Tools &mdash; Sample Data
  </a>
</div>

<!-- ── JavaScript ────────────────────────────────────────────────────────────── -->
<script>
// ── URL helpers ───────────────────────────────────────────────────────────────
const SLED_BASE = (typeof pluginBase !== 'undefined' && pluginBase)
  ? pluginBase : 'plugin.php?plugin=fpp-sled-mailbox&';
const SLED_PAGE = SLED_BASE + 'nopage=1&page=www/';
function sledUrl(p, qs) { return SLED_PAGE + p + (qs ? '&' + qs : ''); }

// ── State ──────────────────────────────────────────────────────────────────────
let sledRange  = { days: 30, period: 'day' };
let sledTimer  = null;
let sledMailCh = null;
let sledCarCh  = null;

// ── Chart.js dark-mode defaults ───────────────────────────────────────────────
Chart.defaults.color       = '#bbb';
Chart.defaults.borderColor = 'rgba(255,255,255,0.08)';
Chart.defaults.font.family = 'inherit';

// ── Label formatter ───────────────────────────────────────────────────────────
function sledFmtLabel(raw, period) {
  if (period === 'day') {
    const d = new Date(raw + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }
  if (period === 'week') {
    const parts = raw.split('-W');
    if (parts.length === 2) {
      const year = parseInt(parts[0], 10);
      const wk   = parseInt(parts[1], 10);
      const jan4 = new Date(year, 0, 4);
      const mon  = new Date(jan4.getTime() + (wk - 1) * 7 * 86400000);
      return mon.toLocaleDateString('en-US', { month: 'short' }) + ' W' + wk;
    }
    return raw;
  }
  // month: "2025-12" → "Dec '25"
  const mp = raw.split('-');
  const mo = new Date(parseInt(mp[0], 10), parseInt(mp[1], 10) - 1, 1);
  return mo.toLocaleDateString('en-US', { month: 'short' }) + " '" + mp[0].slice(2);
}

// ── Range helper text ─────────────────────────────────────────────────────────
function sledRangeText() {
  if (sledRange.period === 'day')   return `Last ${sledRange.days} Days`;
  if (sledRange.period === 'week')  return `Last ${Math.round(sledRange.days / 7)} Weeks`;
  return 'Last 12 Months';
}

// ── Letter + Donation chart ───────────────────────────────────────────────────
function sledBuildMailChart(data) {
  const labels = data.chart.labels.map(l => sledFmtLabel(l, data.period));
  if (sledMailCh) {
    sledMailCh.data.labels            = labels;
    sledMailCh.data.datasets[0].data  = data.chart.letters;
    sledMailCh.data.datasets[1].data  = data.chart.donations;
    sledMailCh.update('none');
  } else {
    sledMailCh = new Chart(document.getElementById('sledMailChart'), {
      type: 'bar',
      data: {
        labels,
        datasets: [
          { label: 'Letters',   data: data.chart.letters,
            backgroundColor: 'rgba(54,162,235,0.72)', borderColor: 'rgba(54,162,235,1)',
            borderWidth: 1, stack: 'mail' },
          { label: 'Donations', data: data.chart.donations,
            backgroundColor: 'rgba(255,99,132,0.72)', borderColor: 'rgba(255,99,132,1)',
            borderWidth: 1, stack: 'mail' },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend:  { labels: { color: '#ccc', boxWidth: 14 } },
          tooltip: { mode: 'index', intersect: false },
        },
        scales: {
          x: { stacked: true, ticks: { color: '#aaa', maxTicksLimit: 16, maxRotation: 45 },
               grid: { color: 'rgba(255,255,255,0.06)' } },
          y: { stacked: true, beginAtZero: true,
               ticks: { color: '#aaa', precision: 0 },
               grid:  { color: 'rgba(255,255,255,0.06)' } },
        },
      },
    });
  }
  const lbl = document.getElementById('sledMailChartLabel');
  if (lbl) lbl.textContent = sledRangeText();
}

// ── Car Traffic chart ─────────────────────────────────────────────────────────
function sledBuildCarChart(data) {
  const labels   = data.chart.labels.map(l => sledFmtLabel(l, data.period));
  const inLabel  = data.label_inbound  || 'Inbound';
  const outLabel = data.label_outbound || 'Outbound';
  const ptR      = data.chart.labels.length > 60 ? 0 : 3;

  if (sledCarCh) {
    sledCarCh.data.labels           = labels;
    sledCarCh.data.datasets[0].data = data.chart.inbound;
    sledCarCh.data.datasets[1].data = data.chart.outbound;
    sledCarCh.update('none');
  } else {
    sledCarCh = new Chart(document.getElementById('sledCarChart'), {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: inLabel,  data: data.chart.inbound,
            borderColor: 'rgba(75,192,192,1)', backgroundColor: 'rgba(75,192,192,0.1)',
            tension: 0.35, fill: true, pointRadius: ptR },
          { label: outLabel, data: data.chart.outbound,
            borderColor: 'rgba(255,206,86,1)', backgroundColor: 'rgba(255,206,86,0.1)',
            tension: 0.35, fill: true, pointRadius: ptR },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend:  { labels: { color: '#ccc', boxWidth: 14 } },
          tooltip: { mode: 'index', intersect: false },
        },
        scales: {
          x: { ticks: { color: '#aaa', maxTicksLimit: 14, maxRotation: 45 },
               grid: { color: 'rgba(255,255,255,0.06)' } },
          y: { beginAtZero: true, ticks: { color: '#aaa', precision: 0 },
               grid: { color: 'rgba(255,255,255,0.06)' } },
        },
      },
    });
  }
  const lbl = document.getElementById('sledCarChartLabel');
  if (lbl) lbl.textContent = sledRangeText();
}

// ── Summary cards ─────────────────────────────────────────────────────────────
function sledUpdateCards(data) {
  const t = data.today, a = data.alltime;
  const set = (id, val, sub) => {
    const ve = document.getElementById(id + 'Val');
    const se = document.getElementById(id + 'Sub');
    if (ve) ve.textContent = val;
    if (se && sub != null) se.textContent = sub;
  };
  set('cardLetters',   t.letters,
      a.letters   ? `${Number(a.letters).toLocaleString()} all-time`   : 'no events yet');
  set('cardDonations', t.donations,
      a.donations ? `${Number(a.donations).toLocaleString()} all-time` : 'no events yet');
  if (data.car_enabled) {
    set('cardInbound',  t.inbound,
        a.cars ? `${Number(a.cars).toLocaleString()} total cars` : 'no cars yet');
    set('cardOutbound', t.outbound, null);
  }
}

// ── Activity feed ─────────────────────────────────────────────────────────────
const SLED_KIND = {
  letter:   { icon: 'fa-envelope', color: '#36a2eb', label: 'Letter'   },
  donation: { icon: 'fa-gift',     color: '#ff6384', label: 'Donation' },
  car:      { icon: 'fa-car',      color: '#4bc0c0', label: 'Car'      },
};

function sledUpdateFeed(events) {
  const feed  = document.getElementById('sledFeed');
  const count = document.getElementById('sledFeedCount');
  if (!events || events.length === 0) {
    feed.innerHTML = '<div class="text-muted p-3 text-center"><i class="fas fa-fw fa-inbox"></i>&nbsp;No events yet</div>';
    if (count) count.textContent = '';
    return;
  }
  if (count) count.textContent = `${events.length} recent`;

  feed.innerHTML = events.map(ev => {
    const m   = SLED_KIND[ev.kind] || { icon: 'fa-circle', color: '#999', label: ev.kind };
    const ts  = (ev.ts || '').slice(0, 19).replace('T', ' ');
    const det = ev.dir  ? `<span class="text-muted ms-1">${ev.dir}</span>`
              : ev.clip ? `<span class="text-muted ms-1" style="font-size:0.75rem;">${ev.clip}</span>`
              : '';
    return `<div class="d-flex align-items-center gap-2 px-2 py-1 border-bottom" style="font-size:0.8rem;">
      <i class="fas fa-fw ${m.icon}" style="color:${m.color};"></i>
      <span class="fw-semibold" style="min-width:60px;">${m.label}</span>
      ${det}
      <span class="text-muted ms-auto" style="white-space:nowrap;font-size:0.72rem;">${ts}</span>
    </div>`;
  }).join('');
}

// ── Master update ─────────────────────────────────────────────────────────────
function sledApplyData(data) {
  sledUpdateCards(data);
  sledBuildMailChart(data);
  sledBuildCarChart(data);
  sledUpdateFeed(data.recent);
  const el = document.getElementById('sledLastRefresh');
  if (el) el.textContent = 'Updated ' + (data.refresh_ts || new Date().toLocaleTimeString());
}

// ── Error display ─────────────────────────────────────────────────────────────
function sledShowFetchError(msg) {
  const ts = document.getElementById('sledLastRefresh');
  if (ts) ts.textContent = '⚠ ' + msg;
  // Show message inside chart area so it's impossible to miss
  const mailWrap = document.getElementById('sledMailChart')?.parentElement;
  if (mailWrap && !mailWrap.querySelector('.sled-fetch-error')) {
    mailWrap.insertAdjacentHTML('afterbegin',
      `<div class="sled-fetch-error text-warning p-2 small border-bottom mb-2">
         <i class="fas fa-fw fa-triangle-exclamation"></i>
         <strong>Stats fetch failed:</strong> ${msg}
         &mdash; Check browser console (F12) for details.
       </div>`);
  }
  document.getElementById('sledFeed').innerHTML =
    `<div class="text-warning p-3 text-center small"><i class="fas fa-fw fa-triangle-exclamation"></i> ${msg}</div>`;
}

// ── Fetch ─────────────────────────────────────────────────────────────────────
async function sledRefreshNow() {
  clearTimeout(sledTimer);
  // Clear previous error banners
  document.querySelectorAll('.sled-fetch-error').forEach(el => el.remove());
  try {
    const qs   = `days=${sledRange.days}&period=${sledRange.period}`;
    const url  = sledUrl('stats.php', qs);
    const res  = await fetch(url, { cache: 'no-store' });
    if (!res.ok) throw new Error(`HTTP ${res.status} from stats.php`);
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch(je) {
      // Show first 120 chars of the bad response to help diagnose
      throw new Error(`stats.php returned non-JSON: "${text.slice(0, 120).replace(/</g,'&lt;')}"`);
    }
    if (data.error) throw new Error('stats.php DB error: ' + data.error);
    sledApplyData(data);
  } catch(e) {
    console.error('[SLED] Stats fetch failed:', e);
    sledShowFetchError(e.message);
  }
  sledScheduleNext();
}

function sledScheduleNext() {
  clearTimeout(sledTimer);
  if (!document.getElementById('sledAutoRefresh').checked) return;
  const secs = Math.max(10, parseInt(document.getElementById('sledInterval').value, 10) || 600);
  sledTimer = setTimeout(sledRefreshNow, secs * 1000);
}

// ── Controls ──────────────────────────────────────────────────────────────────
function sledSaveInterval() {
  localStorage.setItem('sledRefreshInterval',
    Math.max(10, parseInt(document.getElementById('sledInterval').value, 10) || 600));
  sledScheduleNext();
}

function sledToggleAuto() {
  const on = document.getElementById('sledAutoRefresh').checked;
  localStorage.setItem('sledAutoEnabled', on ? '1' : '0');
  if (on) sledScheduleNext(); else clearTimeout(sledTimer);
}

function sledSetRange(days, period) {
  sledRange = { days, period };
  document.querySelectorAll('.sled-range-btn').forEach(btn => {
    const active = +btn.dataset.days === days && btn.dataset.period === period;
    btn.style.fontWeight = active ? '700' : '';
    btn.style.borderColor = active ? '#36a2eb' : '';
    btn.style.color       = active ? '#36a2eb' : '';
  });
  sledRefreshNow();
}

// ── Reset ─────────────────────────────────────────────────────────────────────
async function sledConfirmReset() {
  if (!confirm(
    "Reset all of today's events?\n\n" +
    "This removes today's letter, donation, and car events and recalculates " +
    "all-time totals from remaining history.\n\n" +
    "Use this to clear test data before going live."
  )) return;

  const btn = document.getElementById('sledResetBtn');
  btn.disabled = true;
  try {
    const fd = new FormData(); fd.append('action', 'reset_day');
    const j  = await fetch(sledUrl('reset.php'), { method:'POST', body:fd, cache:'no-store' })
                     .then(r => r.json());
    $.jGrowl(j.message || (j.status==='OK' ? 'Reset complete.' : 'Reset failed.'),
             { themeState: j.status==='OK' ? 'success' : 'danger' });
    if (j.status === 'OK') sledRefreshNow();
  } catch(e) {
    $.jGrowl('Reset error: ' + e.message, { themeState: 'danger' });
  }
  btn.disabled = false;
}

// ── Init ──────────────────────────────────────────────────────────────────────
(function() {
  const iv = parseInt(localStorage.getItem('sledRefreshInterval') || '600', 10);
  const on = localStorage.getItem('sledAutoEnabled') !== '0';
  document.getElementById('sledInterval').value      = iv;
  document.getElementById('sledAutoRefresh').checked = on;
  sledSetRange(30, 'day');   // triggers initial fetch
})();
</script>
