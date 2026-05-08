<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configFile = "/home/fpp/media/config/sled.json";

function respond($ok, $msg) {
  echo json_encode(["status" => $ok ? "OK" : "ERROR", "message" => $msg]);
  exit;
}

function pStr($key, $default = "") {
  return trim((string)($_POST[$key] ?? $default));
}
function pInt($key, $default = 0) {
  $v = trim((string)($_POST[$key] ?? ""));
  return ($v !== "" && is_numeric($v)) ? (int)$v : $default;
}
function pFloat($key, $default = 0.0) {
  $v = trim((string)($_POST[$key] ?? ""));
  return ($v !== "" && is_numeric($v)) ? round((float)$v, 3) : $default;
}
function pBool($key) {
  return isset($_POST[$key]) && $_POST[$key] === "1";
}
function parseClips($raw) {
  if ($raw === "") return [];
  return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

$dir = dirname($configFile);
if (!is_dir($dir))      respond(false, "Config dir missing: $dir");
if (!is_writable($dir)) respond(false, "Config dir not writable: $dir");

// Load current config so we don't lose any keys we don't expose in UI
$cfg = [];
if (file_exists($configFile)) {
  $j = json_decode(@file_get_contents($configFile), true);
  if (is_array($j)) $cfg = $j;
}

// ── Merge posted values ──────────────────────────────────────────────────
$cfg["enabled"] = pBool("enabled");

$cfg["paths"]["videos"] = pStr("video_dir", "/home/fpp/media/plugins/fpp-sled-mailbox/videos");

$cfg["schedule"]["start"] = pStr("schedule_start", "16:00");
$cfg["schedule"]["end"]   = pStr("schedule_end",   "22:00");

$cfg["video"]["idle"]           = pStr("video_idle", "idle.mp4");
$cfg["video"]["letter_clips"]   = parseClips(pStr("letter_clips"));
$cfg["video"]["donation_clips"] = parseClips(pStr("donation_clips"));
$cfg["video"]["play_timeout_s"] = pInt("play_timeout_s", 65);

$pinLetter   = pStr("pin_letter");
$pinDonation = pStr("pin_donation");
$cfg["pins"]["letter"]   = ($pinLetter !== "")   ? (int)$pinLetter   : 17;
$cfg["pins"]["donation"] = ($pinDonation !== "") ? (int)$pinDonation : null;

$cfg["letter"]["cooldown_s"]   = pFloat("letter_cooldown",   3.0);
$cfg["donation"]["cooldown_s"] = pFloat("donation_cooldown", 5.0);

$cfg["car"]["sequence_window_s"] = pFloat("car_seq_window", 0.8);
$cfg["car"]["cooldown_s"]        = pFloat("car_cooldown",   1.5);

$cfg["ld2410"]["enabled"]          = pBool("ld2410_enabled");
$cfg["ld2410"]["A"]["port"]        = pStr("ld2410_port_a", "/dev/ttyUSB0");
$cfg["ld2410"]["B"]["port"]        = pStr("ld2410_port_b", "/dev/ttyUSB1");
$cfg["ld2410"]["A"]["min_energy"]  = pInt("ld2410_min_a", 20);
$cfg["ld2410"]["B"]["min_energy"]  = pInt("ld2410_min_b", 20);

$validRef = ["AB", "BA"];
$ref = strtoupper(pStr("toward_ref", "AB"));
$cfg["direction"]["toward_reference"] = in_array($ref, $validRef) ? $ref : "AB";
$cfg["direction"]["label_toward"] = pStr("label_toward", "Inbound")  ?: "Inbound";
$cfg["direction"]["label_away"]   = pStr("label_away",   "Outbound") ?: "Outbound";

$cfg["dht11"]["enabled"]    = pBool("dht11_enabled");
$cfg["dht11"]["pin"]        = pInt("dht11_pin", 4);
$cfg["dht11"]["interval_s"] = pInt("dht11_interval", 60);

$cfg["mqtt"]["use_fpp_settings"] = pBool("mqtt_use_fpp");
$cfg["mqtt"]["host"]             = pStr("mqtt_host");
$cfg["mqtt"]["port"]             = pInt("mqtt_port", 1883);
$cfg["mqtt"]["username"]         = pStr("mqtt_username");
$cfg["mqtt"]["password"]         = pStr("mqtt_password");
$cfg["mqtt"]["base"]             = pStr("mqtt_base", "sled");
$cfg["mqtt"]["device_name"]      = pStr("mqtt_device_name", "SLED Santa Mailbox");
$cfg["mqtt"]["device_id"]        = pStr("mqtt_device_id", "sled_mailbox");
$cfg["mqtt"]["discovery"]        = pBool("mqtt_discovery");

$cfg["debug"]["use_mock_inputs"] = pBool("debug_mock");

// ── Atomic write ──────────────────────────────────────────────────────────
$tmp  = $configFile . ".tmp";
$data = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (@file_put_contents($tmp, $data) === false) respond(false, "Failed to write temp config");
if (!@rename($tmp, $configFile)) { @unlink($tmp); respond(false, "Failed to replace config"); }

respond(true, "Saved.");
