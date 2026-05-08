<?php
$configFile = "/home/fpp/media/config/sled.json";

function loadConfig($path) {
  $defaults = [
    "enabled"  => true,
    "paths"    => ["videos" => "/home/fpp/media/plugins/fpp-sled-mailbox/videos"],
    "schedule" => ["start" => "16:00", "end" => "22:00"],
    "video"    => [
      "idle"           => "idle.mp4",
      "letter_clips"   => [],
      "donation_clips" => [],
      "play_timeout_s" => 65,
    ],
    "pins"      => ["letter" => 17, "donation" => null],
    "car"       => ["sequence_window_s" => 0.8, "cooldown_s" => 1.5],
    "letter"    => ["cooldown_s" => 3.0],
    "donation"  => ["cooldown_s" => 5.0],
    "direction" => [
      "toward_reference" => "AB",
      "label_toward"     => "Inbound",
      "label_away"       => "Outbound",
    ],
    "ld2410" => [
      "enabled"    => false,
      "min_energy" => 20,
      "A"          => ["port" => "/dev/ttyUSB0", "min_energy" => 20],
      "B"          => ["port" => "/dev/ttyUSB1", "min_energy" => 20],
    ],
    "dht11" => ["enabled" => false, "pin" => 4, "interval_s" => 60],
    "mqtt"  => [
      "use_fpp_settings" => true,
      "host"             => "",
      "port"             => 1883,
      "username"         => "",
      "password"         => "",
      "base"             => "sled",
      "discovery"        => true,
      "device_name"      => "SLED Santa Mailbox",
      "device_id"        => "sled_mailbox",
    ],
    "debug" => ["use_mock_inputs" => false],
  ];

  if (file_exists($path)) {
    $j = json_decode(@file_get_contents($path), true);
    if (is_array($j)) $defaults = array_replace_recursive($defaults, $j);
  }
  return $defaults;
}

function daemonStatus() {
  $pidFile = "/home/fpp/media/logs/sled_daemon.pid";
  if (!file_exists($pidFile)) return ["running" => false, "pid" => null];
  $pid = trim(@file_get_contents($pidFile));
  if (!$pid || !is_numeric($pid)) return ["running" => false, "pid" => null];
  $running = (posix_kill((int)$pid, 0) === true);
  return ["running" => $running, "pid" => (int)$pid];
}

$cfg = loadConfig($configFile);
$daemon = daemonStatus();
?>

<!-- ── Header ──────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <h2 class="mb-0">
    <i class="fas fa-fw fa-envelope-open-text"></i> SLED Santa Mailbox
  </h2>
  <div class="d-flex align-items-center gap-2">
    <!-- Daemon status badge -->
    <span id="daemonBadge" class="badge <?php echo $daemon['running'] ? 'bg-success' : 'bg-secondary'; ?> fs-6">
      <i class="fas fa-fw <?php echo $daemon['running'] ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
      <?php echo $daemon['running'] ? 'Daemon Running' : 'Daemon Stopped'; ?>
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
  Santa's Letter Express Delivery — sensor-driven video playback, car counting, and Home Assistant integration.
</p>

<!-- ── Tab Navigation ────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3" id="sledTabs">
  <li class="nav-item">
    <a class="nav-link active" id="tab-dashboard-link" href="#" onclick="sledTab('dashboard');return false;">
      <i class="fas fa-fw fa-chart-bar"></i> Dashboard
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" id="tab-config-link" href="#" onclick="sledTab('config');return false;">
      <i class="fas fa-fw fa-cog"></i> Configuration
    </a>
  </li>
