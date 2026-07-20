<?php
// mqtt.php — SLED MQTT & Home Assistant Settings
// Loaded by FPP with wrap=1.
// POST action=save    → write mqtt section of sled.json (JSON response)
// POST action=test    → TCP reachability check on broker (JSON response)
// POST action=cleanup → write "cleanup" to command queue (JSON response)
ini_set('display_errors', '0');

$CFG_PATH = "/home/fpp/media/config/sled.json";
$CMD_FILE = "/home/fpp/media/logs/sled_trigger.cmd";
$PID_FILE = "/home/fpp/media/logs/sled_daemon.pid";

// ── Helpers ───────────────────────────────────────────────────────────────────

function respond($ok, $msg, $extra = []) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array_merge(['status' => $ok ? 'OK' : 'ERROR', 'message' => $msg], $extra));
    exit;
}

function load_cfg($path) {
    if (!file_exists($path)) return [];
    $j = @json_decode(file_get_contents($path), true);
    return is_array($j) ? $j : [];
}

function fpp_setting($name) {
    $ctx = stream_context_create(["http" => ["timeout" => 5, "ignore_errors" => true]]);
    $raw = @file_get_contents("http://localhost/api/settings/$name", false, $ctx);
    if ($raw === false) return "";
    $j = @json_decode($raw, true);
    if (is_array($j)) return (string)($j["value"] ?? $j[$name] ?? "");
    return trim($raw);
}

function read_fpp_mqtt() {
    return [
        'host'     => fpp_setting('MQTTServer'),
        'port'     => (int)(fpp_setting('MQTTPort') ?: 1883),
        'username' => fpp_setting('MQTTUsername'),
        'password' => fpp_setting('MQTTPassword'),
    ];
}

function daemon_running($pf) {
    if (!file_exists($pf)) return false;
    $pid = trim(@file_get_contents($pf));
    if (!$pid || !is_numeric($pid)) return false;
    return function_exists('posix_kill') ? posix_kill((int)$pid, 0) : is_dir("/proc/$pid");
}

// ── POST handlers ─────────────────────────────────────────────────────────────

ob_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // ── Save ─────────────────────────────────────────────────────────────────
    if ($action === 'save') {
        $cfg = load_cfg($CFG_PATH);

        $useFpp    = isset($_POST['use_fpp_settings']) && $_POST['use_fpp_settings'] === '1';
        $discovery = isset($_POST['discovery'])        && $_POST['discovery']        === '1';

        $cfg['mqtt']['use_fpp_settings'] = $useFpp;
        $cfg['mqtt']['host']             = trim($_POST['host']        ?? '');
        $cfg['mqtt']['port']             = (int)(trim($_POST['port']  ?? '1883') ?: 1883);
        $cfg['mqtt']['username']         = trim($_POST['username']    ?? '');
        $cfg['mqtt']['password']         = trim($_POST['password']    ?? '');
        $cfg['mqtt']['base']             = trim($_POST['base']        ?? 'sled') ?: 'sled';
        $cfg['mqtt']['discovery']        = $discovery;
        $cfg['mqtt']['device_name']      = trim($_POST['device_name'] ?? 'SLED Santa Mailbox') ?: 'SLED Santa Mailbox';
        $cfg['mqtt']['device_id']        = trim($_POST['device_id']   ?? '') ?: gethostname();

        $dir = dirname($CFG_PATH);
        if (!is_dir($dir))      respond(false, "Config dir missing: $dir");
        if (!is_writable($dir)) respond(false, "Config dir not writable");

        $tmp  = $CFG_PATH . '.tmp';
        $data = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (@file_put_contents($tmp, $data) === false) respond(false, 'Write failed');
        if (!@rename($tmp, $CFG_PATH))                 respond(false, 'Rename failed');

        respond(true, 'MQTT settings saved. Restart the daemon to apply.');
    }

    // ── Test connection ───────────────────────────────────────────────────────
    if ($action === 'test') {
        $cfg    = load_cfg($CFG_PATH);
        $useFpp = !empty($cfg['mqtt']['use_fpp_settings']);
        $fpp    = $useFpp ? read_fpp_mqtt() : [];

        $host = trim($_POST['host'] ?? '') ?: ($useFpp ? ($fpp['host'] ?? '') : '');
        $port = (int)(trim($_POST['port'] ?? '') ?: ($useFpp ? ($fpp['port'] ?? 1883) : 1883));

        if (!$host) respond(false, 'No broker host configured.');

        $fp = @fsockopen($host, $port, $errno, $errstr, 4);
        if ($fp) {
            fclose($fp);
            respond(true, "Broker reachable at {$host}:{$port}");
        } else {
            respond(false, "Cannot reach {$host}:{$port} — {$errstr} (errno {$errno})");
        }
    }

    // ── Remove HA entities ────────────────────────────────────────────────────
    if ($action === 'cleanup') {
        if (!daemon_running($PID_FILE)) {
            respond(false, 'Daemon is not running — start it first, then click Remove HA Entities.');
        }
        if (@file_put_contents($CMD_FILE, "cleanup\n") === false) {
            respond(false, 'Could not write to command queue file.');
        }
        respond(true, 'Cleanup command sent to daemon. HA entities will be removed within a few seconds.');
    }

    respond(false, 'Unknown action');
}

