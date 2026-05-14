<?php
$configFile = "/home/fpp/media/config/sled.json";

// ── Defaults ──────────────────────────────────────────────────────────────
function defaultCfg() {
  return [
    "enabled"   => true,
    "playlists" => [
      "idle"          => "sled_idle",
      "letter"        => ["sled_letter"],
      "donation"      => [],
      "play_timeout_s"=> 120,
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
    "car"   => [
      "sequence_window_s"  => 0.8,
      "cooldown_s"         => 1.5,
      "parked_timeout_s"   => 180,
      "direction_window_s" => 10.0,
    ],
    "mqtt"      => ["enabled" => false, "base" => "sled", "device_name" => "SLED Santa Mailbox"],
    "telemetry" => ["opt_in" => true, "install_id" => ""],
  ];
}

// ── Serial port discovery ──────────────────────────────────────────────────
function listSerialPorts() {
  $ports = [];
  foreach ((array)@glob('/dev/ttyUSB*') as $p) $ports[] = $p;
  foreach ((array)@glob('/dev/ttyACM*') as $p) $ports[] = $p;
  sort($ports);
  return $ports;
}

function loadConfig($path) {
  $cfg = defaultCfg();
  if (file_exists($path)) {
    $j = json_decode(@file_get_contents($path), true);
    if (is_array($j)) $cfg = array_replace_recursive($cfg, $j);
  }
  return $cfg;
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

$cfg         = loadConfig($configFile);
$running     = daemonRunning();
$serialPorts = listSerialPorts();
?>
<style>
/* ── SLED plugin — explicit colours for FPP 9.x / 10.x compatibility ──────
   FPP 9.x uses Bootstrap 4 with its own dark theme; btn-outline-light and
   alert-warning can render as white-on-white / unreadable there.
   All colours here are hardcoded so they look the same on any FPP version.
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
.sled-btn-sm {
  padding: .2rem .5rem;
  font-size: .8rem;
  border-radius: .25rem;
}
/* Inline add/remove row buttons — slightly muted */
.sled-btn-row {
  background-color: #455a72;
  border-color: #556880;
}
.sled-btn-row:hover {
  background-color: #344657;
  border-color: #344657;
}
/* ── Warning banner — explicit amber/warm colours that override FPP 9 theme */
#sledDepBanner {
  background-color: #fff3cd !important;
  border: 1px solid #e6a817 !important;
  color: #5a3e05 !important;
  border-radius: .35rem;
}
#sledDepBanner strong {
  color: #3d2900 !important;
}
#sledDepBanner em {
  color: #5a3e05 !important;
  font-style: normal;
  font-weight: 500;
}
#sledDepBanner small {
  color: #7a5a1a !important;
}
#sledDepBanner code {
  background-color: #ffe69c;
  padding: .1rem .35rem;
  border-radius: .2rem;
  color: #3d2600 !important;
  font-size: .85em;
}
#sledDepBanner .btn-close {
  filter: brightness(0) saturate(100%) opacity(.5);
}
/* ── Mobile responsiveness ──────────────────────────────────────────────── */
@media (max-width: 640px) {
  /* Header: wrap onto two lines — title+pill first, donate buttons second */
  .sled-page-header { flex-wrap: wrap !important; row-gap: .5rem; }
  .sled-page-header > div:first-child { flex: 1 1 100%; min-width: 0; }
  .sled-page-header h2 { font-size: 1.05rem; }
  #sledDaemonPill { font-size: .7rem !important; padding: .2rem .45rem !important; }
  .sled-donate-row { flex-wrap: wrap; width: 100%; }

  /* 4-column table rows → 2 side-by-side pairs (label | input, label | input) */
  .sled-table-4col tbody tr { display: flex; flex-wrap: wrap; }
  .sled-table-4col tbody td {
    width: 50% !important;
    min-width: 0;
    box-sizing: border-box;
  }
  /* Cells that span full width (e.g. colspan, or the Radar Diagnostics button cell) */
  .sled-table-4col td[colspan],
  .sled-table-4col td.sled-full-row { width: 100% !important; }

  /* Prevent fixed-width input-groups from overflowing their 50%-wide cell */
  .sled-table-4col .input-group { max-width: 100% !important; }
  .sled-table-4col select { max-width: 100%; }

  /* GPIO wiring guide: hide the right-hand hint column */
  .sled-hint-col { display: none; }

  /* Daemon badge: ensure it aligns left when buttons wrap */
  #sledDaemonCtrl { row-gap: .4rem; }
  #sledDaemonBadge { align-self: flex-start; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-2 sled-page-header">
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <h2 class="mb-0">
      <i class="fas fa-fw fa-mailbox"></i> SLED &mdash; Smart Letters to Santa
    </h2>
    <!-- Daemon status pill — updated by JS on page load and after restart -->
    <span id="sledDaemonPill" style="
        display:inline-flex; align-items:center; gap:.3rem;
        padding:.25rem .65rem; border-radius:999px; font-size:.8rem; font-weight:600;
        background-color:<?php echo $running ? '#198754' : '#dc3545'; ?>;
        color:#fff; white-space:nowrap;">
      <i class="fas fa-fw fa-circle fa-xs"></i>
      <span id="sledDaemonPillText"><?php echo $running ? 'Daemon Running' : 'Daemon Stopped'; ?></span>
    </span>
  </div>
  <div class="d-flex align-items-center gap-2 sled-donate-row">
    <a href="https://buymeacoffee.com/jm9pwtesct" target="_blank" rel="noopener noreferrer"
       class="sled-btn">
      <i class="fas fa-fw fa-mug-hot"></i> Buy Me a Coffee
    </a>
    <a href="https://paypal.me/NScilingo" target="_blank" rel="noopener noreferrer"
       class="sled-btn">
      <i class="fas fa-fw fa-hand-holding-dollar"></i> Donate via PayPal
    </a>
  </div>
</div>

<!-- ── Dependency health banner (populated by JS on load) ─────────────── -->
<div id="sledDepBanner" class="alert alert-warning d-none mb-3" role="alert">
  <div class="d-flex justify-content-between align-items-start gap-3">
    <div>
      <strong><i class="fas fa-fw fa-triangle-exclamation"></i> Attention needed</strong>
      <div id="sledDepMsg" class="mt-1 small"></div>
    </div>
    <button type="button" class="btn-close flex-shrink-0" onclick="document.getElementById('sledDepBanner').classList.add('d-none')"></button>
  </div>
</div>

<p class="text-muted">
  The <strong>idle screen</strong> is an FPP playlist that loops during the active schedule window.
  <strong>Letter and donation events</strong> play individual video files in round-robin order —
  upload your videos via <strong>Content Setup &rarr; File Manager</strong>, then select them below.
  The SLED daemon monitors the sensor GPIO pins directly — no FPP GPIO configuration required.
</p>

<!-- Playlist name autocomplete populated by JS from /api/playlists -->
<datalist id="fppPlaylists"></datalist>
<!-- Media file list populated by JS from /api/media -->
<datalist id="fppMediaFiles"></datalist>

<form id="sledForm" onsubmit="return false;">
  <!-- Sentinel: tells save.php this form version has the enabled toggle.
       Without it, pBool("enabled") would silently return false for cached
       old-form submissions and disable auto-start. -->
  <input type="hidden" name="_sled_form_v2" value="1" />

  <!-- ── Plugin Settings ─────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th colspan="2" style="padding:8px;">
              <i class="fas fa-fw fa-gears"></i> Plugin Settings
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="width:220px; padding:8px;">
              <label class="mb-0" for="sledAutoStart">Auto-start Daemon on FPP Boot</label>
              <div class="text-muted small">Start the SLED daemon automatically whenever FPP starts</div>
            </td>
            <td style="padding:8px;">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="enabled" id="sledAutoStart"
                       value="1" <?php echo ($cfg['enabled'] ?? true) ? 'checked' : ''; ?> />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Idle Playlist ────────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th colspan="2" style="padding:8px;">
              <i class="fas fa-fw fa-rotate"></i> Idle Screen
              <span class="text-muted fw-normal small ms-2">
                FPP playlist that loops continuously during the active schedule window
              </span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="width:220px; padding:8px;">
              <label class="mb-0">Idle Playlist</label>
              <div class="text-muted small">Must be set to Repeat in FPP</div>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="pl_idle"
                     list="fppPlaylists" placeholder="sled_idle"
                     value="<?php echo htmlspecialchars($cfg['playlists']['idle'] ?? 'sled_idle'); ?>" />
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-hourglass-half"></i> Video Timeout</label>
              <div class="text-muted small">Max seconds to wait for a video to finish</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;">
                <input type="number" class="form-control form-control-sm" name="play_timeout_s"
                       min="5" max="600" step="1"
                       value="<?php echo (int)($cfg['playlists']['play_timeout_s'] ?? 120); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Event Videos ───────────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th colspan="2" style="padding:8px;">
              <i class="fas fa-fw fa-film"></i> Event Videos
              <span class="text-muted fw-normal small ms-2">
                Video files played when a letter or donation drops &mdash; multiple files play in round-robin order
              </span>
            </th>
          </tr>
        </thead>
        <tbody>

          <!-- Letter Videos -->
          <tr>
            <td style="width:220px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-envelope"></i> Letter Videos</label>
              <div class="text-muted small">Played in round-robin when a letter drops</div>
            </td>
            <td style="padding:8px;">
              <div id="letterVidList">
                <?php
                $letterVids = $cfg['videos']['letter'] ?? [];
                if (is_string($letterVids)) $letterVids = [$letterVids];
                if (empty($letterVids)) $letterVids = [''];
                foreach ($letterVids as $vf): ?>
                <div class="d-flex gap-2 mb-1 vid-row" data-type="letter">
                  <select class="form-select form-select-sm" name="letter_vid[]">
                    <option value="">— select a video file —</option>
                    <?php /* JS will populate options; pre-select saved value */ ?>
                  </select>
                  <input type="hidden" class="vid-saved" value="<?php echo htmlspecialchars($vf); ?>" />
                  <button type="button" class="sled-btn sled-btn-sm"
                          onclick="sledRemoveVid(this)" title="Remove">
                    <i class="fas fa-fw fa-trash"></i>
                  </button>
                </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="sled-btn sled-btn-sm mt-1"
                      onclick="sledAddVid('letter')">
                <i class="fas fa-fw fa-plus"></i> Add Video
              </button>
            </td>
          </tr>

          <!-- Donation Videos -->
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-gift"></i> Donation Videos</label>
              <div class="text-muted small">Leave empty to reuse letter videos</div>
            </td>
            <td style="padding:8px;">
              <div id="donationVidList">
                <?php
                $donationVids = $cfg['videos']['donation'] ?? [];
                if (is_string($donationVids)) $donationVids = [$donationVids];
                if (empty($donationVids)) $donationVids = [''];
                foreach ($donationVids as $vf): ?>
                <div class="d-flex gap-2 mb-1 vid-row" data-type="donation">
                  <select class="form-select form-select-sm" name="donation_vid[]">
                    <option value="">— select a video file —</option>
                  </select>
                  <input type="hidden" class="vid-saved" value="<?php echo htmlspecialchars($vf); ?>" />
                  <button type="button" class="sled-btn sled-btn-sm"
                          onclick="sledRemoveVid(this)" title="Remove">
                    <i class="fas fa-fw fa-trash"></i>
                  </button>
                </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="sled-btn sled-btn-sm mt-1"
                      onclick="sledAddVid('donation')">
                <i class="fas fa-fw fa-plus"></i> Add Video
              </button>
            </td>
          </tr>

        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Sensors / GPIO ──────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable sled-table-4col" style="width:100%;">
        <thead>
          <tr>
            <th colspan="4" style="padding:8px;">
              <i class="fas fa-fw fa-microchip"></i> Sensors &amp; Cooldowns
              <span class="text-muted fw-normal small ms-2">BCM pin numbers &mdash; the SLED daemon owns these pins directly; do not add them to FPP&rsquo;s GPIO Inputs</span>
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
      <table class="fppSelectableRowTable sled-table-4col" style="width:100%;">
        <thead>
          <tr>
            <th colspan="4" style="padding:8px;">
              <i class="fas fa-fw fa-clock"></i> Idle Schedule
              <span class="text-muted fw-normal small ms-2">Idle playlist active window &mdash; events trigger outside this window too; idle playlist is stopped when outside the window</span>
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
      <table class="fppSelectableRowTable sled-table-4col" style="width:100%;">
        <thead>
          <tr>
            <th colspan="4" style="padding:8px;">
              <i class="fas fa-fw fa-car"></i> Car Counter (HLK-LD2410B Radar)
              <span class="text-muted fw-normal small ms-2">Optional &mdash; requires two HLK-LD2410B sensors connected via USB</span>
            </th>
          </tr>
        </thead>
        <tbody>

          <!-- Enable toggle + Radar Diagnostics button -->
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0">Enable Car Counter</label>
              <div class="text-muted small">Activates both radar sensors</div>
            </td>
            <td style="padding:8px;">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="ld2410_enabled" id="ld2410Enabled"
                       value="1" <?php echo !empty($cfg['ld2410']['enabled']) ? 'checked' : ''; ?>
                       onchange="sledToggle('radarRows', this.checked)" />
              </div>
            </td>
            <td colspan="2" style="padding:8px; text-align:right;">
              <button type="button" class="sled-btn sled-btn-sm"
                      onclick="sledRadarOpen()"
                      title="Open the Radar Diagnostics panel to view live per-gate energy readings, tune sensitivity thresholds, and write settings directly to the radar hardware. Car counting on the selected radar is paused while the panel is open.">
                <i class="fas fa-fw fa-radar"></i> Radar Diagnostics
              </button>
            </td>
          </tr>

          <!-- Radar-dependent rows dimmed when disabled -->
          <tbody id="radarRows" style="<?php echo empty($cfg['ld2410']['enabled']) ? 'opacity:0.4;' : ''; ?>">

          <!-- Sensor ports -->
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">
                <i class="fas fa-fw fa-plug"></i> Sensor A Port
              </label>
              <div class="text-muted small">First beam — road-side (A→B = Inbound)</div>
            </td>
            <td style="padding:8px;">
              <?php
              $portA    = $cfg['ld2410']['A']['port'] ?? '/dev/ttyUSB0';
              $foundA   = in_array($portA, $serialPorts);
              $portsA   = $foundA ? $serialPorts : array_merge([$portA], $serialPorts);
              ?>
              <select name="ld2410_port_a" class="form-control form-control-sm"
                      title="USB serial port for Radar A. On Raspberry Pi, LD2410 modules typically appear as /dev/ttyUSB0 or /dev/ttyUSB1. Plug in one at a time and note which device appears.">
                <?php foreach ($portsA as $p): ?>
                <option value="<?php echo htmlspecialchars($p); ?>"
                  <?php echo ($portA === $p) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($p); ?>
                </option>
                <?php endforeach; ?>
                <?php if (empty($portsA)): ?>
                <option value="<?php echo htmlspecialchars($portA); ?>" selected>
                  <?php echo htmlspecialchars($portA); ?> (no ports detected)
                </option>
                <?php endif; ?>
              </select>
            </td>
            <td style="padding:8px;">
              <label class="mb-0">
                <i class="fas fa-fw fa-plug"></i> Sensor B Port
              </label>
              <div class="text-muted small">Second beam — mailbox-side</div>
            </td>
            <td style="padding:8px;">
              <?php
              $portB    = $cfg['ld2410']['B']['port'] ?? '/dev/ttyUSB1';
              $foundB   = in_array($portB, $serialPorts);
              $portsB   = $foundB ? $serialPorts : array_merge([$portB], $serialPorts);
              ?>
              <select name="ld2410_port_b" class="form-control form-control-sm"
                      title="USB serial port for Radar B. If both radars are plugged in simultaneously, USB0 and USB1 are assigned in order of detection — swap the physical USB cables if A and B are reversed.">
                <?php foreach ($portsB as $p): ?>
                <option value="<?php echo htmlspecialchars($p); ?>"
                  <?php echo ($portB === $p) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($p); ?>
                </option>
                <?php endforeach; ?>
                <?php if (empty($portsB)): ?>
                <option value="<?php echo htmlspecialchars($portB); ?>" selected>
                  <?php echo htmlspecialchars($portB); ?> (no ports detected)
                </option>
                <?php endif; ?>
              </select>
            </td>
          </tr>

          <!-- Software min-energy thresholds -->
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">
                <i class="fas fa-fw fa-bolt"></i> Min Energy — A
                <span style="cursor:help; color:var(--bs-info);" title="Software detection threshold for Radar A. The daemon checks max(moving_energy, stationary_energy) from the radar frame — if it is below this value the target is ignored, even if the hardware reports presence. Range: 1–100. Default: 20. Lower = more sensitive but more false triggers; raise if parked objects near the sensor cause false detections.">
                  <i class="fas fa-circle-question fa-xs"></i>
                </span>
              </label>
              <div class="text-muted small">Software presence filter (1–100)</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:130px;">
                <input type="number" class="form-control form-control-sm" name="ld2410_min_energy_a"
                       min="1" max="100" step="1"
                       value="<?php echo (int)($cfg['ld2410']['A']['min_energy'] ?? 20); ?>" />
                <span class="input-group-text">/100</span>
              </div>
            </td>
            <td style="padding:8px;">
              <label class="mb-0">
                <i class="fas fa-fw fa-bolt"></i> Min Energy — B
                <span style="cursor:help; color:var(--bs-info);" title="Same as Min Energy A but applied to Radar B. Both radars can be tuned independently.">
                  <i class="fas fa-circle-question fa-xs"></i>
                </span>
              </label>
              <div class="text-muted small">Software presence filter (1–100)</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:130px;">
                <input type="number" class="form-control form-control-sm" name="ld2410_min_energy_b"
                       min="1" max="100" step="1"
                       value="<?php echo (int)($cfg['ld2410']['B']['min_energy'] ?? 20); ?>" />
                <span class="input-group-text">/100</span>
              </div>
            </td>
          </tr>

          <!-- Car timing -->
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">
                <i class="fas fa-fw fa-stopwatch"></i> Sequence Window
                <span style="cursor:help; color:var(--bs-info);" title="Maximum time (in seconds) between Radar A triggering and Radar B triggering for the pair to be counted as the same car passing. If the two triggers are more than this many seconds apart they are treated as separate events. Typical value: 0.5–2 s for a driveway.">
                  <i class="fas fa-circle-question fa-xs"></i>
                </span>
              </label>
              <div class="text-muted small">A→B pair window (seconds)</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:130px;">
                <input type="number" class="form-control form-control-sm" name="sequence_window_s"
                       min="0.1" max="30" step="0.1"
                       value="<?php echo number_format((float)($cfg['car']['sequence_window_s'] ?? 0.8), 1); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
            <td style="padding:8px;">
              <label class="mb-0">
                <i class="fas fa-fw fa-clock-rotate-left"></i> Car Cooldown
                <span style="cursor:help; color:var(--bs-info);" title="Minimum time between car-count triggers. Prevents the same slow-moving vehicle from being counted twice. Typical value: 1–3 s.">
                  <i class="fas fa-circle-question fa-xs"></i>
                </span>
              </label>
              <div class="text-muted small">Min time between car events</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:130px;">
                <input type="number" class="form-control form-control-sm" name="car_cooldown_s"
                       min="0.5" max="30" step="0.5"
                       value="<?php echo number_format((float)($cfg['car']['cooldown_s'] ?? 1.5), 1); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
          </tr>

          <!-- Direction window + Parked car timeout -->
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">
                <i class="fas fa-fw fa-arrows-left-right"></i> Direction Window
                <span style="cursor:help; color:var(--bs-info);" title="How long (seconds) the system waits after the first radar trigger before deciding the car's direction. If the second radar fires within this window, direction (Inbound/Outbound) is determined. If only one radar fires, the car is counted as direction-unknown. Set longer than Sequence Window. Typical value: 5–15 s.">
                  <i class="fas fa-circle-question fa-xs"></i>
                </span>
              </label>
              <div class="text-muted small">Wait time for second radar (seconds)</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:130px;">
                <input type="number" class="form-control form-control-sm" name="direction_window_s"
                       min="1" max="60" step="0.5"
                       value="<?php echo number_format((float)($cfg['car']['direction_window_s'] ?? 10.0), 1); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
            <td style="padding:8px;">
              <label class="mb-0">
                <i class="fas fa-fw fa-square-parking"></i> Parked Car Timeout
                <span style="cursor:help; color:var(--bs-info);" title="If a radar reports continuous presence for longer than this many seconds, the car is assumed to be parked. Further car-count triggers from that radar are suppressed until the car leaves. The parked event is logged in the database. Typical value: 120–300 s (2–5 minutes).">
                  <i class="fas fa-circle-question fa-xs"></i>
                </span>
              </label>
              <div class="text-muted small">Suppress counting after this long</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:130px;">
                <input type="number" class="form-control form-control-sm" name="parked_timeout_s"
                       min="10" max="3600" step="10"
                       value="<?php echo (int)($cfg['car']['parked_timeout_s'] ?? 180); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
          </tr>

          <!-- Direction labels + Toward reference -->
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">
                <i class="fas fa-fw fa-signs-post"></i> Direction Labels
                <span style="cursor:help; color:var(--bs-info);" title="Custom names for the two traffic directions. These labels appear in the Analytics dashboard and MQTT messages.">
                  <i class="fas fa-circle-question fa-xs"></i>
                </span>
              </label>
              <div class="text-muted small">Toward / Away display names</div>
            </td>
            <td style="padding:8px;">
              <div class="d-flex gap-2">
                <input type="text" class="form-control form-control-sm" name="label_toward"
                       placeholder="Inbound"
                       title="Label for traffic moving toward the mailbox (e.g. Inbound, Arriving, To Mailbox)"
                       value="<?php echo htmlspecialchars($cfg['direction']['label_toward'] ?? 'Inbound'); ?>" />
                <input type="text" class="form-control form-control-sm" name="label_away"
                       placeholder="Outbound"
                       title="Label for traffic moving away from the mailbox (e.g. Outbound, Departing, From Mailbox)"
                       value="<?php echo htmlspecialchars($cfg['direction']['label_away'] ?? 'Outbound'); ?>" />
              </div>
            </td>
            <td style="padding:8px;">
              <label class="mb-0">
                <i class="fas fa-fw fa-compass"></i> Toward Reference
                <span style="cursor:help; color:var(--bs-info);" title="Which sensor fires first when a car is traveling toward the mailbox. AB = Radar A fires before Radar B (car approaches from the road past A then B). BA = the reverse. Physically: stand at the mailbox facing the road — if the nearest sensor is A, use AB.">
                  <i class="fas fa-circle-question fa-xs"></i>
                </span>
              </label>
            </td>
            <td style="padding:8px;">
              <select name="toward_ref" class="form-control form-control-sm"
                      title="Which radar fires first for Inbound traffic.">
                <option value="AB" <?php echo ($cfg['direction']['toward_reference'] ?? 'AB') === 'AB' ? 'selected' : ''; ?>>
                  AB &mdash; A fires first (Inbound)
                </option>
                <option value="BA" <?php echo ($cfg['direction']['toward_reference'] ?? 'AB') === 'BA' ? 'selected' : ''; ?>>
                  BA &mdash; B fires first (Inbound)
                </option>
              </select>
            </td>
          </tr>

          </tbody><!-- end radarRows -->
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Footer: Non-commercial notice + telemetry opt-in ────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th style="padding:8px;">
              <i class="fas fa-fw fa-heart"></i> About This Plugin
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="padding:12px 16px;">
              <p class="mb-3">
                SLED and Announcement Assistant are free for personal use.
                If you&rsquo;re using either plugin in a paid display, sponsored event, or
                professional environment &mdash; please consider
                <a href="https://paypal.me/NScilingo" target="_blank" rel="noopener noreferrer">
                  making a donation</a>.
                It helps keep development going.
              </p>
              <div class="d-flex align-items-center gap-2">
                <label class="form-check-label small mb-0" for="telemetryOptIn" style="cursor:pointer;">
                  Help improve this plugin by sharing anonymous usage stats
                  <span style="cursor:help; color:var(--bs-info);"
                        title="Sends once per day: plugin version, FPP version, Pi model, which features are enabled (on/off only), and lifetime event counts. No personal data is collected and no IP addresses are stored. This information is used to understand how the plugin is being used and to prioritize development.">
                    <i class="fas fa-circle-question fa-xs"></i>
                  </span>
                </label>
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="telemetry_opt_in"
                         id="telemetryOptIn" value="1"
                         <?php echo !empty($cfg['telemetry']['opt_in']) ? 'checked' : ''; ?> />
                </div>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Save + Test ──────────────────────────────────────────────────── -->
  <div class="d-flex flex-wrap gap-2 mb-2">
    <button type="button" class="sled-btn" onclick="sledSave()">
      <i class="fas fa-fw fa-save"></i> Save Settings
    </button>
    <button type="button" class="sled-btn" onclick="sledTrigger('letter')">
      <i class="fas fa-fw fa-envelope"></i> Test Letter
    </button>
    <button type="button" class="sled-btn" onclick="sledTrigger('donation')">
      <i class="fas fa-fw fa-gift"></i> Test Donation
    </button>
  </div>

  <!-- ── Daemon Controls ───────────────────────────────────────────────── -->
  <div id="sledDaemonCtrl" class="d-flex flex-wrap gap-2 mb-4 align-items-center">
    <button type="button" class="sled-btn" id="sledStartBtn"
            onclick="sledStartDaemon()"
            title="Start the SLED daemon"
            <?php echo $running ? 'disabled' : ''; ?>>
      <i class="fas fa-fw fa-play"></i> Start Daemon
    </button>
    <button type="button" class="sled-btn" id="sledStopBtn"
            onclick="sledStopDaemon()"
            title="Stop the SLED daemon without restarting it"
            <?php echo !$running ? 'disabled' : ''; ?>>
      <i class="fas fa-fw fa-stop"></i> Stop Daemon
    </button>
    <button type="button" class="sled-btn" id="sledRestartBtn"
            onclick="sledRestart()"
            title="Kill and restart the SLED daemon. Use this after saving settings or after a plugin update."
            <?php echo !$running ? 'disabled' : ''; ?>>
      <i class="fas fa-fw fa-rotate-right"></i> Restart Daemon
    </button>
    <button type="button" class="sled-btn sled-btn-sm" onclick="sledShowLog()"
            title="View the last 60 lines of the daemon log to diagnose startup errors">
      <i class="fas fa-fw fa-terminal"></i> Show Log
    </button>
    <span id="sledDaemonBadge"
          class="badge <?php echo $running ? 'bg-success' : 'bg-secondary'; ?> align-self-center"
          title="SLED daemon status">
      <i class="fas fa-fw <?php echo $running ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
      <?php echo $running ? 'Daemon running' : 'Daemon stopped'; ?>
    </span>
  </div>

</form>

<!-- ── Daemon Log viewer ─────────────────────────────────────────────── -->
<div class="fppTableWrapper fppTableWrapperAsTable mb-3" id="sledLogPanel" style="display:none;">
  <div class="fppTableContents">
    <table class="fppSelectableRowTable" style="width:100%;">
      <thead>
        <tr>
          <th style="padding:8px;">
            <div class="d-flex justify-content-between align-items-center">
              <span><i class="fas fa-fw fa-terminal"></i> Daemon Log (last 60 lines)</span>
              <div class="d-flex gap-2">
                <button type="button" class="sled-btn sled-btn-sm" onclick="sledLoadLog()">
                  <i class="fas fa-fw fa-rotate"></i> Refresh
                </button>
                <button type="button" class="sled-btn sled-btn-sm" onclick="document.getElementById('sledLogPanel').style.display='none'">
                  <i class="fas fa-fw fa-xmark"></i> Close
                </button>
              </div>
            </div>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="padding:0;">
            <pre id="sledLogOutput"
                 style="margin:0; padding:10px; max-height:320px; overflow-y:auto;
                        font-size:.78rem; line-height:1.4; white-space:pre-wrap;
                        background:#1a1a1a; color:#d0d0d0; border-radius:0 0 4px 4px;">Loading…</pre>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

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
          <td class="sled-hint-col" style="padding:8px; color:var(--bs-secondary);">Content Setup &rarr; GPIO</td>
        </tr>
        <tr>
          <td style="padding:8px; text-align:center;"><strong>2</strong></td>
          <td style="padding:8px;">Add a new Input using the <strong>Letter Pin</strong> number above</td>
          <td class="sled-hint-col" style="padding:8px; color:var(--bs-secondary);">Pull-up, active LOW (beam break)</td>
        </tr>
        <tr>
          <td style="padding:8px; text-align:center;"><strong>3</strong></td>
          <td style="padding:8px;">Set the action to <strong>FPP Command</strong> &rarr; <em>SLED &ndash; Trigger Letter</em></td>
          <td class="sled-hint-col" style="padding:8px; color:var(--bs-secondary);">Trigger on: Falling edge</td>
        </tr>
        <tr>
          <td style="padding:8px; text-align:center;"><strong>4</strong></td>
          <td style="padding:8px;">Repeat for the Donation Pin using <em>SLED &ndash; Trigger Donation</em></td>
          <td class="sled-hint-col" style="padding:8px; color:var(--bs-secondary);">Optional</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/radar_diag.php'; ?>

<script>
// ── Tooltip deduplication ─────────────────────────────────────────────────
// FPP loads plugin pages via AJAX into a persistent container. Bootstrap 5
// initialises tooltips on every [title] element after each injection.
// On the second+ visit the same DOM elements still carry their previous
// tooltip instances, so a new init triggers "doesn't allow more than one
// instance per element". Disposing stale instances here (before FPP's init
// runs) keeps the console clean.  First-load: getInstance → null, no-op.
(function sledDisposeStaleTooltips() {
  if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) return;
  document.querySelectorAll('[title]').forEach(function(el) {
    var t = bootstrap.Tooltip.getInstance(el);
    if (t) t.dispose();
  });
})();

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

