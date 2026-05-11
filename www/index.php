<?php
$configFile = "/home/fpp/media/config/sled.json";

// ── Defaults ──────────────────────────────────────────────────────────────
function defaultCfg() {
  return [
    "enabled"  => true,
    "video"    => [
      "idle"           => "",
      "letter_clips"   => [],
      "donation_clips" => [],
      "play_timeout_s" => 65,
    ],
    "pins"      => ["letter" => 17, "donation" => ""],
    "letter"    => ["cooldown_s" => 3.0],
    "donation"  => ["cooldown_s" => 5.0],
    "schedule"  => ["start" => "16:00", "end" => "22:00"],
    "ld2410"    => [
      "enabled"  => false,
      "A"        => ["port" => "/dev/ttyUSB0", "min_energy" => 20],
      "B"        => ["port" => "/dev/ttyUSB1", "min_energy" => 20],
    ],
    "direction" => [
      "toward_reference" => "AB",
      "label_toward"     => "Inbound",
      "label_away"       => "Outbound",
    ],
    "car"   => ["sequence_window_s" => 0.8, "cooldown_s" => 1.5],
    "mqtt"  => ["enabled" => false, "base" => "sled", "device_name" => "SLED Santa Mailbox"],
    "paths" => ["videos" => "/home/fpp/media/videos"],
  ];
}

function loadConfig($path) {
  $cfg = defaultCfg();
  if (file_exists($path)) {
    $j = json_decode(@file_get_contents($path), true);
    if (is_array($j)) $cfg = array_replace_recursive($cfg, $j);
  }
  return $cfg;
}

function clipsToStr($arr) {
  return is_array($arr) ? implode(', ', $arr) : (string)$arr;
}

// ── Daemon status (graceful — posix may not be available) ─────────────────
function daemonRunning() {
  $pidFile = "/home/fpp/media/logs/sled_daemon.pid";
  if (!file_exists($pidFile)) return false;
  $pid = trim(@file_get_contents($pidFile));
  if (!$pid || !is_numeric($pid)) return false;
  if (function_exists('posix_kill')) return posix_kill((int)$pid, 0);
  // Fallback: check /proc
  return is_dir("/proc/$pid");
}

// ── FPP media file list ───────────────────────────────────────────────────
function listMedia() {
  $dirs = [
    "/home/fpp/media/videos",
  ];
  $out = [];
  foreach ($dirs as $d) {
    if (!is_dir($d)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d));
    foreach ($it as $f) {
      if ($f->isDir()) continue;
      if (preg_match('/\.(mp4|mkv|avi|mov|wmv|flv|webm)$/i', $f->getPathname())) {
        $rel = str_replace(["/home/fpp/media/videos/", "/home/fpp/media/music/"], "", $f->getPathname());
        $out[] = $rel;
      }
    }
  }
  sort($out);
  return $out;
}

$cfg       = loadConfig($configFile);
$running   = daemonRunning();
$mediaFiles = listMedia();
?>

<div class="d-flex justify-content-between align-items-center mb-2">
  <h2 class="mb-0">
    <i class="fas fa-fw fa-mailbox"></i> SLED &mdash; Smart Letters to Santa
  </h2>
  <div class="d-flex align-items-center gap-2">
    <span class="badge <?php echo $running ? 'bg-success' : 'bg-secondary'; ?> fs-6">
      <i class="fas fa-fw <?php echo $running ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
      <?php echo $running ? 'Car Counter Running' : 'Car Counter Stopped'; ?>
    </span>
    <a href="https://buymeacoffee.com/jm9pwtesct" target="_blank" rel="noopener noreferrer"
       class="buttons btn-outline-light">
      <i class="fas fa-fw fa-mug-hot"></i> Buy Me a Coffee
    </a>
    <a href="https://paypal.me/NScilingo" target="_blank" rel="noopener noreferrer"
       class="buttons btn-outline-light">
      <i class="fab fa-fw fa-paypal"></i> Donate via PayPal
    </a>
  </div>
</div>

<p class="text-muted">
  Configure video clips for letter and donation events. Wire sensor GPIO pins to
  <strong>FPP Commands</strong> using FPP&rsquo;s built-in GPIO plugin &mdash;
  <em>SLED &ndash; Trigger Letter</em> and <em>SLED &ndash; Trigger Donation</em>.
</p>

