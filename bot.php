<?php
/**
 * BTC Faucet Auto Claim 24/7 — v3 Final
 *
 * Zero dependency (tanpa curl/pcntl/posix/readline)
 * Mobile detection bypassed
 * Captcha auto-solve (Tap the X)
 * Live countdown timer + cool terminal UI
 *
 * Usage:
 *   php bot.php
 *   echo "initData..." | php bot.php
 *   php bot.php --lifetime=86400
 */

set_time_limit(0);
ini_set('memory_limit', '64M');
error_reporting(E_ERROR | E_PARSE);

if (php_sapi_name() !== 'cli') die("CLI only.\n");

// ═══════════════════════════════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════════════════════════════
$CFG = [
    'base'     => 'https://btc.tonrevenue.space',
    'cooldown' => 299,
    'maxretry' => 5,
    'ua'       => 'Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.230 Mobile Safari/537.36',
    // Fingerprint JS visitor ID (random per install)
    'fp_id'    => 'd4e5f6a7b8c90123',
    // Device fingerprint fields (spread at top level in request)
    'device'   => [
        'screen'           => '1080x2400',
        'lang'             => 'id',
        'tz'               => 'Asia/Jakarta',
        'platform'         => 'Linux armv8l',
        'tg_platform'      => 'android',
        'viewport_width'   => 412,
        'viewport_height'  => 915,
        'max_touch_points' => 5,
        'device_pixel_ratio' => 3,
    ],
];

$lifetime = 0;
foreach ($argv ?? [] as $a) {
    if (preg_match('/--lifetime=(\d+)/', $a, $m)) $lifetime = (int)$m[1];
}

// ═══════════════════════════════════════════════════════════════
// ANSI COLORS
// ═══════════════════════════════════════════════════════════════
const E = "\033[";
function ansi(string $c): string { return E . $c; }
function bold(string $t): string { return ansi('1m') . $t . ansi('22m'); }
function dim(string $t): string { return ansi('2m') . $t . ansi('22m'); }
function green(string $t): string { return ansi('32m') . $t . ansi('0m'); }
function red(string $t): string { return ansi('31m') . $t . ansi('0m'); }
function yellow(string $t): string { return ansi('33m') . $t . ansi('0m'); }
function cyan(string $t): string { return ansi('36m') . $t . ansi('0m'); }
function purple(string $t): string { return ansi('35m') . $t . ansi('0m'); }

function cursor_hide(): void { echo ansi('?25l'); }
function cursor_show(): void { echo ansi('?25h'); }

function clear_line(): void {
    echo ansi('2K') . "\r";
}

function move_up(int $n = 1): void {
    echo ansi($n . 'A');
}

// ═══════════════════════════════════════════════════════════════
// BANNER
// ═══════════════════════════════════════════════════════════════
function show_banner(): void {
    echo ansi('1m') . ansi('33m') . "
  ┌──────────────────────────────────────────┐
  │     ₿ BTC FAUCET AUTO CLAIM 24/7        │
  │     @fbtc0bot │ +0.5 sats / 5min       │
  └──────────────────────────────────────────┘\n" . ansi('0m');
}

function show_stats_header(): void {
    echo ansi('2m') . "  ┌─────────────────────────────────────────────────────┐\n" . ansi('0m');
}

function show_stats_footer(): void {
    echo ansi('2m') . "  └─────────────────────────────────────────────────────┘" . ansi('0m') . "\n";
}

function show_banner_small(): void {
    echo ansi('1;33m') . "  ₿ BTC FAUCET" . ansi('0m') . " │ ";
}

// ═══════════════════════════════════════════════════════════════
// INPUT
// ═══════════════════════════════════════════════════════════════
show_banner();

$init = '';

// Try stdin pipe first
$stdin = fopen('php://stdin', 'r');
if ($stdin) {
    $r = [$stdin]; $w = $e = null;
    if (stream_select($r, $w, $e, 0) > 0) {
        while (!feof($stdin)) {
            $ch = fread($stdin, 8192);
            if ($ch === false || $ch === '') break;
            $init .= $ch;
        }
        $init = trim($init);
    }
    fclose($stdin);
}

if (empty($init)) {
    // Try --data= argument
    foreach ($argv ?? [] as $a) {
        if (preg_match('/--data=(.+)/', $a, $m)) { $init = $m[1]; break; }
    }
}

if (empty($init)) {
    echo "  " . cyan(" initData: ") ;
    $init = trim(fgets(STDIN) ?: '');
}

