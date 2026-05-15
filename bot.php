<?php
/**
 * BTC Faucet Auto Claim 24/7 — Ultimate Edition
 * 
 * Zero dependency (tanpa curl/pcntl/posix/readline)
 * Live cooldown timer + cool terminal UI
 * 
 * Usage:
 *   php bot.php
 *   php bot.php --lifetime=86400
 */

set_time_limit(0);
ini_set('memory_limit', '64M');
error_reporting(E_ERROR | E_PARSE);

$C = [
    'base'    => 'https://btc.tonrevenue.space',
    'cd'      => 299,
    'tick'    => 1,
    'maxretry'=> 5,
    'ua'      => 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
    'fp'      => json_encode(['version'=>'12.5.1','platform'=>'android','tg_platform'=>'android','language'=>'en','theme'=>'dark','screen_width'=>1080,'screen_height'=>2400,'online'=>true,'dpr'=>3]),
];

$lifetime = 0;
foreach ($argv ?? [] as $a) {
    if (preg_match('/--lifetime=(\d+)/', $a, $m)) $lifetime = (int)$m[1];
}

// ============================================================
// ANSI HELPERS
// ============================================================
const ESC = "\033[";
function ansi(string $code): string { return ESC . $code; }
function clr(): void { echo ansi('0m'); }
function c(int $code, int $mode = 0): void { echo ansi($mode . ';' . $code . 'm'); }
function cursor_hide(): void { echo ansi('?25l'); }
function cursor_show(): void { echo ansi('?25h'); }
function cursor_up(int $n = 1): void { echo ansi($n . 'A'); }
function clr_line(): void { echo ansi('2K'); echo "\r"; }
function bold(string $t): string { return ansi('1m') . $t . ansi('22m'); }
function dim(string $t): string { return ansi('2m') . $t . ansi('22m'); }

// ============================================================
// INPUT
// ============================================================
echo ansi('33m') . "
 ╔═══════════════════════════════════════════╗
 ║      BTC Faucet Auto Claim 24/7          ║
 ║      @fbtc0bot | +0.5 sats/5min         ║
 ╚═══════════════════════════════════════════╝
" . ansi('0m');

if (php_sapi_name() !== 'cli') die("CLI only.\n");

$init = '';
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
    echo ansi('36m') . " initData: " . ansi('0m');
    $init = trim(fgets(STDIN) ?: '');
}

if (empty($init)) {
    foreach ($argv ?? [] as $a) {
        if (preg_match('/--data=(.+)/', $a, $m)) { $init = $m[1]; break; }
    }
}

if (empty($init)) die(ansi('31m') . "InitData kosong!" . ansi('0m') . "\n");

// Normalize URL
if (strpos($init, 'tgWebAppData=') !== false) {
    if (preg_match('/tgWebAppData=(.+?)(?:&tgWebApp|$)/', $init, $m)) {
        $init = urldecode($m[1]);
    }
}

echo ansi('32m') . " OK " . ansi('0m') . "InitData (" . strlen($init) . " chars)\n";