// ── GET — load config ─────────────────────────────────────────────────────────

$cfg     = load_cfg($CFG_PATH);
$fppMqtt = read_fpp_mqtt();
$running = daemon_running($PID_FILE);
$mqtt    = array_merge([
    'use_fpp_settings' => true,
    'host'             => '',
    'port'             => 1883,
    'username'         => '',
    'password'         => '',
    'base'             => 'sled',
    'discovery'        => true,
    'device_name'      => 'SLED Santa Mailbox',
    'device_id'        => gethostname(),
], $cfg['mqtt'] ?? []);

$fppConfigured = !empty($fppMqtt['host']);
$customActive  = !$mqtt['use_fpp_settings'];

// Effective broker (what the daemon actually uses)
$effHost = $customActive ? $mqtt['host'] : $fppMqtt['host'];
$effPort = $customActive ? $mqtt['port'] : $fppMqtt['port'];

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>

<style>
/* ── SLED plugin — explicit colours for FPP 9.x / 10.x compatibility ──────
   FPP 9.x uses Bootstrap 4 with its own dark theme; btn-outline-light and
   alert-warning can render as white-on-white / unreadable there.
   ───────────────────────────────────────────────────────────────────────── */
.sled-btn {
  display: inline-flex;
  align-items: center;
  gap: .3rem;
  padding: .35rem .8rem;
  font-size: .875rem;
  font-weight: 500;
  line-height: 1.5;
  text-align: center;
  white-space: nowrap;
  cursor: pointer;
  border: 1px solid #3a7fc1;
  border-radius: .3rem;
  text-decoration: none !important;
  background-color: #1a6eb5;
  color: #fff !important;
  transition: background-color .15s ease-in-out, border-color .15s;
  vertical-align: middle;
}
.sled-btn:hover, .sled-btn:focus {
  background-color: #155a94;
  border-color: #0e4370;
  color: #fff !important;
  text-decoration: none !important;
}
.sled-btn:disabled, .sled-btn.disabled {
  opacity: .55;
  cursor: not-allowed;
  pointer-events: none;
}
</style>

<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h2 class="mb-0">
    <i class="fas fa-fw fa-tower-broadcast"></i> SLED &mdash; MQTT &amp; Home Assistant
  </h2>
  <span class="badge <?= $running ? 'bg-success' : 'bg-secondary' ?> fs-6">
    <i class="fas fa-fw <?= $running ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
    <?= $running ? 'Daemon Running' : 'Daemon Stopped' ?>
  </span>
</div>

<p class="text-muted mb-3">
  SLED hooks into FPP&rsquo;s existing MQTT broker. Events (letter drops, donations,
  car passes) publish to <code><?= e($mqtt['base']) ?>/event/&hellip;</code>
  and counters to <code><?= e($mqtt['base']) ?>/status/&hellip;</code>.
  Home Assistant auto-discovery creates sensors and binary sensors automatically.
</p>