if (empty($init)) die(red("  ✗ InitData kosong!\n"));

// Normalize tgWebAppData format
if (strpos($init, 'tgWebAppData=') !== false) {
    if (preg_match('/tgWebAppData=(.+?)(?:&tgWebApp|$)/', $init, $m)) {
        $init = urldecode($m[1]);
    }
}

echo "  " . green("✓") . " InitData loaded (" . strlen($init) . " chars)\n";
echo "  " . dim("  Mobile: Android SM-G991B │ Platform: android") . "\n\n";

// ═══════════════════════════════════════════════════════════════
// HTTP POST (zero dependency - file_get_contents only)
// ═══════════════════════════════════════════════════════════════
function http_post(string $endpoint, array $body = []): ?array {
    global $CFG, $init;

    // Build body: device fields ONLY for /api/init
    $payload = ['initData' => $init];
    if ($endpoint === '/api/init') {
        $payload['fingerprint'] = $CFG['fp_id'];
        $payload = array_merge($payload, $CFG['device']);
    }
    $payload = array_merge($payload, $body);

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    $headers = [
        "Content-Type: application/json",
        "User-Agent: {$CFG['ua']}",
        'Origin: https://btc.tonrevenue.space',
        'Referer: https://btc.tonrevenue.space/',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'sec-ch-ua-mobile: ?1',
        'sec-ch-ua-platform: "Android"',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-origin',
        'Content-Length: ' . strlen($json),
    ];

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $json,
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ]);

    $raw = @file_get_contents($CFG['base'] . $endpoint, false, $ctx);

    if ($raw === false) return null;

    // Handle gzip
    $resp_headers = $http_response_header ?? [];
    foreach ($resp_headers as $h) {
        if (stripos($h, 'Content-Encoding: gzip') !== false) {
            $raw = @gzdecode($raw);
            break;
        }
    }

    $data = @json_decode($raw, true);
    if (!is_array($data)) return null;
    if (isset($data['detail'])) {
        if (is_array($data['detail'])) {
            $msg = $data['detail'][0]['msg'] ?? json_encode($data['detail']);
        } else {
            $msg = (string)$data['detail'];
        }
        return ['_err' => $msg];
    }
    return $data;
}

// ═══════════════════════════════════════════════════════════════
// CAPTCHA SOLVER — "Tap the X" keyword matching
// ═══════════════════════════════════════════════════════════════
function solve_captcha(array $challenge): ?string {
    $prompt = strtolower($challenge['prompt'] ?? '');
    $opts   = $challenge['options'] ?? [];

    if (!$prompt || !$opts) return null;

    // Extract keywords from prompt, remove stop words
    $words = preg_split('/\s+/', preg_replace('/[^a-z\s]/', '', $prompt), -1, PREG_SPLIT_NO_EMPTY);
    $stop_words = ['tap','click','the','a','an','select','choose','find','pick',
                   'button','icon','image','picture','that','which','is','in',
                   'on','of','to','with','this','shown','below','above','and','or'];

    $keywords = array_values(array_filter($words, function($w) use ($stop_words) {
        return strlen($w) > 1 && !in_array($w, $stop_words);
    }));

    // Score-based matching against option labels and IDs
    foreach ($opts as $opt) {
        $label = strtolower($opt['label'] ?? '');
        $id    = strtolower($opt['id'] ?? '');
        foreach ($keywords as $kw) {
            if ($label === $kw || $id === $kw || ($kw && strpos($label, $kw) !== false)) {
                return $opt['id'];
            }
        }
    }

    // Fuzzy fallback
    foreach ($opts as $opt) {
        $label = strtolower($opt['label'] ?? '');
        foreach ($keywords as $kw) {
            if ($kw && function_exists('levenshtein') && levenshtein($label, $kw) <= 2) {
                return $opt['id'];
            }
        }
    }

    // Last resort: first option
    return $opts[0]['id'] ?? null;
}

