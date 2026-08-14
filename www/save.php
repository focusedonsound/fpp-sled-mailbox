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
function generateUUID() {
  $data = random_bytes(16);
  $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
  $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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

// ── Idle playlist ─────────────────────────────────────────────────────────
$cfg["playlists"]["idle"]           = pStr("pl_idle", "sled_idle");
$cfg["playlists"]["play_timeout_s"] = pInt("play_timeout_s", 120);
// Note: playlists.letter / playlists.donation are kept in config for legacy
// fallback only — new setups use videos.letter / videos.donation below.

// ── Event video files (letter/donation round-robin) ───────────────────────
$cfg["videos"]["letter"]   = pClips("letter_vid");
$cfg["videos"]["donation"] = pClips("donation_vid");

// ── Pins + cooldowns ──────────────────────────────────────────────────────
$pinLetter   = pStr("pin_letter");
$pinDonation = pStr("pin_donation");
$pinSpecial1 = pStr("pin_special_1");
$pinSpecial2 = pStr("pin_special_2");

$cfg["pins"]["letter"]    = ($pinLetter   !== "") ? (int)$pinLetter   : 17;
$cfg["pins"]["donation"]  = ($pinDonation !== "") ? (int)$pinDonation : null;
$cfg["pins"]["special_1"] = ($pinSpecial1 !== "") ? (int)$pinSpecial1 : null;
$cfg["pins"]["special_2"] = ($pinSpecial2 !== "") ? (int)$pinSpecial2 : null;

// Build the reserved-pin list dynamically from the Pi boot config so we only
// warn about interfaces that are actually enabled on this hardware.
// Returns an empty array on non-Pi systems (no config.txt) or when an
// interface is disabled — no false positives for hats that don't use SPI/I2C.
function read_boot_config(): string {
    foreach (['/boot/firmware/config.txt', '/boot/config.txt'] as $p) {
        if (is_readable($p)) return file_get_contents($p) ?: '';
    }
    return '';
}
function iface_enabled(string $cfg, string $param): bool {
    return (bool) preg_match('/^\s*' . preg_quote($param, '/') . '\s*=\s*on/m', $cfg);
}
$bootCfg      = read_boot_config();
$reservedGpio = [];
if (iface_enabled($bootCfg, 'dtparam=spi')) {
    $reservedGpio += [
        7  => "SPI0 CE1 (spi_bcm2835 — dtparam=spi=on)",
        8  => "SPI0 CE0 (spi_bcm2835 — dtparam=spi=on)",
        9  => "SPI0 MISO (spi_bcm2835 — dtparam=spi=on)",
        10 => "SPI0 MOSI (spi_bcm2835 — dtparam=spi=on)",
        11 => "SPI0 SCLK (spi_bcm2835 — dtparam=spi=on)",
    ];
}
if (iface_enabled($bootCfg, 'dtparam=i2c_arm')) {
    $reservedGpio += [
        2 => "I2C1 SDA (i2c_bcm2835 — dtparam=i2c_arm=on)",
        3 => "I2C1 SCL (i2c_bcm2835 — dtparam=i2c_arm=on)",
    ];
}
if (iface_enabled($bootCfg, 'enable_uart')) {
    $reservedGpio += [
        14 => "UART0 TX (uart0 — enable_uart=1)",
        15 => "UART0 RX (uart0 — enable_uart=1)",
    ];
}
$pinWarnings = [];
$namedPins = [
    "Letter"   => $cfg["pins"]["letter"],
    "Donation" => $cfg["pins"]["donation"],
    "Special 1"=> $cfg["pins"]["special_1"],
    "Special 2"=> $cfg["pins"]["special_2"],
];
foreach ($namedPins as $pinLabel => $bcm) {
    if ($bcm !== null && isset($reservedGpio[$bcm])) {
        $pinWarnings[] = "$pinLabel pin GPIO$bcm is reserved ({$reservedGpio[$bcm]}) — "
            . "if that interface is active the daemon will fail to claim the pin silently. "
            . "Choose a different BCM pin.";
    }
}

$cfg["letter"]["cooldown_s"]   = pFloat("letter_cooldown",   3.0);
$cfg["donation"]["cooldown_s"] = pFloat("donation_cooldown", 5.0);

// ── Special message slots ─────────────────────────────────────────────────
$cfg["specials"]["special_1"]["label"]      = pStr("special1_label", "Special Message 1") ?: "Special Message 1";
$cfg["specials"]["special_2"]["label"]      = pStr("special2_label", "Special Message 2") ?: "Special Message 2";
$cfg["specials"]["special_1"]["cooldown_s"] = pFloat("special1_cooldown", 5.0);
$cfg["specials"]["special_2"]["cooldown_s"] = pFloat("special2_cooldown", 5.0);

$cfg["videos"]["special_1"] = pClips("special1_vid");
$cfg["videos"]["special_2"] = pClips("special2_vid");

// ── LD2410 ────────────────────────────────────────────────────────────────
$cfg["ld2410"]["enabled"]            = pBool("ld2410_enabled");
$cfg["ld2410"]["A"]["port"]          = pStr("ld2410_port_a", "/dev/ttyUSB0");
$cfg["ld2410"]["B"]["port"]          = pStr("ld2410_port_b", "/dev/ttyUSB1");
$cfg["ld2410"]["A"]["min_energy"]    = max(1, min(100, pInt("ld2410_min_energy_a", 20)));
$cfg["ld2410"]["B"]["min_energy"]    = max(1, min(100, pInt("ld2410_min_energy_b", 20)));

// ── Car counting timings ──────────────────────────────────────────────────
$cfg["car"]["sequence_window_s"]     = pFloat("sequence_window_s", 0.8);
$cfg["car"]["cooldown_s"]            = pFloat("car_cooldown_s", 1.5);
$cfg["car"]["parked_timeout_s"]      = max(10, pInt("parked_timeout_s", 180));
$cfg["car"]["direction_window_s"]    = pFloat("direction_window_s", 10.0);

// ── Direction labels ──────────────────────────────────────────────────────
$validRef = ["AB", "BA"];
$ref = strtoupper(pStr("toward_ref", "AB"));
$cfg["direction"]["toward_reference"] = in_array($ref, $validRef) ? $ref : "AB";
$cfg["direction"]["label_toward"]     = pStr("label_toward", "Inbound")  ?: "Inbound";
$cfg["direction"]["label_away"]       = pStr("label_away",   "Outbound") ?: "Outbound";

// ── Auto-start (daemon enabled at FPP boot) ───────────────────────────────
// Only update if the new form section is present (hidden sentinel).
// Without this guard, submitting an old cached form (which lacks the
// enabled checkbox) would call pBool("enabled") → false and silently
// disable auto-start even though the user never touched the toggle.
if (isset($_POST['_sled_form_v2'])) {
    $cfg["enabled"] = pBool("enabled");
}
// else: preserve whatever is already in the config (defaults to true via callbacks.sh)

// ── Atomic write ──────────────────────────────────────────────────────────
$tmp  = $configFile . ".tmp";
$data = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (@file_put_contents($tmp, $data) === false) respond(false, "Failed to write temp file");
if (!@rename($tmp, $configFile)) { @unlink($tmp); respond(false, "Failed to write config"); }

// ── Auto-create FPP GPIO input triggers ───────────────────────────────────
// Map BCM GPIO numbers → Raspberry Pi P1 header pin names (FPP's pin format)
$bcmToP1 = [
    2=>"P1-3",  3=>"P1-5",  4=>"P1-7",  14=>"P1-8",  15=>"P1-10",
    17=>"P1-11",18=>"P1-12",27=>"P1-13",22=>"P1-15",  23=>"P1-16",
    24=>"P1-18",10=>"P1-19", 9=>"P1-21",25=>"P1-22",  11=>"P1-23",
     8=>"P1-24", 7=>"P1-26", 5=>"P1-29", 6=>"P1-31",  12=>"P1-32",
    13=>"P1-33",19=>"P1-35",16=>"P1-36",26=>"P1-37",  20=>"P1-38",
    21=>"P1-40",
];

function sledGpioEntry($pinName, $desc, $command) {
    return [
        "pin"          => $pinName,
        "enabled"      => true,
        "mode"         => "gpio_pu",   // input with pull-up (active-low beam break)
        "desc"         => $desc,
        "debounceTime" => 50,          // 50 ms debounce
        // FPP requires both rising and falling keys to be present
        "rising"       => [
            "command"          => "",
            "multisyncCommand" => false,
            "multisyncHosts"   => "",
            "args"             => [],
        ],
        "falling"      => [
            "command"          => $command,
            "multisyncCommand" => false,
            "multisyncHosts"   => "",
            "args"             => [],
        ],
    ];
}

// ── Update FPP's gpio.json with SLED reserved-pin placeholders ───────────────
// The SLED daemon owns the letter/donation GPIO pins directly via RPi.GPIO.
// If FPP's GPIO plugin also activates those pins it claims them first (fppd
// starts before the daemon) and RPi.GPIO fails with EINVAL.
//
// Strategy: replace any existing SLED entries with DISABLED placeholders.
// Disabled entries appear in FPP's GPIO Inputs list (so users know the pins
// are reserved) but fppd never sets up edge detection for them, so the daemon
// can claim the pins safely.
$gpioMsg     = "";

// Go through FPP's configfile API rather than reading/writing
// /home/fpp/media/config directly -- gpio.json is FPP's shared GPIO config,
// not this plugin's own data.
function fppApiGetConfigFile($name) {
    $ctx = stream_context_create(["http" => ["timeout" => 5, "ignore_errors" => true]]);
    $result = @file_get_contents("http://localhost/api/configfile/$name", false, $ctx);
    return $result === false ? null : $result;
}
function fppApiPostConfigFile($name, $body) {
    $ctx = stream_context_create(["http" => [
        "method"  => "POST",
        "header"  => "Content-Type: text/plain\r\n",
        "content" => $body,
        "timeout" => 5,
        "ignore_errors" => true,
    ]]);
    $result = @file_get_contents("http://localhost/api/configfile/$name", false, $ctx);
    return $result !== false;
}

$gpioEntries = [];
$gpioRaw = fppApiGetConfigFile("gpio.json");
if ($gpioRaw !== null) {
    $gj = @json_decode($gpioRaw, true);
    if (is_array($gj)) $gpioEntries = $gj;
}

// Remove any existing SLED entries (enabled or disabled)
$gpioEntries = array_values(array_filter($gpioEntries, function($e) {
    return strpos($e["desc"] ?? "", "SLED") !== 0;
}));

// Add disabled placeholder entries for configured pins
$letterBcm   = $cfg["pins"]["letter"]    ?? null;
$donationBcm = $cfg["pins"]["donation"]  ?? null;
$special1Bcm = $cfg["pins"]["special_1"] ?? null;
$special2Bcm = $cfg["pins"]["special_2"] ?? null;

function sledGpioPlaceholder($pinName, $desc) {
    return [
        "pin"          => $pinName,
        "enabled"      => false,   // disabled — FPP will not claim this pin
        "mode"         => "gpio_pu",
        "desc"         => $desc,
        "debounceTime" => 50,
        "rising"       => ["command" => "", "multisyncCommand" => false, "multisyncHosts" => "", "args" => []],
        "falling"      => ["command" => "", "multisyncCommand" => false, "multisyncHosts" => "", "args" => []],
    ];
}

if ($letterBcm && isset($bcmToP1[$letterBcm])) {
    $gpioEntries[] = sledGpioPlaceholder($bcmToP1[$letterBcm], "SLED Letter Sensor — managed by SLED daemon (GPIO{$letterBcm})");
}
if ($donationBcm && isset($bcmToP1[$donationBcm])) {
    $gpioEntries[] = sledGpioPlaceholder($bcmToP1[$donationBcm], "SLED Donation Sensor — managed by SLED daemon (GPIO{$donationBcm})");
}

$sp1Label = $cfg["specials"]["special_1"]["label"] ?? "Special Message 1";
$sp2Label = $cfg["specials"]["special_2"]["label"] ?? "Special Message 2";
if ($special1Bcm && isset($bcmToP1[$special1Bcm])) {
    $gpioEntries[] = sledGpioPlaceholder($bcmToP1[$special1Bcm], "SLED {$sp1Label} — managed by SLED daemon (GPIO{$special1Bcm})");
}
if ($special2Bcm && isset($bcmToP1[$special2Bcm])) {
    $gpioEntries[] = sledGpioPlaceholder($bcmToP1[$special2Bcm], "SLED {$sp2Label} — managed by SLED daemon (GPIO{$special2Bcm})");
}

$gpioJson = json_encode(array_values($gpioEntries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (fppApiPostConfigFile("gpio.json", $gpioJson)) {
    $gpioMsg = " GPIO pins marked as reserved in FPP GPIO Inputs (disabled — SLED daemon owns them).";
} else {
    $gpioMsg = " Note: could not update GPIO config (check permissions).";
}

$warnMsg = $pinWarnings ? " ⚠ Pin warning: " . implode(" | ", $pinWarnings) : "";
respond(true, "Settings saved." . $gpioMsg . $warnMsg);