<form id="sledForm" onsubmit="return false;">

  <!-- ── Video Clips ──────────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable fppStickyTheadTable" style="width:100%;">
        <thead>
          <tr>
            <th colspan="2" style="padding:8px;">
              <i class="fas fa-fw fa-film"></i> Video Clips
              <span class="text-muted fw-normal small ms-2">
                Select from FPP media files &mdash; multiple clips play in round-robin order
              </span>
            </th>
          </tr>
        </thead>
        <tbody>

          <!-- Idle Video -->
          <tr>
            <td style="width:220px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-rotate"></i> Idle Loop (Screen Saver)</label>
              <div class="text-muted small">Loops continuously during the active schedule window</div>
            </td>
            <td style="padding:8px;">
              <select name="video_idle" class="form-control form-control-sm">
                <option value="">-- none --</option>
                <?php foreach ($mediaFiles as $f): ?>
                  <option value="<?php echo htmlspecialchars($f); ?>"
                    <?php echo ($cfg['video']['idle'] === $f) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($f); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>

          <!-- Letter Clips -->
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-envelope"></i> Letter Clips</label>
              <div class="text-muted small">Played in order when a letter drops</div>
            </td>
            <td style="padding:8px;">
              <div id="letterClipList">
                <?php
                $letterClips = $cfg['video']['letter_clips'];
                if (empty($letterClips)) $letterClips = [''];
                foreach ($letterClips as $idx => $clip): ?>
                <div class="d-flex gap-2 mb-1 clip-row" data-type="letter">
                  <select name="letter_clip[]" class="form-control form-control-sm">
                    <option value="">-- none --</option>
                    <?php foreach ($mediaFiles as $f): ?>
                      <option value="<?php echo htmlspecialchars($f); ?>"
                        <?php echo ($clip === $f) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($f); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" class="buttons btn-outline-light btn-sm"
                          onclick="sledRemoveClip(this)" title="Remove">
                    <i class="fas fa-fw fa-trash"></i>
                  </button>
                </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="buttons btn-outline-light btn-sm mt-1"
                      onclick="sledAddClip('letter')">
                <i class="fas fa-fw fa-plus"></i> Add Clip
              </button>
            </td>
          </tr>

          <!-- Donation Clips -->
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-gift"></i> Donation Clips</label>
              <div class="text-muted small">Leave empty to reuse letter clips</div>
            </td>
            <td style="padding:8px;">
              <div id="donationClipList">
                <?php
                $donationClips = $cfg['video']['donation_clips'];
                if (empty($donationClips)) $donationClips = [''];
                foreach ($donationClips as $idx => $clip): ?>
                <div class="d-flex gap-2 mb-1 clip-row" data-type="donation">
                  <select name="donation_clip[]" class="form-control form-control-sm">
                    <option value="">-- none --</option>
                    <?php foreach ($mediaFiles as $f): ?>
                      <option value="<?php echo htmlspecialchars($f); ?>"
                        <?php echo ($clip === $f) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($f); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" class="buttons btn-outline-light btn-sm"
                          onclick="sledRemoveClip(this)" title="Remove">
                    <i class="fas fa-fw fa-trash"></i>
                  </button>
                </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="buttons btn-outline-light btn-sm mt-1"
                      onclick="sledAddClip('donation')">
                <i class="fas fa-fw fa-plus"></i> Add Clip
              </button>
            </td>
          </tr>

          <!-- Clip timeout -->
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-hourglass-half"></i> Clip Timeout</label>
              <div class="text-muted small">Max seconds before force-stop</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;">
                <input type="number" class="form-control form-control-sm" name="play_timeout_s"
                       min="5" max="300" step="1"
                       value="<?php echo (int)$cfg['video']['play_timeout_s']; ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
          </tr>

        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Sensors / GPIO ──────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th colspan="4" style="padding:8px;">
              <i class="fas fa-fw fa-microchip"></i> Sensors &amp; Cooldowns
              <span class="text-muted fw-normal small ms-2">BCM pin numbers &mdash; wire to FPP GPIO plugin to fire SLED Commands</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-envelope"></i> Letter Pin</label>
              <div class="text-muted small">Active-low beam break</div>
            </td>
            <td style="width:180px; padding:8px;">
              <div class="input-group input-group-sm">
                <span class="input-group-text">GPIO</span>
                <input type="number" class="form-control form-control-sm" name="pin_letter"
                       min="1" max="40"
                       value="<?php echo (int)($cfg['pins']['letter'] ?? 17); ?>" />
              </div>
            </td>
            <td style="width:200px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-clock-rotate-left"></i> Cooldown</label>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;">
                <input type="number" class="form-control form-control-sm" name="letter_cooldown"
                       min="0" max="60" step="0.5"
                       value="<?php echo number_format((float)($cfg['letter']['cooldown_s'] ?? 3.0), 1); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-gift"></i> Donation Pin</label>
              <div class="text-muted small">Leave blank to disable</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm">
                <span class="input-group-text">GPIO</span>
                <input type="number" class="form-control form-control-sm" name="pin_donation"
                       min="1" max="40"
                       value="<?php echo $cfg['pins']['donation'] !== '' && $cfg['pins']['donation'] !== null ? (int)$cfg['pins']['donation'] : ''; ?>"
                       placeholder="none" />
              </div>
            </td>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-clock-rotate-left"></i> Cooldown</label>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;">
                <input type="number" class="form-control form-control-sm" name="donation_cooldown"
                       min="0" max="60" step="0.5"
                       value="<?php echo number_format((float)($cfg['donation']['cooldown_s'] ?? 5.0), 1); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Schedule ────────────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th colspan="4" style="padding:8px;">
              <i class="fas fa-fw fa-clock"></i> Idle Schedule
              <span class="text-muted fw-normal small ms-2">Screen Saver active window &mdash; events will play outside this window too. Screen will be blank prior to event (energy saver mode)</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0">Start Time</label>
            </td>
            <td style="width:180px; padding:8px;">
              <input type="time" class="form-control form-control-sm" name="schedule_start"
                     value="<?php echo htmlspecialchars($cfg['schedule']['start']); ?>" />
            </td>
            <td style="width:200px; padding:8px;">
              <label class="mb-0">End Time</label>
            </td>
            <td style="padding:8px;">
              <input type="time" class="form-control form-control-sm" name="schedule_end"
                     value="<?php echo htmlspecialchars($cfg['schedule']['end']); ?>" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Car Counter (LD2410) ────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th colspan="4" style="padding:8px;">
              <i class="fas fa-fw fa-car"></i> Car Counter (HLK-LD2410B Radar)
              <span class="text-muted fw-normal small ms-2">Optional &mdash; requires two USB radar sensors</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0">Enable Car Counter</label>
            </td>
            <td colspan="3" style="padding:8px;">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="ld2410_enabled" id="ld2410Enabled"
                       value="1" <?php echo !empty($cfg['ld2410']['enabled']) ? 'checked' : ''; ?>
                       onchange="sledToggle('radarRows', this.checked)" />
              </div>
            </td>
          </tr>
          <tbody id="radarRows" style="<?php echo empty($cfg['ld2410']['enabled']) ? 'opacity:0.4;' : ''; ?>">
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">Sensor A Port</label>
              <div class="text-muted small">First beam (road-side)</div>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="ld2410_port_a"
                     value="<?php echo htmlspecialchars($cfg['ld2410']['A']['port'] ?? '/dev/ttyUSB0'); ?>" />
            </td>
            <td style="padding:8px;">
              <label class="mb-0">Sensor B Port</label>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="ld2410_port_b"
                     value="<?php echo htmlspecialchars($cfg['ld2410']['B']['port'] ?? '/dev/ttyUSB1'); ?>" />
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">Direction Labels</label>
              <div class="text-muted small">Inbound / Outbound names</div>
            </td>
            <td style="padding:8px;">
              <div class="d-flex gap-2">
                <input type="text" class="form-control form-control-sm" name="label_toward"
                       placeholder="Inbound"
                       value="<?php echo htmlspecialchars($cfg['direction']['label_toward'] ?? 'Inbound'); ?>" />
                <input type="text" class="form-control form-control-sm" name="label_away"
                       placeholder="Outbound"
                       value="<?php echo htmlspecialchars($cfg['direction']['label_away'] ?? 'Outbound'); ?>" />
              </div>
            </td>
            <td style="padding:8px;">
              <label class="mb-0">Toward Reference</label>
            </td>
            <td style="padding:8px;">
              <select name="toward_ref" class="form-control form-control-sm">
                <option value="AB" <?php echo ($cfg['direction']['toward_reference'] ?? 'AB') === 'AB' ? 'selected' : ''; ?>>AB (A fires first)</option>
                <option value="BA" <?php echo ($cfg['direction']['toward_reference'] ?? 'AB') === 'BA' ? 'selected' : ''; ?>>BA (B fires first)</option>
              </select>
            </td>
          </tr>
          </tbody>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Save + Test ─────────────────────────────────────────────────── -->
  <div class="d-flex gap-2 mb-4">
    <button type="button" class="buttons btn-outline-light" onclick="sledSave()">
      <i class="fas fa-fw fa-save"></i> Save Settings
    </button>
    <button type="button" class="buttons btn-outline-light" onclick="sledTrigger('letter')">
      <i class="fas fa-fw fa-envelope"></i> Test Letter
    </button>
    <button type="button" class="buttons btn-outline-light" onclick="sledTrigger('donation')">
      <i class="fas fa-fw fa-gift"></i> Test Donation
    </button>
  </div>