// ═══════════════════════════════════════════════════════════════
// LIVE COOLDOWN TIMER (overwrite same line, ticking down)
// ═══════════════════════════════════════════════════════════════
function live_countdown(int $total_secs, float $balance, int $total_claims, int $total_fails, float $rate, int $start_time): void {
    global $lifetime;

    $bar_width = 30;
    $remaining = $total_secs;

    while ($remaining > 0) {
        // Lifetime check
        if ($lifetime > 0 && (time() - $start_time) >= $lifetime) break;

        $min  = (int)floor($remaining / 60);
        $sec  = $remaining % 60;
        $pct  = $total_secs > 0 ? (int)((($total_secs - $remaining) / $total_secs) * 100) : 0;
        $fill = (int)(($pct / 100) * $bar_width);
        $bar  = ansi('32m') . str_repeat('█', $fill) . ansi('90m') . str_repeat('░', $bar_width - $fill) . ansi('0m');

        $up_sec = time() - $start_time;
        $up_h   = (int)floor($up_sec / 3600);
        $up_m   = (int)floor(($up_sec % 3600) / 60);

        // Color changes as timer counts down
        if ($remaining > 60) {
            $time_color = '36'; // cyan
        } elseif ($remaining > 15) {
            $time_color = '33'; // yellow
        } else {
            $time_color = '35'; // purple/magenta
        }

        // Move up 2 lines (stats footer + this line), clear, rewrite
        move_up(2);
        clear_line();
        echo "  ┃ " . ansi($time_color) . "  ⏱ " . bold(sprintf("%d:%02d", $min, $sec)) . ansi('0m');
        echo "  " . $bar . " " . ansi('37m') . sprintf("%3d%%", $pct) . ansi('0m');
        echo "  " . dim("│ UP " . sprintf("%dh%02dm", $up_h, $up_m));
        echo "  " . dim("│ " . $rate . "/hr") . ansi('0m') . "\n";

        clear_line();
        echo "  ┃ Bal: " . ansi('1;33m') . sprintf("%.1f", $balance) . ansi('0m') . " sats";
        echo "  │ Claims: " . ansi('1;32m') . $total_claims . ansi('0m');
        echo "  │ Fails: " . ansi('31m') . $total_fails . ansi('0m');
        echo "  │ Mem: " . dim(round(memory_get_usage(true) / 1048576, 1) . "MB") . ansi('0m') . "\n";
        show_stats_footer();

        sleep(1);
        $remaining--;
    }

    // Final: clear and show ready
    move_up(2);
    clear_line();
    echo "  ┃ " . green(bold("  ✓ READY — Claiming now...")) . "                          \n";
    clear_line();
    show_stats_footer();
}

// ═══════════════════════════════════════════════════════════════
// MAIN LOOP
// ═══════════════════════════════════════════════════════════════
$claims = 0;
$fails  = 0;
$retry  = 0;
$t0     = time();
$cap_valid_until = 0; // timestamp when captcha expires

cursor_hide();
show_stats_header();
echo "  ┃ " . cyan("  ⟳ Starting...") . "\n";
show_stats_footer();