// ── Restart daemon ────────────────────────────────────────────────────────
async function sledRestart() {
  sledNotify('Restarting daemon…', false);
  const res = await fetch(sledUrl('trigger.php') + '&action=restart', { cache:'no-store' });
  const j   = await sledJson(res);
  sledNotify(j.message || (j.status==='OK' ? 'Daemon restarting.' : 'Restart failed.'), j.status !== 'OK');
  setTimeout(sledRefreshDaemonStatus, 3000);
}

// ── Start daemon ──────────────────────────────────────────────────────────
async function sledStartDaemon() {
  sledNotify('Starting daemon…', false);
  const res = await fetch(sledUrl('trigger.php') + '&action=daemon_start', { cache:'no-store' });
  const j   = await sledJson(res);
  sledNotify(j.message || (j.status==='OK' ? 'Daemon starting.' : 'Start failed.'), j.status !== 'OK');
  setTimeout(sledRefreshDaemonStatus, 2500);
}

// ── Stop daemon ───────────────────────────────────────────────────────────
async function sledStopDaemon() {
  sledNotify('Stopping daemon…', false);
  const res = await fetch(sledUrl('trigger.php') + '&action=daemon_stop', { cache:'no-store' });
  const j   = await sledJson(res);
  sledNotify(j.message || (j.status==='OK' ? 'Daemon stopped.' : 'Stop failed.'), j.status !== 'OK');
  setTimeout(sledRefreshDaemonStatus, 1500);
}

