<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configFile = "/home/fpp/media/config/sled.json";

function respond($ok, $msg) {
  echo json_encode(["status" => $ok ? "OK" : "ERROR", "message" => $msg]);
  exit;
}
function pStr($key, $default = "") { return trim((string)($_POST[$key] ?? $default)); }
function pInt($key, $default = 0)  { $v = trim((string)($_POST[$key] ?? "")); return ($v !== "" && is_numeric($v)) ? (int)$v : $default; }
function pFloat($key, $default = 0.0) { $v = trim((string)($_POST[$key] ?? "")); return ($v !== "" && is_numeric($v)) ? round((float)$v, 3) : $default; }
function pBool($key) { return isset($_POST[$key]) && $_POST[$key] === "1"; }
function pClips($key) {
  if (!isset($_POST[$key]) || !is_array($_POST[$key])) return [];
  return array_values(array_filter(array_map('trim', $_POST[$key])));
}

$dir = dirname($configFile);
if (!is_dir($dir))      respond(false, "Config directory missing: $dir");
if (!is_writable($dir)) respond(false, "Config directory not writable: $dir");

// Load existing config to preserve keys not in the UI
$cfg = [];
if (file_exists($configFile)) {
  $j = json_decode(@file_get_contents($configFile), true);
  if (is_array($j)) $cfg = $j;
}

// ── Video ─────────────────────────────────────────────────────────────────
$cfg["video"]["idle"]           = pStr("video_idle");
$cfg["video"]["letter_clips"]   = pClips("letter_clip");
$cfg["video"]["donation_clips"] = pClips("donation_clip");
$cfg["video"]["play_timeout_s"] = pInt("play_timeout_s", 65);

// ── Pins + cooldowns ──────────────────────────────────────────────────────
$pinLetter   = pStr("pin_letter");
$pinDonation = pStr("pin_donation");
$cfg["pins"]["letter"]   = ($pinLetter !== "")   ? (int)$pinLetter   : 17;
$cfg["pins"]["donation"] = ($pinDonation !== "") ? (int)$pinDonation : null;

$cfg["letter"]["cooldown_s"]   = pFloat("letter_cooldown",   3.0);
$cfg["donation"]["cooldown_s"] = pFloat("donation_cooldown", 5.0);

// ── Schedule ──────────────────────────────────────────────────────────────
$cfg["schedule"]["start"] = pStr("schedule_start", "16:00");
$cfg["schedule"]["end"]   = pStr("schedule_end",   "22:00");

// ── LD2410 ────────────────────────────────────────────────────────────────
$cfg["ld2410"]["enabled"]         = pBool("ld2410_enabled");
$cfg["ld2410"]["A"]["port"]       = pStr("ld2410_port_a", "/dev/ttyUSB0");
$cfg["ld2410"]["B"]["port"]       = pStr("ld2410_port_b", "/dev/ttyUSB1");

$validRef = ["AB", "BA"];
$ref = strtoupper(pStr("toward_ref", "AB"));
$cfg["direction"]["toward_reference"] = in_array($ref, $validRef) ? $ref : "AB";
$cfg["direction"]["label_toward"]     = pStr("label_toward", "Inbound")  ?: "Inbound";
$cfg["direction"]["label_away"]       = pStr("label_away",   "Outbound") ?: "Outbound";

// ── Atomic write ──────────────────────────────────────────────────────────
$tmp  = $configFile . ".tmp";
$data = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (@file_put_contents($tmp, $data) === false) respond(false, "Failed to write temp file");
if (!@rename($tmp, $configFile)) { @unlink($tmp); respond(false, "Failed to write config"); }

respond(true, "Settings saved.");