</form>

<!-- ── FPP GPIO Wiring Guide ───────────────────────────────────────── -->
<div class="fppTableWrapper fppTableWrapperAsTable mb-3">
  <div class="fppTableContents">
    <table class="fppSelectableRowTable" style="width:100%;">
      <thead>
        <tr>
          <th colspan="3" style="padding:8px;">
            <i class="fas fa-fw fa-circle-info"></i> How to Wire GPIO Sensors in FPP
          </th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="padding:8px; width:30px; text-align:center;"><strong>1</strong></td>
          <td style="padding:8px;">Go to <strong>GPIO</strong> in the FPP menu</td>
          <td style="padding:8px; color:var(--bs-secondary);">Content Setup &rarr; GPIO</td>
        </tr>
        <tr>
          <td style="padding:8px; text-align:center;"><strong>2</strong></td>
          <td style="padding:8px;">Add a new Input using the <strong>Letter Pin</strong> number above</td>
          <td style="padding:8px; color:var(--bs-secondary);">Pull-up, active LOW (beam break)</td>
        </tr>
        <tr>
          <td style="padding:8px; text-align:center;"><strong>3</strong></td>
          <td style="padding:8px;">Set the action to <strong>FPP Command</strong> &rarr; <em>SLED &ndash; Trigger Letter</em></td>
          <td style="padding:8px; color:var(--bs-secondary);">Trigger on: Falling edge</td>
        </tr>
        <tr>
          <td style="padding:8px; text-align:center;"><strong>4</strong></td>
          <td style="padding:8px;">Repeat for the Donation Pin using <em>SLED &ndash; Trigger Donation</em></td>
          <td style="padding:8px; color:var(--bs-secondary);">Optional</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<script>