// ── Refresh daemon status (pill + badge + buttons) ────────────────────────
async function sledRefreshDaemonStatus() {
  try {
    const r = await fetch(sledUrl('trigger.php') + '&action=depcheck', { cache:'no-store' });
    const d = await r.json();
    sledUpdateDaemonPill(d.daemon_up);
  } catch(e) {}
}

// ── Daemon log viewer ─────────────────────────────────────────────────────
async function sledLoadLog() {
  const pre = document.getElementById('sledLogOutput');
  if (pre) pre.textContent = 'Loading…';
  try {
    const res = await fetch(sledUrl('trigger.php') + '&action=logtail&lines=60', { cache:'no-store' });
    const j   = await res.json();
    if (pre) {
      if (!j.ok) {
        pre.textContent = j.note || 'Log not available.';
      } else {
        pre.textContent = j.lines.length ? j.lines.join('\n') : '(log is empty)';
        // Scroll to bottom
        pre.scrollTop = pre.scrollHeight;
      }
    }
  } catch(e) {
    if (pre) pre.textContent = 'Failed to fetch log: ' + e;
  }
}

function sledShowLog() {
  const panel = document.getElementById('sledLogPanel');
  if (!panel) return;
  panel.style.display = '';
  sledLoadLog();
  panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Populate FPP playlist autocomplete ───────────────────────────────────
(async function sledLoadPlaylists() {
  try {
    const res  = await fetch('/api/playlists', { cache: 'no-store' });
    const data = await res.json();
    const dl   = document.getElementById('fppPlaylists');
    if (!dl) return;
    // /api/playlists returns an array of playlist names (strings)
    const names = Array.isArray(data) ? data : (data.playlists || []);
    names.forEach(n => {
      const name = typeof n === 'string' ? n : (n.name || n.playlist || '');
      if (!name) return;
      const opt = document.createElement('option');
      opt.value = name;
      dl.appendChild(opt);
    });
  } catch(e) { /* not critical — text input still works */ }
})();

// ── Dynamic playlist rows (idle autocomplete) ─────────────────────────────
function sledAddPl(type) {
  const listId = type === 'letter' ? 'letterPlList' : 'donationPlList';
  const list   = document.getElementById(listId);
  if (!list) return;
  const div = document.createElement('div');
  div.className = 'd-flex gap-2 mb-1 pl-row';
  div.dataset.type = type;
  const ph = type === 'letter' ? 'sled_letter' : 'sled_donation (or leave blank)';
  div.innerHTML = `
    <input type="text" class="form-control form-control-sm" name="${type}_pl[]"
           list="fppPlaylists" placeholder="${ph}" />
    <button type="button" class="sled-btn sled-btn-sm"
            onclick="sledRemovePl(this)" title="Remove">
      <i class="fas fa-fw fa-trash"></i>
    </button>`;
  list.appendChild(div);
}

function sledRemovePl(btn) {
  const row = btn.closest('.pl-row');
  if (row) row.remove();
}

// ── Media file loader + video row helpers ─────────────────────────────────
let _sledMediaFiles = [];   // cached file list populated on page load

(async function sledLoadMedia() {
  try {
    const res  = await fetch('/api/media', { cache: 'no-store' });
    const data = await res.json();
    // FPP returns {media: [...]} or a flat array
    const files = Array.isArray(data) ? data
                : (Array.isArray(data.media) ? data.media : []);
    // Normalise to plain strings and keep only video-ish extensions
    const videoExts = /\.(mp4|mov|avi|mkv|m4v|mpg|mpeg|wmv|flv|webm|ts)$/i;
    _sledMediaFiles = files
      .map(f => typeof f === 'string' ? f : (f.name || f.filename || ''))
      .filter(f => f && videoExts.test(f))
      .sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase()));

    // Populate datalist for any text-based fallbacks
    const dl = document.getElementById('fppMediaFiles');
    if (dl) {
      _sledMediaFiles.forEach(f => {
        const opt = document.createElement('option');
        opt.value = f;
        dl.appendChild(opt);
      });
    }

    // Fill all existing <select name="*_vid[]"> with options,
    // then restore the saved value from the adjacent hidden input
    document.querySelectorAll('select[name="letter_vid[]"], select[name="donation_vid[]"]')
      .forEach(sel => sledPopulateVidSelect(sel));

  } catch(e) { /* non-critical */ }
})();

