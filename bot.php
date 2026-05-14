<?php
/**
 * BTC Faucet Auto Claim 24/7 - Optimized Single File
 *
 * Usage:
 *   php bot.php                          # Input initData, loop forever
 *   php bot.php --lifetime=86400         # Jalan 24 jam
 *   echo "initdata" | php bot.php        # Pipe input
 */

set_time_limit(0);
ini_set('memory_limit', '64M');

// ============================================================
// SIGNAL HANDLING
// ============================================================
$STOP = false;
if (function_exists('pcntl_async_signals')) pcntl_async_signals(true);
if (function_exists('pcntl_signal')) {
    $h = function() { global $STOP; $STOP = true; };
    pcntl_signal(SIGINT,  $h);
    pcntl_signal(SIGTERM, $h);
    pcntl_signal(SIGHUP,  $h);
}

// ============================================================
// CONFIG
// ============================================================
$BASE     = 'https://btc.tonrevenue.space';
$CD_SEC   = 299;       // cooldown server
$TICK     = 3;         // interruptible sleep interval
$MAX_RETR = 5;         // max consecutive error retry
$UA       = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

$lifetime = 0;
$once     = false;
foreach ($argv as $a) {
    if (preg_match('/--lifetime=(\d+)/', $a, $m)) $lifetime = (int)$m[1];
    if ($a === '--once') $once = true;
}

// ============================================================
// BANNER + INPUT
// ============================================================
fwrite(STDERR, "\033[33m");
fwrite(STDERR, "╔══════════════════════════════════════╗\n");
fwrite(STDERR, "║   BTC Faucet Auto Claim 24/7        ║\n");
fwrite(STDERR, "║   @fbtc0bot | +0.5 sats/claim       ║\n");
fwrite(STDERR, "╚══════════════════════════════════════╝\n");
fwrite(STDERR, "\033[0m\n");

if (php_sapi_name() !== 'cli') die("CLI only.\n");

$init = '';
if (!posix_isatty(STDIN)) {
    $init = trim(stream_get_contents(STDIN));
}
if (empty($init)) {
    fwrite(STDERR, "\033[36mPaste initData:\033[0m ");
    $init = trim(readline("> "));
}
if (empty($init)) die("InitData kosong!\n");

// Normalize: strip URL prefix kalau user paste full URL
if (preg_match('/tgWebAppData=(.+?)(?:&tgWebApp|$)/', $init, $m)) {
    $init = urldecode($m[1]);
}
fwrite(STDERR, "\033[32m[OK]\033[0m InitData loaded (" . strlen($init) . " chars)\n");
if ($lifetime > 0) fwrite(STDERR, "Lifetime: {$lifetime}s (" . round($lifetime/60) . " min)\n");
fwrite(STDERR, "\n");

// ============================================================
// LOG (ke stderr biar pipe ke file clean)
// ============================================================
function L(string $msg): void {
    $t = date('H:i:s');
    fwrite(STDERR, "[{$t}] {$msg}\n");
}

// ============================================================
// HTTP POST — kirim initData di body JSON
// ============================================================
function post(string $endpoint, array $extra = []): ?array {
    global $BASE, $init, $UA;

    $body = array_merge(['initData' => $init], $extra);

    $ch = curl_init("{$BASE}{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "User-Agent: {$UA}",
            'Origin: https://btc.tonrevenue.space',
            'Referer: https://btc.tonrevenue.space/',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING       => 'gzip',
        CURLOPT_FRESH_CONNECT  => true,
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        L("  \033[31mNET:\033[0m {$err}");
        return null;
    }

    $d = json_decode($raw, true);
    if (!$d) {
        L("  \033[31mJSON:\033[0m HTTP {$code} | " . substr($raw, 0, 120));
        return null;
    }

    // API error: detail field
    if (isset($d['detail'])) {
        return ['_error' => $d['detail'], '_code' => $code, '_raw' => $d];
    }

    return $d;
}

// ============================================================
// INTERRUPTIBLE SLEEP
// ============================================================
function zzz(int $seconds): void {
    global $STOP;
    $done = 0;
    while ($done < $seconds && !$STOP) {
        sleep(min($TICK, $seconds - $done));
        $done += $TICK;
    }
}

