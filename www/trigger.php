<?php
// trigger.php — Manual trigger endpoint for SLED plugin
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($ok, $msg) {
  echo json_encode(["status" => $ok ? "OK" : "ERROR", "message" => $msg]);
  exit;
}

$action   = trim((string)($_GET['action'] ?? ''));
$cmdQueue = "/home/fpp/media/logs/sled_trigger.cmd";
$pidFile  = "/home/fpp/media/logs/sled_daemon.pid";

$pluginDir = realpath(dirname(__FILE__) . "/../");
$callbacks = $pluginDir ? $pluginDir . "/callbacks.sh" : null;

switch ($action) {
  case 'letter':
    if (@file_put_contents($cmdQueue, "letter\n") === false)
      respond(false, "Failed to write command queue");
    respond(true, "Letter trigger queued.");

  case 'donation':
    if (@file_put_contents($cmdQueue, "donation\n") === false)
      respond(false, "Failed to write command queue");
    respond(true, "Donation trigger queued.");

  case 'stop':
    if (@file_put_contents($cmdQueue, "stop\n") === false)
      respond(false, "Failed to write command queue");
    respond(true, "Stop queued.");

  case 'restart':
    // Kill ALL existing daemon instances, then start fresh via callbacks.sh
    @exec("pkill -f sled_daemon.py 2>/dev/null");
    sleep(1);
    if (!$callbacks || !file_exists($callbacks))
      respond(false, "callbacks.sh not found");
    @exec("bash " . escapeshellarg($callbacks) . " pluginStart > /dev/null 2>&1 &");
    respond(true, "Daemon restart initiated.");

  case 'pkgcheck': {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'dpkg_serial'  => trim(shell_exec('dpkg -l python3-serial 2>&1 | tail -1') ?: ''),
      'dpkg_pip'     => trim(shell_exec('dpkg -l python3-pip 2>&1 | tail -1') ?: ''),
      'apt_serial'   => trim(shell_exec('apt-get install -y python3-serial 2>&1 | tail -3') ?: ''),
      'ensurepip'    => trim(shell_exec('python3 -m ensurepip --version 2>&1') ?: ''),
      'serial_after' => trim(shell_exec('python3 -c "import serial; print(serial.__version__)" 2>&1') ?: ''),
    ], JSON_PRETTY_PRINT);
    exit;
  }

  case 'install_deps': {
    $pluginDir2 = realpath(dirname(__FILE__) . "/../");
    $vendor     = $pluginDir2 . "/scripts/vendor";
    $installPy  = $pluginDir2 . "/scripts/install_vendor.py";
    @mkdir($vendor, 0755, true);
    if (!file_exists($installPy))
      respond(false, "install_vendor.py not found");
    $out = trim(shell_exec("python3 " . escapeshellarg($installPy) . " " . escapeshellarg($vendor) . " 2>&1") ?: "");
    respond(true, $out ?: "install_vendor.py ran (no output)");
  }

  // ── Radar diagnostic mode ──────────────────────────────────────────────
  case 'diag_start_a':
    if (@file_put_contents($cmdQueue, "diag_start_a\n") === false)
      respond(false, "Failed to write command queue");
    respond(true, "Diagnostic mode start queued for Radar A.");

  case 'diag_start_b':
    if (@file_put_contents($cmdQueue, "diag_start_b\n") === false)
      respond(false, "Failed to write command queue");
    respond(true, "Diagnostic mode start queued for Radar B.");

  case 'diag_stop':
    if (@file_put_contents($cmdQueue, "diag_stop\n") === false)
      respond(false, "Failed to write command queue");
    respond(true, "Diagnostic mode stop queued.");

  case 'diag_set_a':
  case 'diag_set_b': {
    $data = trim((string)($_GET['data'] ?? $_POST['data'] ?? ''));
    if ($data === '') respond(false, "No config data provided");
    $parsed = json_decode($data, true);
    if (!is_array($parsed)) respond(false, "Invalid JSON config data");
    // Sanitise and re-encode to prevent injection via the cmd line
    $safe = json_encode($parsed, JSON_UNESCAPED_SLASHES);
    $line = $action . ':' . $safe . "\n";
    if (@file_put_contents($cmdQueue, $line) === false)
      respond(false, "Failed to write command queue");
    $side = ($action === 'diag_set_a') ? 'A' : 'B';
    respond(true, "Radar $side config write queued.");
  }

  // ── System diagnostics ────────────────────────────────────────────────
  case 'sysinfo': {
    header('Content-Type: application/json; charset=utf-8');
    $pyserial = trim(shell_exec('python3 -c "import serial; print(serial.__version__)" 2>&1') ?: "");
    $paho     = trim(shell_exec('python3 -c "import paho; print(paho.__version__)" 2>&1') ?: "");
    $usbDevs  = trim(shell_exec('ls /dev/ttyUSB* 2>/dev/null') ?: "none");
    $daemonPid= trim(shell_exec('pgrep -f sled_daemon.py 2>/dev/null') ?: "not running");
    $svcState = trim(shell_exec('systemctl is-active sled-mailbox 2>/dev/null') ?: "unknown");
    $whoami   = trim(shell_exec('whoami') ?: "");
    $installLog = file_exists('/tmp/SledMailbox_install.log')
        ? file_get_contents('/tmp/SledMailbox_install.log')
        : (file_exists('/home/fpp/media/logs/SledMailbox_install.log')
            ? file_get_contents('/home/fpp/media/logs/SledMailbox_install.log')
            : "install log not found");
    echo json_encode([
      "user"        => $whoami,
      "pyserial"    => $pyserial ?: "NOT FOUND",
      "paho_mqtt"   => $paho ?: "NOT FOUND",
      "usb_devices" => $usbDevs,
      "daemon_pid"  => $daemonPid,
      "svc_state"   => $svcState,
      "install_log" => $installLog,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
  }

  default:
    respond(false, "Unknown action: " . htmlspecialchars($action));
}