while (true) {
    // Lifetime check
    if ($lifetime > 0 && (time() - $t0) >= $lifetime) {
        move_up(2); clear_line();
        echo "  ┃ " . yellow("⏰ Lifetime reached (" . $lifetime . "s)") . "\n";
        show_stats_footer();
        break;
    }

    $mem = round(memory_get_usage(true) / 1048576, 1);
    $elapsed = time() - $t0;
    $rate = $elapsed > 0 ? round(($claims / $elapsed) * 3600, 1) : 0;

    // ─── 1. INIT ───
    move_up(2); clear_line();
    echo "  ┃ " . cyan("  ⟳ Initializing...") . "\n";
    show_stats_footer();

    $resp = http_post('/api/init');

    if ($resp === null) {
        $retry++; $fails++;
        $delay = min(5 * pow(2, $retry), 60);
        move_up(2); clear_line();
        echo "  ┃ " . red("  ✗ Network error (retry " . min($retry, $CFG['maxretry']) . "/" . $CFG['maxretry'] . ", " . $delay . "s)") . "\n";
        show_stats_footer();
        if ($retry >= $CFG['maxretry']) { $retry = 0; sleep(60); } else sleep((int)$delay);
        continue;
    }

    if (isset($resp['_err'])) {
        $msg = $resp['_err'];
        move_up(2); clear_line();

        // Check for cooldown in error
        if (preg_match('/(\d+)\s*second/i', $msg, $m)) {
            $wait = (int)$m[1] + 3;
            echo "  ┃ " . purple("  ⏳ Server cooldown: " . $wait . "s") . "\n";
            show_stats_footer();
            live_countdown($wait, 0, $claims, $fails, $rate, $t0);
            $retry = 0;
            continue;
        }

        // Auth error = stop
        if (stripos($msg, 'auth') !== false || stripos($msg, 'invalid') !== false || stripos($msg, 'expired') !== false) {
            echo "  ┃ " . red("  ✗ Auth error: " . $msg) . " — stopping\n";
            show_stats_footer();
            break;
        }

        echo "  ┃ " . red("  ✗ Init: " . $msg) . "\n";
        show_stats_footer();
        $retry++; $fails++;
        if ($retry >= $CFG['maxretry']) { $retry = 0; sleep(60); } else sleep(10);
        continue;
    }

    // Parse response
    $user  = $resp['user'] ?? [];
    $bal   = (float)($user['balance'] ?? 0);
    $cd    = (int)($user['cooldown'] ?? 0);
    $risk  = (float)($user['risk_score'] ?? 0);
    $cap_req = !empty($user['captcha_required']);
    $blocked = !empty($user['is_blocked']);

    $access = $resp['access'] ?? [];
    $device_kind = $access['device_kind'] ?? 'unknown';
    $mobile_blocked = !empty($access['mobile_only_blocked']);

    move_up(2); clear_line();

    if ($blocked) {
        echo "  ┃ " . red("  🚫 BLOCKED: " . ($user['ban_reason'] ?? 'unknown')) . "\n";
        show_stats_footer();
        break;
    }

    // Check if mobile detection failed
    if ($mobile_blocked) {
        echo "  ┃ " . red("  📱 Mobile only blocked (device: " . $device_kind . ")") . "\n";
        show_stats_footer();
        sleep(30);
        continue;
    }

    echo "  ┃ " . green("  ✓ Init OK") . dim(" │ Bal: " . $bal . " │ CD: " . $cd . "s │ Risk: " . $risk . " │ " . $device_kind) . "\n";
    show_stats_footer();

    // ─── 2. CAPTCHA (solve if required & expired) ───
    $need_captcha = $cap_req && time() >= $cap_valid_until;

    if ($need_captcha) {
        // Get captcha challenge
        move_up(2); clear_line();
        echo "  ┃ " . purple("  🔒 Getting captcha...") . "\n";
        show_stats_footer();

        $challenge = $user['captcha_challenge'] ?? null;

        // Fetch fresh challenge if not in init response
        if (!$challenge) {
            $cr = http_post('/api/captcha/challenge');
            if ($cr && !isset($cr['_err'])) {
                $challenge = $cr['challenge'] ?? $cr;
            }
        }

        if (!$challenge) {
            move_up(2); clear_line();
            echo "  ┃ " . red("  ✗ No captcha challenge") . "\n";
            show_stats_footer();
            $fails++; sleep(15); continue;
        }

        // Solve captcha
        $prompt    = $challenge['prompt'] ?? '?';
        $cid       = $challenge['challenge_id'] ?? '';
        $answer    = solve_captcha($challenge);
        $opts      = $challenge['options'] ?? [];

        // Find emoji for display
        $emoji = '';
        foreach ($opts as $o) {
            if (($o['id'] ?? '') === $answer) { $emoji = $o['emoji'] ?? ''; break; }
        }

        move_up(2); clear_line();
        echo "  ┃ " . purple("  🔒 " . $prompt) . " → " . ansi('1m') . $emoji . " " . ($answer ?? '?') . ansi('0m') . "\n";
        show_stats_footer();

        if (!$answer) {
            move_up(2); clear_line();
            echo "  ┃ " . red("  ✗ Cannot solve captcha") . "\n";
            show_stats_footer();
            $fails++; sleep(30); continue;
        }

        // Verify answer
        $v = http_post('/api/captcha/verify', [
            'challenge_id' => $cid,
            'answer'       => $answer,
        ]);

        if ($v && ($v['status'] ?? '') === 'success') {
            $cap_valid_until = strtotime($v['captcha_valid_until'] ?? '+6 hours');
            $valid_h = round(($cap_valid_until - time()) / 3600, 1);
            move_up(2); clear_line();
            echo "  ┃ " . green("  🔓 Captcha solved! Valid " . $valid_h . "h") . "\n";
            show_stats_footer();
            sleep(1);
        } else {
            $em = $v['_err'] ?? ($v['detail'] ?? 'wrong answer');
            move_up(2); clear_line();
            echo "  ┃ " . red("  ✗ Captcha failed: " . $em) . "\n";
            show_stats_footer();
            $fails++; sleep(30); continue;
        }
    } else {
        $rh = $cap_valid_until > 0 ? round(($cap_valid_until - time()) / 3600, 1) : 0;
        if ($cap_req && $rh > 0) {
            move_up(2); clear_line();
            echo "  ┃ " . dim("  🔓 Captcha cached (" . $rh . "h left)") . "\n";
            show_stats_footer();
            sleep(1);
        }
    }

    // ─── 3. COOLDOWN CHECK ───
    if ($cd > 0) {
        live_countdown($cd, $bal, $claims, $fails, $rate, $t0);
        $retry = 0;
        continue;
    }

    // ─── 4. CLAIM ───
    move_up(2); clear_line();
    echo "  ┃ " . yellow("  💰 Claiming...") . "\n";
    show_stats_footer();

    $claim = http_post('/api/claim');

    if ($claim === null) {
        move_up(2); clear_line();
        echo "  ┃ " . red("  ✗ Network error on claim") . "\n";
        show_stats_footer();
        $retry++; $fails++; sleep(15); continue;
    }

    if (isset($claim['_err'])) {
        $msg = $claim['_err'];
        move_up(2); clear_line();

        // Captcha expired → re-solve
        if (stripos($msg, 'captcha') !== false) {
            $cap_valid_until = 0;
            echo "  ┃ " . yellow("  🔄 Captcha expired, re-solving...") . "\n";
            show_stats_footer();
            sleep(2); continue;
        }

        // Cooldown in error
        if (preg_match('/(\d+)\s*second/i', $msg, $m)) {
            $wait = (int)$m[1] + 3;
            echo "  ┃ " . purple("  ⏳ Claim cooldown: " . $wait . "s") . "\n";
            show_stats_footer();
            live_countdown($wait, $bal, $claims, $fails, $rate, $t0);
            $retry = 0; continue;
        }

        echo "  ┃ " . red("  ✗ Claim: " . $msg) . "\n";
        show_stats_footer();
        $retry++; $fails++; sleep(10); continue;
    }

    $suid = $claim['session_uid'] ?? '';
    $claim_status = $claim['status'] ?? '';

    if (empty($suid)) {
        move_up(2); clear_line();
        echo "  ┃ " . red("  ✗ No session_uid (status: " . $claim_status . ")") . "\n";
        show_stats_footer();
        $retry++; $fails++; sleep(10); continue;
    }

    // ─── 5. CONFIRM (bypass ad - confirm directly) ───
    move_up(2); clear_line();
    echo "  ┃ " . yellow("  ⚡ Confirming...") . dim(" (bypass ad)") . "\n";
    show_stats_footer();

    $conf = http_post('/api/claim/confirm', ['session_uid' => $suid]);

    if ($conf === null || isset($conf['_err'])) {
        $msg = $conf['_err'] ?? 'network error';
        move_up(2); clear_line();
        echo "  ┃ " . red("  ✗ Confirm: " . $msg) . "\n";
        show_stats_footer();
        $fails++; sleep(10); continue;
    }

    // ─── 6. SUCCESS ───
    $new_bal = (float)($conf['new_balance'] ?? $bal);
    $reward  = (float)($conf['reward'] ?? 0.5);
    $next_cd = (int)($conf['cooldown'] ?? $CFG['cooldown']);
    $claims++;
    $retry = 0;

    // Update rate
    $elapsed = time() - $t0;
    $rate = $elapsed > 0 ? round(($claims / $elapsed) * 3600, 1) : 0;

    move_up(2); clear_line();
    echo "  ┃ " . green(bold("  🪙 +" . $reward . " sats")) . dim(" │ New bal: " . $new_bal . " │ #" . $claims . " │ " . $rate . "/hr") . "\n";
    show_stats_footer();

    // Live cooldown timer
    live_countdown($next_cd, $new_bal, $claims, $fails, $rate, $t0);

    // Periodic GC
    if ($claims % 10 === 0) @gc_collect_cycles();
}

// ═══════════════════════════════════════════════════════════════
// CLEANUP
// ═══════════════════════════════════════════════════════════════
cursor_show();

$total_time = time() - $t0;
$total_min  = (int)floor($total_time / 60);

echo "\n  " . ansi('1;33m') . "═════════════════════════════════════════════" . ansi('0m') . "\n";
echo "  " . bold("  SUMMARY") . "\n";
echo "  " . dim("  Claims: " . $claims . " │ Fails: " . $fails . " │ Time: " . $total_min . " min") . "\n";
echo "  " . dim("  Final balance: " . ($new_bal ?? 0) . " sats") . "\n";
echo "  " . ansi('1;33m') . "═════════════════════════════════════════════" . ansi('0m') . "\n\n";
