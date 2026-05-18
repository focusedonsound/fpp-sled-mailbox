<?php
/**
 * radar_diag.php — HLK-LD2410B Radar Diagnostic Modal
 *
 * Included at the bottom of index.php.
 * Activated by sledRadarOpen() called from the "Radar Diagnostics" button.
 *
 * Features:
 *  - Live 9-gate energy bar chart (moving=blue, stationary=orange)
 *  - Per-gate threshold lines on the same chart
 *  - Editable per-gate moving / stationary sensitivity thresholds
 *  - Max Gate Range selector (hardware max gate, in 0.75m steps)
 *  - No-Presence Timeout spinner
 *  - Vehicle and Person quick-preset buttons
 *  - Save to Radar / Reset to Current buttons
 *  - Polls radar_live.php every 300 ms
 *  - Sends diag_start / diag_stop / diag_set commands via trigger.php
 */
?>

<!-- ═══════════════════════════════════════════════════════════════════════
     RADAR DIAGNOSTIC MODAL OVERLAY
     ═══════════════════════════════════════════════════════════════════════ -->
<style>
/* Fullscreen overlay */
#sledRadarModal {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(0,0,0,0.7);
  align-items: flex-start;
  justify-content: center;
  padding: 24px 12px;
  overflow-y: auto;
}
#sledRadarModal.sr-open { display: flex; }

/* Dialog card */
.sr-dialog {
  background: var(--bs-body-bg, #1e1e2e);
  border: 1px solid rgba(255,255,255,0.15);
  border-radius: 10px;
  width: 100%;
  max-width: 900px;
  box-shadow: 0 8px 40px rgba(0,0,0,0.6);
  padding: 0;
  overflow: hidden;
}

/* Header bar */
.sr-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 20px;
  border-bottom: 1px solid rgba(255,255,255,0.1);
  background: rgba(255,255,255,0.04);
}
.sr-header h5 { margin: 0; font-size: 1.05rem; }

/* Body */
.sr-body { padding: 20px; }

/* Status bar */
.sr-status {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  background: rgba(0,0,0,0.2);
  border-radius: 6px;
  margin-bottom: 16px;
  min-height: 44px;
}

/* Gate chart wrapper */
.sr-chart-wrap {
  position: relative;
  height: 220px;
  margin-bottom: 20px;
}

