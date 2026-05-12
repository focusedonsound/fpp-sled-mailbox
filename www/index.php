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
    "car"   => [
      "sequence_window_s"  => 0.8,
      "cooldown_s"         => 1.5,
      "parked_timeout_s"   => 180,
      "direction_window_s" => 10.0,
    ],
    "mqtt"      => ["enabled" => false, "base" => "sled", "device_name" => "SLED Santa Mailbox"],
    "paths"     => ["videos" => "/home/fpp/media/videos"],
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

$cfg         = loadConfig($configFile);
$running     = daemonRunning();
$mediaFiles  = listMedia();
$serialPorts = listSerialPorts();
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
              <button type="button" class="buttons btn-outline-light btn-sm"
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
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="telemetry_opt_in"
                       id="telemetryOptIn" value="1"
                       <?php echo !empty($cfg['telemetry']['opt_in']) ? 'checked' : ''; ?> />
                <label class="form-check-label small" for="telemetryOptIn" style="cursor:pointer;">
                  Help improve this plugin by sharing anonymous usage stats
                  <span style="cursor:help; color:var(--bs-info);"
                        title="Sends once per day: plugin version, FPP version, Pi model, which features are enabled (on/off only), and lifetime event counts. No personal data is collected and no IP addresses are stored. This information is used to understand how the plugin is being used and to prioritize development.">
                    <i class="fas fa-circle-question fa-xs"></i>
                  </span>
                </label>
              </div>
            </td>
          </tr>
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

<?php include __DIR__ . '/radar_diag.php'; ?>

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