const SLED_BASE = (typeof pluginBase !== 'undefined' && pluginBase)
  ? pluginBase : 'plugin.php?plugin=fpp-sled-mailbox&';
const SLED_URL  = SLED_BASE + 'nopage=1&page=www/';

function sledUrl(p) { return SLED_URL + p; }

async function sledJson(res) {
  const t = await res.text();
  try { return JSON.parse(t); }
  catch(e) { return { status:'ERROR', message:'Bad response: ' + t.slice(0,200) }; }
}
function sledNotify(msg, isError) {
  $.jGrowl(msg, { themeState: isError ? 'danger' : 'success' });
}

// ── Save ────────────────────────────────────────────────────────────────
async function sledSave() {
  const fd  = new FormData(document.getElementById('sledForm'));
  const res = await fetch(sledUrl('save.php'), { method:'POST', body:fd, cache:'no-store' });
  const j   = await sledJson(res);
  sledNotify(j.message || (j.status==='OK' ? 'Saved.' : 'Save failed.'), j.status !== 'OK');
}

// ── Test trigger ─────────────────────────────────────────────────────────
async function sledTrigger(kind) {
  const res = await fetch(sledUrl('trigger.php') + '&action=' + kind, { cache:'no-store' });
  const j   = await sledJson(res);
  sledNotify(j.message || (j.status==='OK' ? 'Triggered.' : 'Failed.'), j.status !== 'OK');
}

// ── Dynamic clip rows ─────────────────────────────────────────────────────
const MEDIA_OPTIONS = <?php echo json_encode(array_map('htmlspecialchars', $mediaFiles)); ?>;

function sledAddClip(type) {
  const listId = type === 'letter' ? 'letterClipList' : 'donationClipList';
  const list   = document.getElementById(listId);
  const div    = document.createElement('div');
  div.className = 'd-flex gap-2 mb-1 clip-row';
  div.dataset.type = type;

  let opts = '<option value="">-- none --</option>';
  MEDIA_OPTIONS.forEach(f => { opts += `<option value="${f}">${f}</option>`; });

  div.innerHTML = `
    <select name="${type}_clip[]" class="form-control form-control-sm">${opts}</select>
    <button type="button" class="buttons btn-outline-light btn-sm"
            onclick="sledRemoveClip(this)" title="Remove">
      <i class="fas fa-fw fa-trash"></i>
    </button>`;
  list.appendChild(div);
}

function sledRemoveClip(btn) {
  const row = btn.closest('.clip-row');
  if (row) row.remove();
}

// ── Toggle opacity ───────────────────────────────────────────────────────
function sledToggle(id, enabled) {
  const el = document.getElementById(id);
  if (el) el.style.opacity = enabled ? '1' : '0.4';
}
</script>