function sledPopulateVidSelect(sel, savedVal) {
  // Preserve existing selection or fall back to hidden saved value
  const saved = savedVal
    ?? sel.closest('.vid-row')?.querySelector('.vid-saved')?.value
    ?? '';
  // Clear & rebuild
  sel.innerHTML = '<option value="">— select a video file —</option>';
  _sledMediaFiles.forEach(f => {
    const opt = document.createElement('option');
    opt.value = f;
    opt.textContent = f;
    if (f === saved) opt.selected = true;
    sel.appendChild(opt);
  });
  // If saved value not in list (file renamed/moved), add it so it isn't lost
  if (saved && !_sledMediaFiles.includes(saved)) {
    const opt = document.createElement('option');
    opt.value = saved;
    opt.textContent = saved + ' (not found)';
    opt.selected = true;
    sel.appendChild(opt);
  }
}

function sledAddVid(type) {
  const listId = type === 'letter' ? 'letterVidList' : 'donationVidList';
  const list   = document.getElementById(listId);
  if (!list) return;
  const div = document.createElement('div');
  div.className = 'd-flex gap-2 mb-1 vid-row';
  div.dataset.type = type;
  div.innerHTML = `
    <select class="form-select form-select-sm" name="${type}_vid[]">
      <option value="">— select a video file —</option>
    </select>
    <input type="hidden" class="vid-saved" value="" />
    <button type="button" class="sled-btn sled-btn-sm"
            onclick="sledRemoveVid(this)" title="Remove">
      <i class="fas fa-fw fa-trash"></i>
    </button>`;
  list.appendChild(div);
  sledPopulateVidSelect(div.querySelector('select'));
}

