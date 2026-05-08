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
    // Stop then start via callbacks.sh
    if (!$callbacks || !file_exists($callbacks))
      respond(false, "callbacks.sh not found");
    @exec("bash " . escapeshellarg($callbacks) . " pluginStop > /dev/null 2>&1");
    sleep(1);
    @exec("bash " . escapeshellarg($callbacks) . " pluginStart > /dev/null 2>&1 &");
    respond(true, "Daemon restart initiated.");

  default:
    respond(false, "Unknown action: " . htmlspecialchars($action));
}