<form id="mqttForm" onsubmit="return false;">

  <!-- ── FPP Broker Info (read-only) ──────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th colspan="2" style="padding:8px;">
              <i class="fas fa-fw fa-server"></i> FPP MQTT Broker
              <span class="text-muted fw-normal small ms-2">
                Configured in FPP &rarr; Settings &rarr; Network &rarr; MQTT
              </span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="width:200px;padding:8px;"><strong>Broker</strong></td>
            <td style="padding:8px;">
              <?php if ($fppConfigured): ?>
                <span class="text-success"><i class="fas fa-fw fa-circle-check"></i></span>
                <code><?= e($fppMqtt['host']) ?>:<?= e($fppMqtt['port']) ?></code>
                <?php if ($fppMqtt['username']): ?>
                  &nbsp;&bull;&nbsp;<small class="text-muted">user: <?= e($fppMqtt['username']) ?></small>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted"><i class="fas fa-fw fa-circle-xmark"></i> Not configured in FPP</span>
                <small class="text-muted ms-2">
                  Go to FPP &rarr; Settings &rarr; Network &rarr; MQTT to set a broker.
                </small>
              <?php endif; ?>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── SLED Overrides ───────────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th colspan="2" style="padding:8px;">
              <i class="fas fa-fw fa-sliders"></i> SLED MQTT Configuration
            </th>
          </tr>
        </thead>
        <tbody>

          <!-- Use FPP settings toggle -->
          <tr>
            <td style="width:220px;padding:8px;">
              <label class="mb-0"><strong>Use FPP Broker Settings</strong></label>
              <div class="text-muted small">Inherit host/port/credentials from FPP</div>
            </td>
            <td style="padding:8px;">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="use_fpp_settings"
                       id="useFppToggle" value="1"
                       <?= $mqtt['use_fpp_settings'] ? 'checked' : '' ?>
                       onchange="sledMqttToggleFpp(this.checked)" />
              </div>
            </td>
          </tr>

          <!-- Custom credentials (hidden when using FPP settings) -->
          <tbody id="customRows" style="<?= $mqtt['use_fpp_settings'] ? 'opacity:0.4;pointer-events:none;' : '' ?>">
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">Broker Host</label>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:400px;">
                <input type="text" class="form-control" name="host" id="mqttHost"
                       value="<?= e($mqtt['host']) ?>" placeholder="e.g. 192.168.1.100 or homeassistant.local" />
                <input type="number" class="form-control" name="port" id="mqttPort"
                       value="<?= e($mqtt['port']) ?>" min="1" max="65535" style="max-width:90px;" />
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">Username</label>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="username"
                     value="<?= e($mqtt['username']) ?>" placeholder="optional" style="max-width:300px;" />
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">Password</label>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:300px;">
                <input type="password" class="form-control" name="password" id="mqttPass"
                       value="<?= e($mqtt['password']) ?>" placeholder="optional" />
                <button type="button" class="buttons btn-outline-light btn-sm"
                        onclick="sledMqttTogglePass()">
                  <i class="fas fa-fw fa-eye" id="mqttPassIcon"></i>
                </button>
              </div>
            </td>
          </tr>
          </tbody>

          <!-- Base topic -->
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><strong>Base Topic</strong></label>
              <div class="text-muted small">Root prefix for all SLED messages</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:300px;">
                <input type="text" class="form-control" name="base"
                       value="<?= e($mqtt['base']) ?>" />
                <span class="input-group-text text-muted">/event/… &nbsp; /status/…</span>
              </div>
            </td>
          </tr>

          <!-- HA Discovery -->
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><strong>HA Auto-Discovery</strong></label>
              <div class="text-muted small">Publishes config to homeassistant/…</div>
            </td>
            <td style="padding:8px;">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="discovery"
                       id="discoveryToggle" value="1"
                       <?= !empty($mqtt['discovery']) ? 'checked' : '' ?> />
              </div>
            </td>
          </tr>

          <!-- Device name / ID -->
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">Device Name</label>
              <div class="text-muted small">Shown in HA device list</div>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="device_name"
                     value="<?= e($mqtt['device_name']) ?>" style="max-width:300px;" />
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">Device ID</label>
              <div class="text-muted small">Unique identifier — used in HA entity IDs</div>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="device_id"
                     value="<?= e($mqtt['device_id']) ?>" style="max-width:300px;" />
            </td>
          </tr>

        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Topic reference ──────────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th colspan="2" style="padding:8px;">
              <i class="fas fa-fw fa-list"></i> Published Topics
              <span class="text-muted fw-normal small ms-2">
                Base: <code id="topicBaseRef"><?= e($mqtt['base']) ?></code>
              </span>
            </th>
          </tr>
        </thead>
        <tbody>
          <?php
          $b = $mqtt['base'];
          $topics = [
            ['event',  "{$b}/event/letter",          'JSON payload when a letter is dropped'],
            ['event',  "{$b}/event/donation",         'JSON payload when a donation is dropped'],
            ['event',  "{$b}/event/car",              'JSON payload on car detection (includes direction)'],
            ['status', "{$b}/status",                 '"online" / "offline" (retained LWT)'],
            ['status', "{$b}/status/letter_total",    'Lifetime letter count (retained)'],
            ['status', "{$b}/status/donation_total",  'Lifetime donation count (retained)'],
            ['status', "{$b}/status/letter_today",    "Today’s letter count"],
            ['status', "{$b}/status/donation_today",  "Today’s donation count"],
            ['status', "{$b}/status/car_total",       'Lifetime car count (retained)'],
            ['status', "{$b}/status/car_today",       "Today’s car count"],
            ['status', "{$b}/status/inbound_today",   "Today’s inbound count"],
            ['status', "{$b}/status/outbound_today",  "Today’s outbound count"],
            ['status', "{$b}/status/last_letter",     'ISO timestamp of last letter (retained)'],
            ['status', "{$b}/status/last_donation",   'ISO timestamp of last donation (retained)'],
            ['status', "{$b}/status/last_car_time",   'ISO timestamp of last car (retained)'],
            ['status', "{$b}/status/last_dir",        'Direction label of last car'],
          ];
          foreach ($topics as [$type, $topic, $desc]):
            $color = $type === 'event' ? '#36a2eb' : '#4bc0c0';
          ?>
          <tr>
            <td style="padding:5px 8px;width:50%;"><code><?= e($topic) ?></code></td>
            <td style="padding:5px 8px;color:#999;font-size:0.85rem;"><?= $desc ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Actions ──────────────────────────────────────────────────────────── -->
  <div class="d-flex gap-2 mb-3 flex-wrap">
    <button type="button" class="sled-btn" onclick="sledMqttSave()">
      <i class="fas fa-fw fa-save"></i> Save Settings
    </button>
    <button type="button" class="sled-btn" onclick="sledMqttTest()" id="testBtn">
      <i class="fas fa-fw fa-plug"></i> Test Connection
    </button>
    <button type="button" class="sled-btn ms-auto" id="cleanupBtn"
            onclick="sledMqttCleanup()" title="Remove SLED entities from Home Assistant"
            <?= $running ? '' : 'disabled' ?>>
      <i class="fas fa-fw fa-broom"></i> Remove HA Entities
    </button>
  </div>
  <?php if (!$running): ?>
  <div class="text-muted small mb-3">
    <i class="fas fa-fw fa-circle-info"></i>
    <em>Remove HA Entities</em> requires the daemon to be running —
    start it via <strong>FPP &rarr; Status/Control</strong>.
  </div>
  <?php endif; ?>

  <div class="text-muted small mb-4">
    <i class="fas fa-fw fa-circle-info"></i>
    <strong>Clean uninstall:</strong> click <em>Remove HA Entities</em> before deleting the plugin.
    This sends empty retained messages to all <code>homeassistant/…</code> discovery topics
    so Home Assistant automatically removes SLED from its device registry.
  </div>