/* Per-gate threshold grid */
.sr-gate-grid {
  overflow-x: auto;
}
.sr-gate-grid table {
  min-width: 680px;
  width: 100%;
  border-collapse: collapse;
}
.sr-gate-grid th, .sr-gate-grid td {
  text-align: center;
  padding: 4px 3px;
  font-size: 0.82rem;
}
.sr-gate-grid th.sr-label {
  font-weight: 600;
  text-align: right;
  padding-right: 10px;
  width: 110px;
  white-space: nowrap;
}
.sr-gate-grid input[type=number] {
  width: 52px;
  padding: 2px 4px;
  text-align: center;
  font-size: 0.82rem;
}
/* Moving threshold inputs: blue tint */
.sr-move-input { border-color: #3b82f6 !important; }
/* Static threshold inputs: orange tint */
.sr-static-input { border-color: #fb923c !important; }

/* Divider */
.sr-divider {
  border: none;
  border-top: 1px solid rgba(255,255,255,0.1);
  margin: 18px 0;
}

/* Controls row */
.sr-controls {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  align-items: flex-end;
  margin-bottom: 18px;
}
.sr-controls .sr-ctrl { display: flex; flex-direction: column; gap: 4px; }
.sr-controls label { font-size: 0.82rem; color: var(--bs-secondary-color, #adb5bd); white-space: nowrap; }

/* Tooltip helper icon */
.sr-tip {
  cursor: help;
  color: var(--bs-info, #0dcaf0);
  font-size: 0.8rem;
  margin-left: 3px;
}

/* Preset / action button row */
.sr-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-top: 4px;
}
</style>

<div id="sledRadarModal" role="dialog" aria-modal="true" aria-labelledby="srTitle">

  <!-- Click outside to close -->
  <div style="position:fixed;inset:0;z-index:-1;" onclick="sledRadarClose()"></div>

  <div class="sr-dialog">

    <!-- ── Header ──────────────────────────────────────────────────────── -->
    <div class="sr-header">
      <h5 id="srTitle">
        <i class="fas fa-fw fa-radar"></i>
        Radar Diagnostics &mdash; HLK-LD2410B
      </h5>
      <button class="btn btn-sm btn-outline-secondary" onclick="sledRadarClose()"
              title="Close (stops diagnostic mode and resumes normal car counting)">
        <i class="fas fa-fw fa-times"></i> Close
      </button>
    </div>

    <!-- ── Body ────────────────────────────────────────────────────────── -->
    <div class="sr-body">

      <!-- Info banner -->
      <div class="alert alert-info py-2 px-3 mb-3" style="font-size:0.88rem;">
        <i class="fas fa-fw fa-circle-info"></i>
        <strong>Diagnostic mode</strong> switches the active radar to engineering streaming,
        which provides per-gate energy data for tuning.
        <strong>Car counting is paused</strong> on the selected radar while this panel is open.
        Close the panel to resume normal operation.
      </div>

      <!-- Tabs -->
      <ul class="nav nav-tabs mb-3" id="srTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="srTabA" type="button" role="tab"
                  onclick="sledRadarTabSwitch('A', this)"
                  title="Radar A — first sensor (road-side). A→B direction = Inbound.">
            <i class="fas fa-fw fa-satellite-dish"></i> Radar A
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="srTabB" type="button" role="tab"
                  onclick="sledRadarTabSwitch('B', this)"
                  title="Radar B — second sensor (mailbox-side). B→A direction = Outbound.">
            <i class="fas fa-fw fa-satellite-dish"></i> Radar B
          </button>
        </li>
      </ul>

      <!-- Status bar -->
      <div class="sr-status" id="srStatus">
        <span id="srBadge" class="badge bg-secondary fs-6">STARTING&hellip;</span>
        <span id="srDistLabel" class="text-muted small">
          <i class="fas fa-fw fa-ruler-horizontal"></i> &mdash;
        </span>
        <span id="srPresenceLabel" class="text-muted small">
          <i class="fas fa-fw fa-clock"></i> &mdash;
        </span>
        <span id="srDiagLabel" class="text-muted small ms-auto">
          <i class="fas fa-fw fa-circle text-warning fa-beat-fade"></i> Diagnostic active
        </span>
      </div>
      <!-- Status legend -->
      <p class="text-muted mb-3" style="font-size:0.76rem; line-height:1.6;">
        <span class="badge bg-secondary me-1">ABSENT</span> No target detected &nbsp;&bull;&nbsp;
        <span class="badge bg-success me-1">MOVING</span> Target in motion &nbsp;&bull;&nbsp;
        <span class="badge bg-info text-dark me-1">STATIC</span> Stationary target (parked / standing still) &nbsp;&bull;&nbsp;
        <span class="badge bg-success me-1">PRESENT</span> Both moving &amp; stationary detected &nbsp;&bull;&nbsp;
        <span class="badge bg-warning text-dark me-1">UNKNOWN</span> Target detected &mdash; status unrecognized (firmware variant)
      </p>

      <!-- Live gate energy chart -->
      <div class="sr-chart-wrap">
        <canvas id="srChart" aria-label="Gate energy bar chart"></canvas>
      </div>
      <p class="text-muted small mb-3" style="font-size:0.78rem;">
        <span style="color:#3b82f6;">&#9646;</span> Moving energy &nbsp;
        <span style="color:#fb923c;">&#9646;</span> Stationary energy &nbsp;
        <span style="color:#3b82f6; border-bottom:2px dashed #3b82f6; padding-bottom:1px;">- - -</span> Move threshold &nbsp;
        <span style="color:#fb923c; border-bottom:2px dashed #fb923c; padding-bottom:1px;">- - -</span> Static threshold
        &nbsp;<em>(energy above threshold triggers detection)</em>
      </p>

      <hr class="sr-divider">

      <!-- Per-gate thresholds -->
      <div class="mb-3">
        <h6 style="font-size:0.9rem;">
          Per-Gate Sensitivity Thresholds
          <span class="sr-tip" data-bs-toggle="tooltip" data-bs-placement="top" title="Each gate covers 0.75 m of range. Energy above the threshold at that gate counts as a detection. Lower values = more sensitive (but more false triggers). Gates 0-1 (0–1.5 m) are closest to the sensor — usually need high thresholds to ignore the mailbox itself. Use the Vehicle or Person preset buttons below as a starting point.">
            <i class="fas fa-circle-question"></i>
          </span>
        </h6>
        <p class="text-muted mb-2" style="font-size:0.78rem;">
          0 &larr; <em>more sensitive (detects weaker signals)</em>
          &nbsp;&middot;&middot;&middot;&middot;&middot;&nbsp;
          <em>less sensitive (ignores noise)</em> &rarr; 100
        </p>
        <div class="sr-gate-grid">
          <table>
            <thead>
              <tr>
                <th class="sr-label"></th>
                <?php for ($g = 0; $g < 9; $g++): ?>
                <th title="Gate <?php echo $g; ?> — <?php echo round($g * 0.75, 2); ?>&ndash;<?php echo round(($g+1)*0.75, 2); ?> m from sensor">
                  G<?php echo $g; ?><br>
                  <span class="text-muted" style="font-size:0.72rem;"><?php echo $g * 0.75; ?>m</span>
                </th>
                <?php endfor; ?>
              </tr>
            </thead>
            <tbody>
              <tr title="Moving (motion) energy threshold per gate. The radar considers a target moving if its energy at this gate exceeds this value.">
                <th class="sr-label" style="color:#3b82f6;">
                  <i class="fas fa-fw fa-person-walking"></i> Move
                  <span class="sr-tip" data-bs-toggle="tooltip" data-bs-placement="top" title="Moving threshold per gate (0–100). Raise to reduce false positives from wind/vibration. Lower to detect slower-moving vehicles.">
                    <i class="fas fa-circle-question"></i>
                  </span>
                </th>
                <?php for ($g = 0; $g < 9; $g++): ?>
                <td>
                  <input type="number" id="srMove<?php echo $g; ?>"
                         class="form-control form-control-sm sr-move-input"
                         min="0" max="100" value="40"
                         oninput="srUpdateThresholdLines()"
                         title="Gate <?php echo $g; ?> moving threshold (0–100)" />
                </td>
                <?php endfor; ?>
              </tr>
              <tr title="Stationary energy threshold per gate. Used to detect cars that have stopped (parked).">
                <th class="sr-label" style="color:#fb923c;">
                  <i class="fas fa-fw fa-car"></i> Static
                  <span class="sr-tip" data-bs-toggle="tooltip" data-bs-placement="top" title="Stationary threshold per gate (0–100). Gates 0-1 are typically set to 0 (disabled) to avoid the sensor detecting its own mounting. Raise for gates in your main detection zone if you get false parked-car detections.">
                    <i class="fas fa-circle-question"></i>
                  </span>
                </th>
                <?php for ($g = 0; $g < 9; $g++): ?>
                <td>
                  <input type="number" id="srStatic<?php echo $g; ?>"
                         class="form-control form-control-sm sr-static-input"
                         min="0" max="100" value="20"
                         oninput="srUpdateThresholdLines()"
                         title="Gate <?php echo $g; ?> stationary threshold (0–100)" />
                </td>
                <?php endfor; ?>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <hr class="sr-divider">

      <!-- Hardware controls -->
      <div class="sr-controls">

        <div class="sr-ctrl">
          <label for="srMaxGate">
            Max Gate Range
            <span class="sr-tip" data-bs-toggle="tooltip" data-bs-placement="top" title="Maximum detection range in gates (each gate = 0.75 m). Gates beyond this are ignored by the radar hardware, saving processing and reducing false detections from distant objects. For a typical driveway, gates 5–6 (3.75–4.5 m) work well.">
              <i class="fas fa-circle-question"></i>
            </span>
          </label>
          <select id="srMaxGate" class="form-control form-control-sm" style="width:160px;"
                  onchange="srUpdateThresholdLines()">
            <?php for ($g = 1; $g <= 8; $g++): ?>
            <option value="<?php echo $g; ?>" <?php echo $g === 6 ? 'selected' : ''; ?>>
              Gate <?php echo $g; ?> (<?php echo round($g * 0.75, 2); ?> m)
            </option>
            <?php endfor; ?>
          </select>
        </div>

        <div class="sr-ctrl">
          <label for="srTimeout">
            No-Presence Timeout
            <span class="sr-tip" data-bs-toggle="tooltip" data-bs-placement="top" title="After all targets leave the detection zone, the radar continues to report 'presence' for this many seconds before clearing. Longer values reduce flutter (repeated on/off transitions) but slow down reaction time after a car leaves. Typical range: 3–15 seconds.">
              <i class="fas fa-circle-question"></i>
            </span>
          </label>
          <div class="input-group input-group-sm" style="width:130px;">
            <input type="number" id="srTimeout" class="form-control form-control-sm"
                   min="1" max="120" value="5" style="width:80px;" />
            <span class="input-group-text">sec</span>
          </div>
        </div>

        <div class="sr-ctrl">
          <label>
            Quick Presets
            <span class="sr-tip" data-bs-toggle="tooltip" data-bs-placement="top" title="Apply pre-tuned sensitivity profiles. 'Vehicle' uses higher thresholds optimised for car-sized targets (suppresses near-field noise). 'Person' uses lower thresholds for pedestrian detection. You can adjust individual gate values after applying a preset before saving.">
              <i class="fas fa-circle-question"></i>
            </span>
          </label>
          <div class="d-flex gap-2">
            <button type="button" class="buttons btn-outline-light btn-sm"
                    onclick="sledRadarPreset('vehicle')"
                    title="Vehicle preset: high near-field thresholds, detection zone 1.5–4.5 m, timeout 5 s. Best for counting cars in a driveway.">
              <i class="fas fa-fw fa-car"></i> Vehicle
            </button>
            <button type="button" class="buttons btn-outline-light btn-sm"
                    onclick="sledRadarPreset('person')"
                    title="Person preset: lower thresholds, detects all 9 gates (0–6.75 m), timeout 10 s. Best for detecting pedestrians approaching the mailbox.">
              <i class="fas fa-fw fa-person-walking"></i> Person
            </button>
          </div>
        </div>

      </div>

      <!-- Action buttons -->
      <div class="sr-actions">
        <button type="button" class="sled-btn"
                onclick="sledRadarSave()"
                title="Write the current threshold and range settings to the radar's flash memory. The radar will use these settings immediately and remember them after power-off. Car counting resumes automatically.">
          <i class="fas fa-fw fa-microchip"></i> Save to Radar
        </button>
        <button type="button" class="sled-btn sled-btn-sm"
                onclick="sledRadarResetForm()"
                title="Discard any unsaved changes and reload the last values read from the radar device.">
          <i class="fas fa-fw fa-rotate-left"></i> Reset to Saved
        </button>
        <span class="text-muted small ms-2" id="srSaveStatus"></span>
      </div>

      <!-- Footer note -->
      <p class="text-muted mt-3 mb-0" style="font-size:0.8rem;">
        <i class="fas fa-fw fa-circle-info"></i>
        Changes are written to the radar&rsquo;s non-volatile memory via the UART config protocol.
        The <strong>Software Min Energy</strong> threshold (in the main config) is a separate
        software-side filter &mdash; the values here are hardware gate sensitivities programmed
        directly into the LD2410B chip.
      </p>

    </div><!-- /.sr-body -->
  </div><!-- /.sr-dialog -->
</div><!-- /#sledRadarModal -->


<script>
(function () {
  'use strict';

  // ── Constants ──────────────────────────────────────────────────────────────
  const NUM_GATES   = 9;
  const GATE_WIDTH  = 0.75;                  // metres per gate
  const POLL_MS     = 300;

  const GATE_LABELS = Array.from({length: NUM_GATES},
    (_, i) => (i * GATE_WIDTH).toFixed(2).replace('.00', '') + 'm');

  // Factory-default presets — match sled_ld2410.py
  const SR_PRESETS = {
    vehicle: {
      max_move_gate: 6, max_static_gate: 6, timeout_s: 5,
      move_sensitivity:   [50, 50, 40, 40, 30, 25, 20, 20, 20],
      static_sensitivity: [ 0,  0, 30, 30, 20, 15, 10, 10, 10],
    },
    person: {
      max_move_gate: 8, max_static_gate: 8, timeout_s: 10,
      move_sensitivity:   [30, 30, 25, 25, 20, 20, 15, 15, 15],
      static_sensitivity: [20, 20, 20, 20, 15, 15, 10, 10, 10],
    },
  };

  // ── State ──────────────────────────────────────────────────────────────────
  let srSide       = 'A';      // 'A' or 'B'
  let srChart      = null;
  let srPollTimer  = null;
  let srDiagActive = false;
  let srSavedCfg   = null;     // last config confirmed written to device

  // ── Helpers ────────────────────────────────────────────────────────────────

  function srTriggerUrl(action, extraParams) {
    let url = sledUrl('trigger.php') + '&action=' + encodeURIComponent(action);
    if (extraParams) url += '&' + extraParams;
    return url;
  }

  function srLiveUrl(side) {
    return sledUrl('radar_live.php') + '&side=' + side.toLowerCase();
  }

  function srSetBadge(text, color) {
    const b = document.getElementById('srBadge');
    if (!b) return;
    b.textContent = text;
    b.className   = 'badge fs-6 bg-' + (color || 'secondary');
  }

  function srSetSaveStatus(msg, isError) {
    const el = document.getElementById('srSaveStatus');
    if (el) {
      el.textContent = msg;
      el.style.color = isError ? 'var(--bs-danger, #dc3545)' : 'var(--bs-success, #198754)';
    }
  }

  // ── Chart initialisation ───────────────────────────────────────────────────

  function srInitChart() {
    if (srChart) { srChart.destroy(); srChart = null; }
    const canvas = document.getElementById('srChart');
    if (!canvas || typeof Chart === 'undefined') return;

    const zeros = Array(NUM_GATES).fill(0);

    srChart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: GATE_LABELS,
        datasets: [
          {
            label: 'Moving Energy',
            type: 'bar',
            data: [...zeros],
            backgroundColor: 'rgba(59, 130, 246, 0.75)',
            borderColor:     'rgba(59, 130, 246, 1)',
            borderWidth: 1,
            order: 2,
          },
          {
            label: 'Stationary Energy',
            type: 'bar',
            data: [...zeros],
            backgroundColor: 'rgba(251, 146, 60, 0.65)',
            borderColor:     'rgba(251, 146, 60, 1)',
            borderWidth: 1,
            order: 3,
          },
          {
            label: 'Move Threshold',
            type: 'line',
            data: [...zeros],
            borderColor: 'rgba(59, 130, 246, 0.9)',
            borderWidth: 2,
            borderDash: [6, 4],
            pointRadius: 0,
            fill: false,
            tension: 0,
            stepped: 'middle',
            order: 1,
          },
          {
            label: 'Static Threshold',
            type: 'line',
            data: [...zeros],
            borderColor: 'rgba(251, 146, 60, 0.9)',
            borderWidth: 2,
            borderDash: [6, 4],
            pointRadius: 0,
            fill: false,
            tension: 0,
            stepped: 'middle',
            order: 0,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            display: true,
            position: 'top',
            labels: { boxWidth: 14, font: { size: 11 } },
          },
          tooltip: {
            callbacks: {
              label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y}`,
            },
          },
        },
        scales: {
          x: {
            title: { display: true, text: 'Gate (distance from sensor)', font: { size: 11 } },
            ticks: { font: { size: 11 } },
          },
          y: {
            min: 0,
            max: 100,
            title: { display: true, text: 'Energy (0–100)', font: { size: 11 } },
            ticks: { font: { size: 11 } },
          },
        },
      },
    });
  }

  // ── Threshold lines ────────────────────────────────────────────────────────

  window.srUpdateThresholdLines = function () {
    if (!srChart) return;
    const moves = [], statics = [];
    for (let g = 0; g < NUM_GATES; g++) {
      const mi = document.getElementById('srMove'   + g);
      const si = document.getElementById('srStatic' + g);
      moves.push(mi   ? (parseInt(mi.value)  || 0) : 0);
      statics.push(si ? (parseInt(si.value) || 0) : 0);
    }
    srChart.data.datasets[2].data = moves;
    srChart.data.datasets[3].data = statics;
    srChart.update('none');
  };

  // ── Form helpers ───────────────────────────────────────────────────────────

  function srPopulateForm(cfg) {
    if (!cfg) return;
    const mgEl = document.getElementById('srMaxGate');
    if (mgEl) mgEl.value = cfg.max_move_gate ?? 6;

    const toEl = document.getElementById('srTimeout');
    if (toEl) toEl.value = cfg.timeout_s ?? 5;

    for (let g = 0; g < NUM_GATES; g++) {
      const mi = document.getElementById('srMove'   + g);
      const si = document.getElementById('srStatic' + g);
      if (mi) mi.value = (cfg.move_sensitivity   || [])[g] ?? 40;
      if (si) si.value = (cfg.static_sensitivity || [])[g] ?? 20;
    }
    srUpdateThresholdLines();
  }

  function srReadForm() {
    const moves = [], statics = [];
    for (let g = 0; g < NUM_GATES; g++) {
      const mi = document.getElementById('srMove'   + g);
      const si = document.getElementById('srStatic' + g);
      moves.push(  mi ? Math.max(0, Math.min(100, parseInt(mi.value) || 0)) : 0);
      statics.push(si ? Math.max(0, Math.min(100, parseInt(si.value) || 0)) : 0);
    }
    const mg = parseInt(document.getElementById('srMaxGate')?.value) || 6;
    const to = parseInt(document.getElementById('srTimeout')?.value) || 5;
    return {
      max_move_gate:      mg,
      max_static_gate:    mg,
      move_sensitivity:   moves,
      static_sensitivity: statics,
      timeout_s:          Math.max(1, Math.min(120, to)),
    };
  }

  // ── Polling ────────────────────────────────────────────────────────────────

  function srStartPoll() {
    srStopPoll();
    srPollTimer = setInterval(srPoll, POLL_MS);
  }

  function srStopPoll() {
    if (srPollTimer !== null) {
      clearInterval(srPollTimer);
      srPollTimer = null;
    }
  }

  async function srPoll() {
    try {
      const res = await fetch(srLiveUrl(srSide), { cache: 'no-store' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const d = await res.json();
      srUpdateDisplay(d);
    } catch (e) {
      srSetBadge('ERROR', 'danger');
    }
  }

  function srUpdateDisplay(d) {
    if (d.error && !d.active) {
      srSetBadge('NO DATA', 'secondary');
      document.getElementById('srDistLabel').innerHTML =
        '<i class="fas fa-fw fa-ruler-horizontal"></i> ' +
        '<em class="text-muted">' + escHtml(d.error) + '</em>';
      document.getElementById('srPresenceLabel').innerHTML =
        '<i class="fas fa-fw fa-clock"></i> &mdash;';
      return;
    }

    if (!d.active) {
      srSetBadge('STALE', 'warning');
      return;
    }

    // Status badge
    const statusMap = {
      none:    { text: 'ABSENT',  color: 'secondary' },
      moving:  { text: 'MOVING',  color: 'success'   },
      static:  { text: 'STATIC',  color: 'info'       },
      both:    { text: 'PRESENT', color: 'success'   },
      unknown: { text: 'UNKNOWN', color: 'warning'   },
    };
    const s = statusMap[d.status] || { text: (d.status || 'UNKNOWN').toUpperCase(), color: 'info' };
    srSetBadge(s.text, s.color);

    // Distance
    const dist = d.distance ? '~' + d.distance + ' m' : '—';
    document.getElementById('srDistLabel').innerHTML =
      '<i class="fas fa-fw fa-ruler-horizontal"></i> ' + escHtml(dist);

    // Age
    const age = d.ts ? Math.round(Date.now() / 1000 - d.ts) : null;
    document.getElementById('srPresenceLabel').innerHTML =
      '<i class="fas fa-fw fa-clock"></i> ' +
      (age !== null ? age + 's ago' : '—');

    // Chart
    if (srChart && d.gates) {
      srChart.data.datasets[0].data = (d.gates.moving    || []).slice(0, NUM_GATES);
      srChart.data.datasets[1].data = (d.gates.stationary|| []).slice(0, NUM_GATES);
      srChart.update('none');
    }
  }

  function escHtml(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;');
  }

  // ── Diagnostic mode lifecycle ──────────────────────────────────────────────

  async function srStartDiag(side) {
    const action = 'diag_start_' + side.toLowerCase();
    try {
      await fetch(srTriggerUrl(action), { cache: 'no-store' });
      srDiagActive = true;
    } catch (e) {
      console.warn('[SLED Radar] diag_start failed:', e);
    }
    srStartPoll();
  }

  async function srStopDiag() {
    srStopPoll();
    if (!srDiagActive) return;
    try {
      await fetch(srTriggerUrl('diag_stop'), { cache: 'no-store' });
    } catch (e) {
      console.warn('[SLED Radar] diag_stop failed:', e);
    }
    srDiagActive = false;
  }

  // ── Public API (called from HTML) ──────────────────────────────────────────

  /** Open the modal and start diagnostic mode on the current side. */
  window.sledRadarOpen = function () {
    const modal = document.getElementById('sledRadarModal');
    if (!modal) return;
    modal.classList.add('sr-open');
    document.body.style.overflow = 'hidden';

    // Initialise Bootstrap tooltips for all sr-tip elements inside the modal
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
      modal.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        var existing = bootstrap.Tooltip.getInstance(el);
        if (existing) existing.dispose();
        new bootstrap.Tooltip(el, { trigger: 'hover', container: '#sledRadarModal' });
      });
    }

    // Ensure Chart.js is available — it might not be on a slow load
    if (typeof Chart === 'undefined') {
      srSetBadge('CHART N/A', 'danger');
    } else {
      srInitChart();
    }
    // Apply default preset values to form on first open
    sledRadarPreset('vehicle');

    srStartDiag(srSide);
  };

  /** Close the modal and stop diagnostic mode. */
  window.sledRadarClose = function () {
    const modal = document.getElementById('sledRadarModal');
    if (!modal) return;
    srStopDiag();
    modal.classList.remove('sr-open');
    document.body.style.overflow = '';
    srSetBadge('STARTING…', 'secondary');
    srSetSaveStatus('', false);
  };

  /** Switch active tab (side). Restarts diagnostic on the new radar. */
  window.sledRadarTabSwitch = function (side, btn) {
    if (side === srSide) return;

    // Update tab visual
    document.querySelectorAll('#srTabs .nav-link').forEach(el => el.classList.remove('active'));
    if (btn) btn.classList.add('active');

    srStopDiag();
    srSide = side;
    srSetBadge('STARTING…', 'secondary');
    document.getElementById('srDistLabel').innerHTML    = '<i class="fas fa-fw fa-ruler-horizontal"></i> &mdash;';
    document.getElementById('srPresenceLabel').innerHTML= '<i class="fas fa-fw fa-clock"></i> &mdash;';
    srSetSaveStatus('', false);

    srStartDiag(srSide);
  };

  /** Apply a built-in preset to the threshold form inputs. */
  window.sledRadarPreset = function (name) {
    const p = SR_PRESETS[name];
    if (!p) return;
    srPopulateForm(p);
    srSetSaveStatus('⚠ Preset applied — click “Save to Radar” to program the device', false);
  };

  /** Reset form to last saved (or default vehicle preset if never saved). */
  window.sledRadarResetForm = function () {
    if (srSavedCfg) {
      srPopulateForm(srSavedCfg);
      srSetSaveStatus('Reset to last saved values', false);
    } else {
      sledRadarPreset('vehicle');
      srSetSaveStatus('No saved config — showing Vehicle preset defaults', false);
    }
  };

  /** Write current form values to the radar via the daemon cmd queue. */
  window.sledRadarSave = async function () {
    const cfg = srReadForm();
    const action = 'diag_set_' + srSide.toLowerCase();
    srSetSaveStatus('Saving…', false);
    try {
      const url = srTriggerUrl(action, 'data=' + encodeURIComponent(JSON.stringify(cfg)));
      const res = await fetch(url, { cache: 'no-store' });
      const j   = await res.json().catch(() => ({ status: 'ERROR', message: 'Bad response' }));
      if (j.status === 'OK') {
        srSavedCfg = cfg;
        srSetSaveStatus('✓ ' + (j.message || 'Saved to Radar ' + srSide), false);
      } else {
        srSetSaveStatus('✗ ' + (j.message || 'Save failed'), true);
      }
    } catch (e) {
      srSetSaveStatus('✗ Network error: ' + e.message, true);
    }
  };

})();
</script>