</ul>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- DASHBOARD TAB                                                           -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div id="tab-dashboard">

  <!-- Live Trigger Buttons -->
  <div class="d-flex flex-wrap gap-2 mb-3">
    <button type="button" class="buttons btn-outline-light" style="min-width:180px; min-height:48px;"
            onclick="sledTrigger('letter')">
      <i class="fas fa-fw fa-envelope"></i> Trigger Letter
    </button>
    <button type="button" class="buttons btn-outline-light" style="min-width:180px; min-height:48px;"
            onclick="sledTrigger('donation')">
      <i class="fas fa-fw fa-gift"></i> Trigger Donation
    </button>
  </div>

  <!-- Stat Cards -->
  <div class="row g-3 mb-3" id="sledStats">
    <?php
    $statCards = [
      ["icon"=>"fa-envelope",      "id"=>"stat_letter_total",   "label"=>"Letters (Total)"],
      ["icon"=>"fa-gift",          "id"=>"stat_donation_total", "label"=>"Donations (Total)"],
      ["icon"=>"fa-car-side",      "id"=>"stat_car_total",      "label"=>"Cars (Total)"],
      ["icon"=>"fa-calendar-day",  "id"=>"stat_car_today",      "label"=>"Cars Today"],
      ["icon"=>"fa-arrow-right",   "id"=>"stat_inbound_today",  "label"=>"Inbound Today"],
      ["icon"=>"fa-arrow-left",    "id"=>"stat_outbound_today", "label"=>"Outbound Today"],
    ];
    foreach ($statCards as $c): ?>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="fppTableWrapper fppTableWrapperAsTable text-center p-3 h-100">
        <div style="font-size:2rem;" id="<?php echo $c['id']; ?>">—</div>
        <div class="text-muted small"><i class="fas fa-fw <?php echo $c['icon']; ?>"></i> <?php echo $c['label']; ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Last Event Row -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3 p-2">
    <div class="row g-2 text-center small">
      <div class="col-md-4">
        <div class="text-muted"><i class="fas fa-fw fa-envelope-open"></i> Last Letter</div>
        <div id="stat_last_letter">—</div>
      </div>
      <div class="col-md-4">
        <div class="text-muted"><i class="fas fa-fw fa-gift"></i> Last Donation</div>
        <div id="stat_last_donation">—</div>
      </div>
      <div class="col-md-4">
        <div class="text-muted"><i class="fas fa-fw fa-car"></i> Last Car</div>
        <div id="stat_last_car">—</div>
      </div>
    </div>
  </div>

  <!-- Recent Events Table -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable fppStickyTheadTable" style="width:100%;">
        <thead>
          <tr>
            <th style="padding:8px; width:160px;">Timestamp</th>
            <th style="padding:8px; width:100px;">Event</th>
            <th style="padding:8px;">Details</th>
          </tr>
        </thead>
        <tbody id="sledEventLog">
          <tr><td colspan="3" class="text-center text-muted p-3">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /#tab-dashboard -->

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- CONFIG TAB                                                              -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div id="tab-config" style="display:none;">
<form id="sledForm" onsubmit="return false;">

  <!-- ── General ────────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead><tr><th colspan="4" style="padding:8px;">
          <i class="fas fa-fw fa-sliders-h"></i> General
        </th></tr></thead>
        <tbody>
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-power-off"></i> Enabled</label>
              <div class="text-muted small">Run daemon on FPP boot</div>
            </td>
            <td style="padding:8px;">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="enabled" id="cfgEnabled"
                       value="1" <?php echo $cfg['enabled'] ? 'checked' : ''; ?> />
              </div>
            </td>
            <td style="width:200px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-folder-video"></i> Video Directory</label>
              <div class="text-muted small">Absolute path to video files</div>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="video_dir"
                     value="<?php echo htmlspecialchars($cfg['paths']['videos']); ?>" />
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-clock"></i> Schedule Start</label>
            </td>
            <td style="padding:8px;">
              <input type="time" class="form-control form-control-sm" name="schedule_start"
                     value="<?php echo htmlspecialchars($cfg['schedule']['start']); ?>" />
            </td>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-clock"></i> Schedule End</label>
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

  <!-- ── Video Clips ────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead><tr><th colspan="2" style="padding:8px;">
          <i class="fas fa-fw fa-film"></i> Video Clips
          <span class="text-muted fw-normal small ms-2">Filenames relative to Video Directory; separate with commas</span>
        </th></tr></thead>
        <tbody>
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-rotate"></i> Idle Loop</label>
              <div class="text-muted small">Plays continuously when active</div>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="video_idle"
                     value="<?php echo htmlspecialchars($cfg['video']['idle']); ?>" />
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-envelope"></i> Letter Clips</label>
              <div class="text-muted small">Played in round-robin order</div>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="letter_clips"
                     value="<?php echo htmlspecialchars(implode(', ', $cfg['video']['letter_clips'])); ?>"
                     placeholder="letter1.mp4, letter2.mp4" />
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-gift"></i> Donation Clips</label>
              <div class="text-muted small">Leave blank to reuse letter clips</div>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="donation_clips"
                     value="<?php echo htmlspecialchars(implode(', ', $cfg['video']['donation_clips'])); ?>"
                     placeholder="donation1.mp4" />
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-hourglass"></i> Clip Timeout</label>
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

  <!-- ── GPIO Pins ──────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead><tr><th colspan="4" style="padding:8px;">
          <i class="fas fa-fw fa-microchip"></i> GPIO Pins <span class="text-muted fw-normal small ms-2">(BCM numbering)</span>
        </th></tr></thead>
        <tbody>
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-envelope"></i> Letter Pin</label>
              <div class="text-muted small">Active-low beam break (pull-up)</div>
            </td>
            <td style="width:160px; padding:8px;">
              <div class="input-group input-group-sm">
                <span class="input-group-text">GPIO</span>
                <input type="number" class="form-control form-control-sm" name="pin_letter"
                       min="1" max="40" value="<?php echo (int)$cfg['pins']['letter']; ?>" />
              </div>
            </td>
            <td style="width:200px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-gift"></i> Donation Pin</label>
              <div class="text-muted small">Leave blank to disable</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;">
                <span class="input-group-text">GPIO</span>
                <input type="number" class="form-control form-control-sm" name="pin_donation"
                       min="1" max="40"
                       value="<?php echo $cfg['pins']['donation'] !== null ? (int)$cfg['pins']['donation'] : ''; ?>"
                       placeholder="none" />
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-clock-rotate-left"></i> Letter Cooldown</label>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;">
                <input type="number" class="form-control form-control-sm" name="letter_cooldown"
                       min="0" max="60" step="0.5"
                       value="<?php echo number_format((float)$cfg['letter']['cooldown_s'], 1); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
            <td style="padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-clock-rotate-left"></i> Donation Cooldown</label>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;">
                <input type="number" class="form-control form-control-sm" name="donation_cooldown"
                       min="0" max="60" step="0.5"
                       value="<?php echo number_format((float)$cfg['donation']['cooldown_s'], 1); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Car Radar (LD2410) ─────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead><tr><th colspan="4" style="padding:8px;">
          <i class="fas fa-fw fa-car"></i> Car Radar (HLK-LD2410B USB)
        </th></tr></thead>
        <tbody>
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0">Enable LD2410</label>
            </td>
            <td colspan="3" style="padding:8px;">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="ld2410_enabled" id="ld2410Enabled"
                       value="1" <?php echo $cfg['ld2410']['enabled'] ? 'checked' : ''; ?>
                       onchange="sledToggleRadar()" />
              </div>
            </td>
          </tr>
          <tr id="radarFields">
            <td style="padding:8px;">
              <label class="mb-0">Sensor A Port</label>
              <div class="text-muted small">Closer to road / first beam</div>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="ld2410_port_a"
                     value="<?php echo htmlspecialchars($cfg['ld2410']['A']['port'] ?? '/dev/ttyUSB0'); ?>"
                     placeholder="/dev/ttyUSB0" />
            </td>
            <td style="padding:8px;">
              <label class="mb-0">Sensor B Port</label>
              <div class="text-muted small">Second beam</div>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="ld2410_port_b"
                     value="<?php echo htmlspecialchars($cfg['ld2410']['B']['port'] ?? '/dev/ttyUSB1'); ?>"
                     placeholder="/dev/ttyUSB1" />
            </td>
          </tr>
          <tr id="radarFields2">
            <td style="padding:8px;">
              <label class="mb-0">Min Energy (A)</label>
              <div class="text-muted small">Trigger threshold 0–100</div>
            </td>
            <td style="padding:8px;">
              <input type="number" class="form-control form-control-sm" name="ld2410_min_a"
                     min="1" max="100"
                     value="<?php echo (int)($cfg['ld2410']['A']['min_energy'] ?? 20); ?>" />
            </td>
            <td style="padding:8px;">
              <label class="mb-0">Min Energy (B)</label>
            </td>
            <td style="padding:8px;">
              <input type="number" class="form-control form-control-sm" name="ld2410_min_b"
                     min="1" max="100"
                     value="<?php echo (int)($cfg['ld2410']['B']['min_energy'] ?? 20); ?>" />
            </td>
          </tr>
          <tr id="radarFields3">
            <td style="padding:8px;">
              <label class="mb-0">Sequence Window</label>
              <div class="text-muted small">A→B (or B→A) must occur within</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;">
                <input type="number" class="form-control form-control-sm" name="car_seq_window"
                       min="0.1" max="10" step="0.1"
                       value="<?php echo number_format((float)$cfg['car']['sequence_window_s'], 1); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
            <td style="padding:8px;">
              <label class="mb-0">Car Cooldown</label>
              <div class="text-muted small">Ignore re-triggers per car</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;">
                <input type="number" class="form-control form-control-sm" name="car_cooldown"
                       min="0" max="30" step="0.5"
                       value="<?php echo number_format((float)$cfg['car']['cooldown_s'], 1); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
          </tr>
          <tr id="radarFields4">
            <td style="padding:8px;">
              <label class="mb-0">Toward Reference</label>
              <div class="text-muted small">Which sequence = "Inbound"</div>
            </td>
            <td style="padding:8px;">
              <select name="toward_ref" class="form-control form-control-sm">
                <option value="AB" <?php echo ($cfg['direction']['toward_reference']==='AB') ? 'selected' : ''; ?>>AB (A fires first → Inbound)</option>
                <option value="BA" <?php echo ($cfg['direction']['toward_reference']==='BA') ? 'selected' : ''; ?>>BA (B fires first → Inbound)</option>
              </select>
            </td>
            <td style="padding:8px;">
              <label class="mb-0">Direction Labels</label>
            </td>
            <td style="padding:8px;">
              <div class="d-flex gap-2">
                <input type="text" class="form-control form-control-sm" name="label_toward" placeholder="Inbound"
                       value="<?php echo htmlspecialchars($cfg['direction']['label_toward']); ?>" />
                <input type="text" class="form-control form-control-sm" name="label_away" placeholder="Outbound"
                       value="<?php echo htmlspecialchars($cfg['direction']['label_away']); ?>" />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── DHT11 Sensor ──────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead><tr><th colspan="4" style="padding:8px;">
          <i class="fas fa-fw fa-thermometer-half"></i> Environment Sensor (DHT11)
        </th></tr></thead>
        <tbody>
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0">Enable DHT11</label>
              <div class="text-muted small">Temperature &amp; humidity</div>
            </td>
            <td style="padding:8px;">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="dht11_enabled"
                       value="1" <?php echo $cfg['dht11']['enabled'] ? 'checked' : ''; ?> />
              </div>
            </td>
            <td style="width:200px; padding:8px;">
              <label class="mb-0">DHT11 GPIO Pin</label>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;">
                <span class="input-group-text">GPIO</span>
                <input type="number" class="form-control form-control-sm" name="dht11_pin"
                       min="1" max="40" value="<?php echo (int)$cfg['dht11']['pin']; ?>" />
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">Read Interval</label>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;">
                <input type="number" class="form-control form-control-sm" name="dht11_interval"
                       min="10" max="3600" step="10"
                       value="<?php echo (int)$cfg['dht11']['interval_s']; ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
            <td colspan="2"></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── MQTT / Home Assistant ─────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead><tr><th colspan="4" style="padding:8px;">
          <i class="fas fa-fw fa-home"></i> MQTT / Home Assistant
        </th></tr></thead>
        <tbody>
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0">Use FPP MQTT Settings</label>
              <div class="text-muted small">Read host/user/pass from FPP</div>
            </td>
            <td colspan="3" style="padding:8px;">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="mqtt_use_fpp" id="mqttUseFpp"
                       value="1" <?php echo $cfg['mqtt']['use_fpp_settings'] ? 'checked' : ''; ?>
                       onchange="sledToggleMqtt()" />
              </div>
            </td>
          </tr>
          <tr id="mqttManualFields">
            <td style="padding:8px;">
              <label class="mb-0">MQTT Broker Host</label>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="mqtt_host"
                     value="<?php echo htmlspecialchars($cfg['mqtt']['host']); ?>"
                     placeholder="192.168.0.100" />
            </td>
            <td style="padding:8px;">
              <label class="mb-0">Port</label>
            </td>
            <td style="padding:8px;">
              <input type="number" class="form-control form-control-sm" name="mqtt_port"
                     min="1" max="65535" value="<?php echo (int)$cfg['mqtt']['port']; ?>"
                     style="max-width:120px;" />
            </td>
          </tr>
          <tr id="mqttManualFields2">
            <td style="padding:8px;">
              <label class="mb-0">Username</label>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="mqtt_username"
                     value="<?php echo htmlspecialchars($cfg['mqtt']['username']); ?>" />
            </td>
            <td style="padding:8px;">
              <label class="mb-0">Password</label>
            </td>
            <td style="padding:8px;">
              <input type="password" class="form-control form-control-sm" name="mqtt_password"
                     value="<?php echo htmlspecialchars($cfg['mqtt']['password']); ?>" />
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">Topic Base</label>
              <div class="text-muted small">e.g. <code>sled</code> → sled/state/…</div>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="mqtt_base"
                     value="<?php echo htmlspecialchars($cfg['mqtt']['base']); ?>" />
            </td>
            <td style="padding:8px;">
              <label class="mb-0">Device Name (HA)</label>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="mqtt_device_name"
                     value="<?php echo htmlspecialchars($cfg['mqtt']['device_name']); ?>" />
            </td>
          </tr>
          <tr>
            <td style="padding:8px;">
              <label class="mb-0">Device ID</label>
            </td>
            <td style="padding:8px;">
              <input type="text" class="form-control form-control-sm" name="mqtt_device_id"
                     value="<?php echo htmlspecialchars($cfg['mqtt']['device_id']); ?>" />
            </td>
            <td style="padding:8px;">
              <label class="mb-0">HA Discovery</label>
            </td>
            <td style="padding:8px;">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="mqtt_discovery"
                       value="1" <?php echo $cfg['mqtt']['discovery'] ? 'checked' : ''; ?> />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Debug ──────────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead><tr><th colspan="2" style="padding:8px;">
          <i class="fas fa-fw fa-bug"></i> Debug
        </th></tr></thead>
        <tbody>
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0">Use Mock Inputs</label>
              <div class="text-muted small">Keyboard-driven events (no hardware)</div>
            </td>
            <td style="padding:8px;">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="debug_mock"
                       value="1" <?php echo $cfg['debug']['use_mock_inputs'] ? 'checked' : ''; ?> />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="mb-4">
    <button type="button" class="buttons btn-outline-light" onclick="sledSave()">
      <i class="fas fa-fw fa-save"></i> Save Settings
    </button>
    <button type="button" class="buttons btn-outline-light ms-2" onclick="sledRestartDaemon()">
      <i class="fas fa-fw fa-rotate"></i> Restart Daemon
    </button>
  </div>