// ============================================================
// HTTP POST (zero dep)
// ============================================================
function post(string $ep, array $extra = []): ?array {
    global $C, $init;
    $body = json_encode(array_merge(['initData' => $init], $extra));
    $hdr = implode("\r\n", [
        "Content-Type: application/json",
        "User-Agent: {$C['ua']}",
        'Origin: https://btc.tonrevenue.space',
        'Referer: https://btc.tonrevenue.space/',
        'Accept: application/json',
        'Accept-Encoding: identity',
        "Content-Length: " . strlen($body),
    ]);
    $ctx = stream_context_create([
        'http' => ['method'=>'POST','header'=>$hdr,'content'=>$body,'timeout'=>30,'ignore_errors'=>true],
        'ssl'  => ['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true],
    ]);
    $raw = @file_get_contents("{$C['base']}{$ep}", false, $ctx);
    if ($raw === false) return null;
    $h = $http_response_header ?? [];
    foreach ($h as $x) { if (stripos($x, 'Content-Encoding: gzip') !== false) { $raw = @gzdecode($raw); break; } }
    $d = @json_decode($raw, true);
    if (!is_array($d)) return null;
    if (isset($d['detail'])) return ['_err' => (string)$d['detail']];
    return $d;
}

// ============================================================
// CAPTCHA SOLVER — "Tap the X" → option id
// ============================================================
function solve_cap(array $ch): ?string {
    $prompt = strtolower($ch['prompt'] ?? '');
    $opts   = $ch['options'] ?? [];
    if (!$prompt || !$opts) return null;

    // "tap the grapes" → extract "grapes"
    $w = preg_split('/\s+/', preg_replace('/[^a-z\s]/', '', $prompt), -1, PREG_SPLIT_NO_EMPTY);
    $stop = ['tap','click','the','a','an','select','choose','find','pick','button','icon','image','picture','that','which','is','in','on','of','to','with','this','shown','below','above','and','or'];
    $kw = array_values(array_filter($w, fn($x) => strlen($x) > 1 && !in_array($x, $stop)));

    // Score-based match
    foreach ($opts as $o) {
        $lbl = strtolower($o['label'] ?? '');
        $id  = strtolower($o['id'] ?? '');
        foreach ($kw as $k) {
            if ($lbl === $k || $id === $k || ($k && strpos($lbl, $k) !== false)) return $o['id'];
        }
    }
    // Fuzzy
    foreach ($opts as $o) {
        $lbl = strtolower($o['label'] ?? '');
        foreach ($kw as $k) {
            if ($k && function_exists('levenshtein') && levenshtein($lbl, $k) <= 1) return $o['id'];
        }
    }
    return $opts[0]['id'] ?? null;
}

// ============================================================
// LIVE COOLDOWN DISPLAY
// ============================================================
function live_cd(int $seconds, float $bal, int $claims, int $fails, float $rate, float $mem): void {
    global $lifetime;
    $total = $seconds;
    $elapsed_start = time();

    while ($seconds > 0) {
        // Lifetime check
        if ($lifetime > 0 && (time() - $GLOBALS['t0']) >= $lifetime) break;

        $m = (int)floor($seconds / 60);
        $s = $seconds % 60;
        $pct = $total > 0 ? (int)((($total - $seconds) / $total) * 100) : 0;

        // Progress bar
        $bar_w = 30;
        $filled = (int)(($pct / 100) * $bar_w);
        $bar = str_repeat('━', $filled) . str_repeat('─', $bar_w - $filled);

        $color = $seconds > 30 ? '33' : '35'; // yellow > purple near end

        // Move cursor up 1 line and clear
        echo ansi('1A') . ansi('2K') . "\r";

        // Build status line
        $up_h = (int)floor((time() - $GLOBALS['t0']) / 3600);
        $up_m = (int)floor(((time() - $GLOBALS['t0']) % 3600) / 60);

        echo ansi($color . 'm');
        echo "  ⏳ {$m}:" . str_pad($s, 2, '0', STR_PAD_LEFT);
        echo " ┃ {$bar} {$pct}%";
        echo " ┃ Bal:" . ansi('1;33m') . " {$bal}" . ansi($color . 'm');
        echo " ┃ +" . ansi('1;32m') . "{$claims}" . ansi($color . 'm');
        echo " ✗{$fails}";
        echo " ┃ {$rate}/hr";
        echo " ┃ {$mem}MB";
        echo " ┃ UP {$up_h}h{$up_m}m";
        echo ansi('0m');

        sleep(1);
        $seconds--;
    }

    // Final clear
    echo ansi('1A') . ansi('2K') . "\r";
    echo "  " . ansi('32m') . "✅ READY" . ansi('0m') . " — claiming now...              \n";
}

// ============================================================
// MAIN
// ============================================================
$retries = 0; $claims = 0; $fails = 0;
$cap_ok_until = 0;
$t0 = time();

cursor_hide();

echo "\n";

while (true) {
    if ($lifetime > 0 && (time() - $t0) >= $lifetime) {
        echo "  " . ansi('33m') . "⏰ Lifetime reached" . ansi('0m') . "\n";
        break;
    }

    $mem  = round(memory_get_usage(true) / 1048576, 1);
    $up   = time() - $t0;
    $rate = $up > 0 ? round(($claims / $up) * 3600, 1) : 0;

    // ─── INIT ───
    echo "  " . ansi('36m') . "⟳ Init..." . ansi('0m');
    $resp = post('/api/init', ['fingerprint' => $C['fp']]);

    if ($resp === null) {
        $retries++; $fails++;
        $d = 5 * pow(2, min($retries, 5));
        echo ansi('1A') . ansi('2K') . "\r";
        echo "  " . ansi('31m') . "✗ Network error, retry {$retries}/{$C['maxretry']} ({$d}s)" . ansi('0m') . "\n";
        if ($retries >= $C['maxretry']) { $retries = 0; sleep(60); } else sleep((int)$d);
        continue;
    }

    if (isset($resp['_err'])) {
        $msg = $resp['_err'];
        if (stripos($msg, 'cooldown') !== false) {
            if (preg_match('/(\d+)\s*s/i', $msg, $m)) {
                $w = (int)$m[1] + 2;
                echo ansi('1A') . ansi('2K') . "\r";
                $user = $resp['_raw'] ?? [];
                $bal = (float)($user['user']['balance'] ?? $user['balance'] ?? 0);
                echo "  " . ansi('35m') . "⏳ Server cooldown: {$w}s" . ansi('0m') . "\n";
                live_cd($w, $bal, $claims, $fails, $rate, $mem);
                $retries = 0;
                continue;
            }
        }
        echo ansi('1A') . ansi('2K') . "\r";
        echo "  " . ansi('31m') . "✗ {$msg}" . ansi('0m') . "\n";
        if (stripos($msg, 'auth') !== false || stripos($msg, 'invalid') !== false || stripos($msg, 'expired') !== false) break;
        $retries++; $fails++;
        if ($retries >= $C['maxretry']) { $retries = 0; sleep(60); } else sleep(10);
        continue;
    }

    $user = $resp['user'] ?? [];
    $bal  = (float)($user['balance'] ?? 0);
    $cd   = (int)($user['cooldown'] ?? 0);
    $risk = (float)($user['risk_score'] ?? 0);

    if (!empty($user['is_blocked'])) {
        echo ansi('1A') . ansi('2K') . "\r";
        echo "  " . ansi('31m') . "🚫 BLOCKED: " . ($user['ban_reason'] ?? '') . ansi('0m') . "\n";
        break;
    }

    $cap_req = !empty($user['captcha_required']);

    echo ansi('1A') . ansi('2K') . "\r";
    echo "  " . ansi('32m') . "✓" . ansi('0m') . " Bal:" . ansi('33m') . " {$bal}" . ansi('0m') . " CD:{$cd}s Risk:{$risk}\n";

    // ─── CAPTCHA ───
    $need_cap = $cap_req && time() >= $cap_ok_until;

    if ($need_cap) {
        echo "  " . ansi('36m') . "🔒 Solving captcha..." . ansi('0m') . "\n";

        $challenge = $user['captcha_challenge'] ?? null;
        if (!$challenge) {
            $cr = post('/api/captcha/challenge');
            if ($cr && !isset($cr['_err'])) $challenge = $cr;
        }

        if ($challenge) {
            $prompt = $challenge['prompt'] ?? '?';
            $answer = solve_cap($challenge);
            $cid    = $challenge['challenge_id'] ?? '';

            $emoji_map = [];
            foreach ($challenge['options'] ?? [] as $o) {
                $emoji_map[$o['id'] ?? ''] = $o['emoji'] ?? '';
            }
            $emo = $emoji_map[$answer] ?? '';

            echo "  " . dim("   Q: \"{$prompt}\"") . "\n";
            echo "  " . dim("   A: {$emo} {$answer}") . "\n";

            if ($answer) {
                $v = post('/api/captcha/verify', ['challenge_id' => $cid, 'answer' => $answer]);
                if ($v && ($v['status'] ?? '') === 'success') {
                    $cap_ok_until = strtotime($v['captcha_valid_until'] ?? '+6 hours');
                    $vh = round(($cap_ok_until - time()) / 3600, 1);
                    echo "  " . ansi('32m') . "🔓 Captcha OK! Valid {$vh}h" . ansi('0m') . "\n";
                } else {
                    $em = $v['_err'] ?? ($v['detail'] ?? 'fail');
                    echo "  " . ansi('31m') . "✗ Captcha: {$em}" . ansi('0m') . "\n";
                    sleep(30); $fails++; continue;
                }
            } else {
                echo "  " . ansi('31m') . "✗ Cannot solve captcha" . ansi('0m') . "\n";
                sleep(30); $fails++; continue;
            }
        } else {
            echo "  " . ansi('31m') . "✗ No captcha challenge" . ansi('0m') . "\n";
            sleep(30); $fails++; continue;
        }
    } else {
        $rh = $cap_ok_until > 0 ? round(($cap_ok_until - time()) / 3600, 1) : 0;
        if ($cap_req && $rh > 0) {
            echo "  " . dim("🔓 Captcha valid ({$rh}h)") . "\n";
        }
    }

    // ─── COOLDOWN CHECK ───
    if ($cd > 0) {
        echo "  " . ansi('35m') . "⏳ Cooldown {$cd}s..." . ansi('0m') . "\n";
        live_cd($cd, $bal, $claims, $fails, $rate, $mem);
        $retries = 0;
        continue;
    }

    // ─── CLAIM ───
    echo "  " . ansi('33m') . "💰 Claiming..." . ansi('0m');
    $claim = post('/api/claim');

    if ($claim === null) {
        echo ansi('1A') . ansi('2K') . "\r";
        echo "  " . ansi('31m') . "✗ Network error" . ansi('0m') . "\n";
        $retries++; $fails++; sleep(15); continue;
    }

    if (isset($claim['_err'])) {
        $msg = $claim['_err'];
        echo ansi('1A') . ansi('2K') . "\r";
        if (stripos($msg, 'captcha') !== false) {
            $cap_ok_until = 0;
            echo "  " . ansi('33m') . "🔄 Captcha expired, re-solving..." . ansi('0m') . "\n";
            sleep(3); continue;
        }
        if (preg_match('/(\d+)\s*s/i', $msg, $m)) {
            $w = (int)$m[1] + 2;
            echo "  " . ansi('35m') . "⏳ Cooldown: {$w}s" . ansi('0m') . "\n";
            live_cd($w, $bal, $claims, $fails, $rate, $mem);
            $retries = 0; continue;
        }
        echo "  " . ansi('31m') . "✗ Claim: {$msg}" . ansi('0m') . "\n";
        $retries++; $fails++; sleep(10); continue;
    }

    $suid = $claim['session_uid'] ?? '';
    if (empty($suid)) {
        echo ansi('1A') . ansi('2K') . "\r";
        echo "  " . ansi('31m') . "✗ No session_uid" . ansi('0m') . "\n";
        $retries++; $fails++; sleep(10); continue;
    }

    // ─── CONFIRM ───
    $conf = post('/api/claim/confirm', ['session_uid' => $suid, 'token' => '']);

    if ($conf === null || isset($conf['_err'])) {
        $msg = $conf['_err'] ?? 'network';
        echo ansi('1A') . ansi('2K') . "\r";
        echo "  " . ansi('31m') . "✗ Confirm: {$msg}" . ansi('0m') . "\n";
        $fails++; sleep(10); continue;
    }

    // ─── SUCCESS ───
    $new_bal = (float)($conf['new_balance'] ?? $bal);
    $reward  = (float)($conf['reward'] ?? 0.5);
    $cd      = (int)($conf['cooldown'] ?? $C['cd']);
    $claims++;
    $retries = 0;
    $fails   = 0;

    $up   = time() - $t0;
    $rate = $up > 0 ? round(($claims / $up) * 3600, 1) : 0;
    $mem  = round(memory_get_usage(true) / 1048576, 1);

    echo ansi('1A') . ansi('2K') . "\r";
    echo "  " . ansi('32m') . "🪙 +" . ansi('1m') . "{$reward}" . ansi('22m') . " sats" . ansi('0m');
    echo "  Bal:" . ansi('33m') . " {$new_bal}" . ansi('0m');
    echo "  CD:" . ansi('35m') . "{$cd}s" . ansi('0m');
    echo "  #" . ansi('1m') . "{$claims}" . ansi('0m');
    echo "  " . dim("{$rate}/hr {$mem}MB") . "\n";

    // Live cooldown
    live_cd($cd, $new_bal, $claims, $fails, $rate, $mem);

    if ($claims % 10 === 0) @gc_collect_cycles();
}

cursor_show();
$up = time() - $t0;
echo "\n  " . ansi('33m') . "═══ Stopped: {$claims} claims, {$fails} fails, " . round($up/60) . "min ═══" . ansi('0m') . "\n\n";