function sledRemoveVid(btn) {
  const row = btn.closest('.vid-row');
  if (row) row.remove();
}

// ── Toggle opacity ───────────────────────────────────────────────────────
function sledToggle(id, enabled) {
  const el = document.getElementById(id);
  if (el) el.style.opacity = enabled ? '1' : '0.4';
}

// ── Daemon status pill + badge + button state ─────────────────────────────
function sledUpdateDaemonPill(isRunning) {
  // Header pill
  const pill = document.getElementById('sledDaemonPill');
  const text = document.getElementById('sledDaemonPillText');
  if (pill) pill.style.backgroundColor = isRunning ? '#198754' : '#dc3545';
  if (text) text.textContent = isRunning ? 'Daemon Running' : 'Daemon Stopped';

  // Badge in button bar
  const badge = document.getElementById('sledDaemonBadge');
  if (badge) {
    badge.className = 'badge ' + (isRunning ? 'bg-success' : 'bg-secondary') + ' align-self-center';
    badge.innerHTML = '<i class="fas fa-fw ' + (isRunning ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i> '
                    + (isRunning ? 'Daemon running' : 'Daemon stopped');
  }

  // Enable / disable daemon control buttons
  const startBtn   = document.getElementById('sledStartBtn');
  const stopBtn    = document.getElementById('sledStopBtn');
  const restartBtn = document.getElementById('sledRestartBtn');
  if (startBtn)   startBtn.disabled   = isRunning;
  if (stopBtn)    stopBtn.disabled    = !isRunning;
  if (restartBtn) restartBtn.disabled = !isRunning;
}

// ── Dependency health check ───────────────────────────────────────────────
(async function sledDepCheck() {
  try {
    const res = await fetch(sledUrl('trigger.php') + '&action=depcheck', { cache:'no-store' });
    const j   = await sledJson(res);

    sledUpdateDaemonPill(j.daemon_up);

    // Show banner only when something actionable is wrong.
    // pyserial is flagged in j.missing only when radar is enabled, so j.ok
    // will be true for the common case (no radar hardware).
    // daemon_up is shown separately since it is always relevant.
    const hasMissing = !j.ok;                  // required deps absent
    const daemonDown = !j.daemon_up;           // daemon not responding
    if (!hasMissing && !daemonDown) return;    // all good — banner stays hidden

    const banner = document.getElementById('sledDepBanner');
    const msgEl  = document.getElementById('sledDepMsg');
    if (!banner || !msgEl) return;

    const items = [];
    if (daemonDown) items.push('<strong>SLED daemon is not running</strong> — use the <strong>Start Daemon</strong> button below to start it, or check the log for errors');
    // Only mention pyserial if radar is enabled and it is genuinely missing
    if (j.radar_enabled && !j.pyserial)
      items.push('<strong>python3-serial</strong> is required for radar/USB communication');

    let html = items.join('<br>');
    if (j.fix_cmd) {
      html += '<br><br>To install the missing package, SSH into your FPP device and run:<br>'
            + '<code class="user-select-all">' + j.fix_cmd + '</code>'
            + '<br><small class="text-muted">Then click Restart Daemon to apply.</small>';
    }
    msgEl.innerHTML = html;
    banner.classList.remove('d-none');

    // Auto-open the log panel when daemon is down — helps diagnose startup crashes
    if (daemonDown) sledShowLog();
  } catch(e) {
    // Network error — don't show banner, not worth alarming the user
  }
})();
</script>