</form>
</div><!-- /#tab-config -->

<script>
const SLED_BASE =
  (typeof pluginBase !== 'undefined' && pluginBase)
    ? pluginBase
    : 'plugin.php?plugin=fpp-sled-mailbox&';

const SLED_URL = SLED_BASE + 'nopage=1&page=www/';

function sledUrl(rel) { return SLED_URL + rel; }

async function sledJson(res) {
  const text = await res.text();
  try { return JSON.parse(text); }
  catch (e) {
    return { status: 'ERROR', message: 'Non-JSON: ' + text.slice(0, 200) };
  }
}

function sledNotify(msg, isError) {
  $.jGrowl(msg, { themeState: isError ? 'danger' : 'success' });
}

// ── Tab switching ──────────────────────────────────────────────────────────
function sledTab(name) {
  document.getElementById('tab-dashboard').style.display = (name === 'dashboard') ? '' : 'none';
  document.getElementById('tab-config').style.display    = (name === 'config')    ? '' : 'none';
  document.getElementById('tab-dashboard-link').classList.toggle('active', name === 'dashboard');
  document.getElementById('tab-config-link').classList.toggle('active',    name === 'config');
  if (name === 'dashboard') sledRefresh();
}

// ── Dashboard refresh ──────────────────────────────────────────────────────
async function sledRefresh() {
  try {
    const res = await fetch(sledUrl('status.php'), { cache: 'no-store' });
    const j   = await sledJson(res);
    if (j.status !== 'OK') return;

    const c = j.counters || {};
    const setText = (id, v) => {
      const el = document.getElementById(id);
      if (el) el.textContent = (v !== undefined && v !== null) ? v : '—';
    };

    setText('stat_letter_total',   c.letter_total);
    setText('stat_donation_total', c.donation_total);
    setText('stat_car_total',      c.car_total);
    setText('stat_car_today',      c.car_today);
    setText('stat_inbound_today',  c.inbound_today);
    setText('stat_outbound_today', c.outbound_today);

    const fmt = iso => iso ? new Date(iso).toLocaleString() : '—';
    setText('stat_last_letter',   fmt(j.last_letter));
    setText('stat_last_donation', fmt(j.last_donation));
    setText('stat_last_car',      (j.last_car_time ? fmt(j.last_car_time) + ' · ' + (j.last_dir || '') : '—'));

    // Daemon badge
    const badge = document.getElementById('daemonBadge');
    if (badge) {
      const running = j.daemon_running;
      badge.className = 'badge ' + (running ? 'bg-success' : 'bg-secondary') + ' fs-6';
      badge.innerHTML = '<i class="fas fa-fw ' + (running ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i> '
                      + (running ? 'Daemon Running' : 'Daemon Stopped');
    }

    // Event log
    const tbody = document.getElementById('sledEventLog');
    if (tbody && Array.isArray(j.events)) {
      const icons = { letter: 'fa-envelope', donation: 'fa-gift', car: 'fa-car' };
      if (j.events.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted p-3">No events recorded yet.</td></tr>';
      } else {
        tbody.innerHTML = j.events.map(ev => {
          const icon  = icons[ev.kind] || 'fa-circle';
          const data  = ev.data || {};
          let detail  = '';
          if (ev.kind === 'car')      detail = (data.dir || '') + (data.dir_seq ? ' (' + data.dir_seq + ')' : '');
          else if (data.clip)         detail = data.clip;
          const ts = ev.ts ? new Date(ev.ts).toLocaleString() : ev.ts;
          return `<tr>
            <td style="padding:6px 8px;">${ts}</td>
            <td style="padding:6px 8px;"><i class="fas fa-fw ${icon}"></i> ${ev.kind}</td>
            <td style="padding:6px 8px;">${detail}</td>
          </tr>`;
        }).join('');
      }
    }
  } catch (e) {
    console.warn('[SLED] Refresh error:', e);
  }
}

// ── Save config ────────────────────────────────────────────────────────────
async function sledSave() {
  const form = document.getElementById('sledForm');
  const fd   = new FormData(form);
  const res  = await fetch(sledUrl('save.php'), { method: 'POST', body: fd, cache: 'no-store' });
  const j    = await sledJson(res);
  const ok   = j.status === 'OK';
  sledNotify(j.message || (ok ? 'Saved.' : 'Save failed.'), !ok);
}

// ── Manual trigger ─────────────────────────────────────────────────────────
async function sledTrigger(kind) {
  const res = await fetch(sledUrl('trigger.php') + '&action=' + encodeURIComponent(kind),
                          { cache: 'no-store' });
  const j   = await sledJson(res);
  const ok  = j.status === 'OK';
  sledNotify(j.message || (ok ? 'Triggered.' : 'Trigger failed.'), !ok);
  setTimeout(sledRefresh, 800);
}

// ── Restart daemon ─────────────────────────────────────────────────────────
async function sledRestartDaemon() {
  const res = await fetch(sledUrl('trigger.php') + '&action=restart', { cache: 'no-store' });
  const j   = await sledJson(res);
  const ok  = j.status === 'OK';
  sledNotify(j.message || (ok ? 'Restarting daemon…' : 'Restart failed.'), !ok);
  setTimeout(sledRefresh, 2000);
}

// ── UI toggle helpers ──────────────────────────────────────────────────────
function sledToggleRadar() {
  const enabled = document.getElementById('ld2410Enabled')?.checked;
  ['radarFields','radarFields2','radarFields3','radarFields4'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.opacity = enabled ? '1' : '0.4';
  });
}

function sledToggleMqtt() {
  const useFpp = document.getElementById('mqttUseFpp')?.checked;
  ['mqttManualFields','mqttManualFields2'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.opacity = useFpp ? '0.4' : '1';
  });
}

// ── Init ───────────────────────────────────────────────────────────────────
sledToggleRadar();
sledToggleMqtt();
sledRefresh();

// Auto-refresh dashboard every 15 s
setInterval(() => {
  if (document.getElementById('tab-dashboard').style.display !== 'none') {
    sledRefresh();
  }
}, 15000);
</script>