// ============================================================
// CAPTCHA SOLVER — "Tap the {X}" → match option label
// ============================================================
function solve_captcha(array $challenge): ?string {
    $prompt  = $challenge['prompt'] ?? '';
    $options = $challenge['options'] ?? [];

    if (empty($prompt) || empty($options)) return null;

    // Extract keyword: "Tap the Pear" → "pear"
    // Also handle: "Tap the Orange" → "orange"
    $clean = preg_replace('/[^a-zA-Z\s]/', '', strtolower($prompt));
    $words = preg_split('/\s+/', $clean);

    // Remove stop words
    $stops = ['tap','click','the','a','an','select','choose','find','pick','button','icon','image','picture','that','which','is','in','on','of','to','with','this','shown','below','above','and','or'];
    $kws = array_filter($words, fn($w) => strlen($w) > 1 && !in_array($w, $stops));

    foreach ($options as $opt) {
        $label = strtolower($opt['label'] ?? $opt['id'] ?? '');
        foreach ($kws as $kw) {
            // Exact match atau substring match
            if ($label === $kw || strpos($label, $kw) !== false) {
                return $opt['id'] ?? null;
            }
        }
    }

    // Fallback: exact word match di label
    foreach ($options as $opt) {
        $label = strtolower($opt['label'] ?? '');
        foreach ($kws as $kw) {
            if (levenshtein($label, $kw) <= 1) return $opt['id'] ?? null;
        }
    }

    return $options[0]['id'] ?? null;
}

// ============================================================
// MAIN BOT
// ============================================================
$retries     = 0;
$claims      = 0;
$failed      = 0;
$captcha_ok_until = 0;  // timestamp, 0 = perlu solve
$t0          = time();

L("=== Bot started ===");