</form>

<script>
const SLED_BASE = (typeof pluginBase !== 'undefined' && pluginBase)
  ? pluginBase : 'plugin.php?plugin=fpp-sled-mailbox&';
const SLED_PAGE = SLED_BASE + 'nopage=1&page=www/';

function sledMqttToggleFpp(on) {
  const rows = document.getElementById('customRows');
  rows.style.opacity        = on ? '0.4' : '1';
  rows.style.pointerEvents  = on ? 'none' : '';
}

function sledMqttTogglePass() {
  const inp  = document.getElementById('mqttPass');
  const icon = document.getElementById('mqttPassIcon');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'fas fa-fw fa-eye-slash';
  } else {
    inp.type = 'password';
    icon.className = 'fas fa-fw fa-eye';
  }
}

// Update topic reference table live when base topic input changes
document.querySelector('[name="base"]')?.addEventListener('input', function() {
  const el = document.getElementById('topicBaseRef');
  if (el) el.textContent = this.value || 'sled';
});

async function sledMqttPost(action, extra) {
  const fd = new FormData(document.getElementById('mqttForm'));
  fd.set('action', action);
  if (extra) Object.entries(extra).forEach(([k,v]) => fd.set(k, v));
  const res = await fetch(SLED_PAGE + 'mqtt.php', { method:'POST', body:fd, cache:'no-store' });
  return res.json();
}

async function sledMqttSave() {
  try {
    const j = await sledMqttPost('save');
    $.jGrowl(j.message, { themeState: j.status==='OK' ? 'success' : 'danger' });
  } catch(e) { $.jGrowl('Save failed: '+e.message, {themeState:'danger'}); }
}

async function sledMqttTest() {
  const btn = document.getElementById('testBtn');
  btn.disabled = true;
  try {
    const useFpp = document.getElementById('useFppToggle').checked;
    const host   = useFpp ? '' : (document.querySelector('[name="host"]')?.value || '');
    const port   = useFpp ? '' : (document.querySelector('[name="port"]')?.value || '');
    const j = await sledMqttPost('test', { host, port });
    $.jGrowl(j.message, { themeState: j.status==='OK' ? 'success' : 'danger' });
  } catch(e) { $.jGrowl('Test failed: '+e.message, {themeState:'danger'}); }
  btn.disabled = false;
}

async function sledMqttCleanup() {
  if (!confirm(
    'Remove SLED from Home Assistant?\n\n' +
    'This sends empty retained messages to all homeassistant/… discovery topics, ' +
    'causing HA to delete the SLED device and all its entities.\n\n' +
    'Use this before uninstalling the plugin.'
  )) return;
  const btn = document.getElementById('cleanupBtn');
  btn.disabled = true;
  try {
    const j = await sledMqttPost('cleanup');
    $.jGrowl(j.message, { themeState: j.status==='OK' ? 'success' : 'danger' });
  } catch(e) { $.jGrowl('Error: '+e.message, {themeState:'danger'}); }
  btn.disabled = false;
}
</script>