while (!$STOP) {
    // Lifetime check
    if ($lifetime > 0 && (time() - $t0) >= $lifetime) {
        L("Lifetime reached ({$lifetime}s)");
        break;
    }

    // ---- STEP 1: INIT SESSION ----
    L("[1/3] Init...");
    $fp_json = json_encode([
        'version' => '12.5.1', 'platform' => 'android', 'tg_platform' => 'android',
        'language' => 'en', 'theme' => 'dark', 'screen_width' => 1080,
        'screen_height' => 2400, 'online' => true, 'dpr' => 3,
    ]);

    $resp = post('/api/init', ['fingerprint' => $fp_json]);

    if ($resp === null) {
        $retries++;
        if ($retries >= $MAX_RETR) { L("Max retry, wait 60s..."); $retries = 0; zzz(60); }
        else { $d = 5 * pow(2, min($retries, 5)); L("Retry {$retries}/{$MAX_RETR} in {$d}s"); zzz((int)$d); }
        $failed++;
        continue;
    }

    if (isset($resp['_error'])) {
        $msg = $resp['_error'];
        L("  \033[31mERR:\033[0m {$msg}");

        if (stripos($msg, 'cooldown') !== false) {
            // Parse "Try again in Xs"
            if (preg_match('/(\d+)\s*s/', $msg, $m)) {
                $wait = (int)$m[1] + 2;
                L("  Cooldown: {$wait}s");
                zzz($wait);
                $retries = 0;
                continue;
            }
            zzz($CD_SEC);
            $retries = 0;
            continue;
        }

        // Auth error — initData mungkin expired
        if (stripos($msg, 'auth') !== false || stripos($msg, 'invalid') !== false || stripos($msg, 'expired') !== false) {
            L("  \033[31mInitData mungkin expired! Bot berhenti.\033[0m");
            break;
        }

        $retries++;
        if ($retries >= $MAX_RETR) { $retries = 0; zzz(60); }
        else { zzz(10); }
        $failed++;
        continue;
    }

    // Parse init response
    $user     = $resp['user'] ?? [];
    $balance  = (float)($user['balance'] ?? 0);
    $cooldown = (int)($user['cooldown'] ?? 0);
    $risk     = (float)($user['risk_score'] ?? 0);
    $blocked  = !empty($user['is_blocked']);

    if ($blocked) {
        L("  \033[31mACCOUNT BLOCKED!\033[0m " . ($user['ban_reason'] ?? ''));
        break;
    }

    L("  OK | Bal: \033[33m{$balance}\033[0m sats | CD: {$cooldown}s | Risk: {$risk}");

    // ---- STEP 2: CAPTCHA (kalau perlu) ----
    // Captcha valid 6 jam, cek apakah masih valid
    $need_captcha = !empty($user['captcha_required']) && time() >= $captcha_ok_until;

    if ($need_captcha) {
        L("[2/3] Solve captcha...");

        // Challenge dari init response
        $challenge = $user['captcha_challenge'] ?? null;

        if (!$challenge) {
            // Coba minta challenge baru
            $ch_resp = post('/api/captcha/challenge');
            if ($ch_resp && !isset($ch_resp['_error'])) {
                $challenge = $ch_resp;
            }
        }

        if ($challenge) {
            $prompt = $challenge['prompt'] ?? '?';
            $answer = solve_captcha($challenge);
            $cid    = $challenge['challenge_id'] ?? '';

            L("  Q: \"{$prompt}\"");

            if ($answer) {
                L("  A: \"{$answer}\"");
                $v = post('/api/captcha/verify', [
                    'challenge_id' => $cid,
                    'answer'       => $answer,
                ]);

                if ($v && ($v['status'] ?? '') === 'success') {
                    $captcha_ok_until = strtotime($v['captcha_valid_until'] ?? '+6 hours');
                    $valid_h = round(($captcha_ok_until - time()) / 3600, 1);
                    L("  \033[32mCaptcha OK!\033[0m Valid {$valid_h}h");
                } else {
                    $emsg = $v['_error']['detail'] ?? ($v['detail'] ?? 'failed');
                    L("  \033[31mCaptcha fail: {$emsg}\033[0m");
                    zzz(30);
                    $failed++;
                    continue;
                }
            } else {
                L("  \033[31mCannot solve captcha\033[0m");
                zzz(30);
                $failed++;
                continue;
            }
        } else {
            L("  \033[31mNo challenge available\033[0m");
            zzz(30);
            $failed++;
            continue;
        }
    } else {
        $remain_h = round(($captcha_ok_until - time()) / 3600, 1);
        L("[2/3] Captcha still valid ({$remain_h}h), skip");
    }

    // ---- STEP 3: CLAIM ----
    // Cek cooldown dari init
    if ($cooldown > 0) {
        L("[3/3] Cooldown: {$cooldown}s, skip claim");
        zzz($cooldown + 2);
        $retries = 0;
        continue;
    }

    L("[3/3] Claim...");

    $claim = post('/api/claim');

    if ($claim === null) {
        L("  \033[31mClaim network error\033[0m");
        $retries++;
        zzz(15);
        $failed++;
        continue;
    }

    if (isset($claim['_error'])) {
        $msg = $claim['_error'];
        L("  \033[31mClaim: {$msg}\033[0m");

        if (stripos($msg, 'captcha') !== false) {
            // Captcha expired, force re-solve
            $captcha_ok_until = 0;
            zzz(5);
            continue;
        }

        if (preg_match('/(\d+)\s*s/', $msg, $m)) {
            $w = (int)$m[1] + 2;
            L("  Wait {$w}s");
            zzz($w);
            $retries = 0;
            continue;
        }

        $retries++;
        zzz(10);
        $failed++;
        continue;
    }

    // Claim success → dapat session_uid
    $session_uid = $claim['session_uid'] ?? '';
    $reward_hint = (float)($claim['reward_sats'] ?? 0.5);

    if (empty($session_uid)) {
        L("  \033[31mNo session_uid in response\033[0m");
        $retries++;
        zzz(10);
        $failed++;
        continue;
    }

    L("  session_uid: " . substr($session_uid, 0, 16) . "...");

    // ---- STEP 4: CONFIRM (bypass ad) ----
    L("[4/4] Confirm...");

    $confirm = post('/api/claim/confirm', [
        'session_uid' => $session_uid,
        'token'       => '',
    ]);

    if ($confirm === null) {
        L("  \033[31mConfirm network error\033[0m");
        $failed++;
        zzz(10);
        continue;
    }

    if (isset($confirm['_error'])) {
        $msg = $confirm['_error'];
        L("  \033[31mConfirm: {$msg}\033[0m");
        $failed++;
        zzz(10);
        continue;
    }

    // SUCCESS!
    $new_bal = (float)($confirm['new_balance'] ?? $balance);
    $reward  = (float)($confirm['reward'] ?? $reward_hint);
    $cd      = (int)($confirm['cooldown'] ?? $CD_SEC);
    $claims++;
    $retries = 0;
    $failed  = 0;

    $elapsed = time() - $t0;
    $mem     = round(memory_get_usage(true) / 1048576, 1);
    $rate    = $elapsed > 0 ? round(($claims / $elapsed) * 3600, 1) : 0;

    L("  \033[32m+{$reward} sats\033[0m | Bal: \033[33m{$new_bal}\033[0m sats | CD: {$cd}s");
    L("  Stats: {$claims} claims, {$failed} fails, {$elapsed}s uptime, {$rate}/hr, {$mem}MB");

    // Cooldown
    L("  --- cooldown {$cd}s ---");
    zzz($cd + 2);

    // GC tiap 10 claims
    if ($claims % 10 === 0) {
        gc_collect_cycles();
    }
}

$elapsed = time() - $t0;
L("=== Bot stopped. {$claims} claims, {$failed} fails, {$elapsed}s ===\n");
