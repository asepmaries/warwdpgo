<?php
// ======================================================================
// GOPAY MLBB WDP - WAR EDITION (Dynamic Lead + Adaptive Retry)
//
// Strategi:
//  - Lead time otomatis: target_srv - (RTT efektif / 2).
//    lead.txt hanya fallback kalau RTT warm-up tidak tersedia.
//    Contoh: lead.txt isi -25 → fire 25ms sebelum 17:00:00 (T-25ms).
//  - Warm-up tunggal T-1.5s (4 paralel) untuk warm TLS pool sebelum burst.
//  - Salvo pertama biasanya "voucher not available" -> retry SECEPATNYA.
//  - Adaptive per-handle: handle yang selesai langsung di-retry sendiri,
//    tidak menunggu handle lain (pakai curl_multi_info_read).
//  - User yang sudah SUCCESS tidak ikut retry.
//  - User di luar region promo tidak ikut retry.
//  - Stop GLOBAL kalau ada response "out of stock" (voucher habis).
//  - Captcha dipakai sekali untuk semua user, di-cache 23 jam.
//  - Hard cap MAX_TOTAL_INQUIRIES untuk hindari transaction suspecious.
// ======================================================================
if (php_sapi_name() !== 'cli') {
    die("Script ini hanya boleh dijalankan via CLI\n");
}
date_default_timezone_set('Asia/Jakarta');
set_time_limit(0);
ignore_user_abort(true);
// PHP 8.5+ deprecates curl_close() (no-op since 8.0). Sembunyikan supaya log bersih.
error_reporting(E_ALL & ~E_DEPRECATED);

// ----------------------------------------------------------------------
// AUTO-LOG: tulis semua output ke STDOUT dan file loghasil.txt secara simultan.
// File loghasil.txt akan di-truncate setiap script start (fresh log per run).
// ----------------------------------------------------------------------
$LOG_FILE = __DIR__ . '/loghasil.txt';
$LOG_FH   = fopen($LOG_FILE, 'w');
if ($LOG_FH === false) {
    fwrite(STDERR, "[WARN] Tidak bisa buka loghasil.txt untuk tulis. Lanjut tanpa logging file.\n");
    $LOG_FH = null;
}
if ($LOG_FH !== null) {
    // Tulis header marker
    fwrite($LOG_FH, "=== WAR LOG START @ " . date('Y-m-d H:i:s') . " === host=" . php_uname('n') . "\n\n");
    fflush($LOG_FH);
}

// Output buffering: tee ke STDOUT + file
ob_start(function ($buffer) use (&$LOG_FH) {
    if ($LOG_FH !== null && $buffer !== '') {
        fwrite($LOG_FH, $buffer);
        fflush($LOG_FH);
    }
    return $buffer; // tetap kirim ke stdout
}, 1); // chunk size 1 byte → flush setiap echo langsung tampil
ob_implicit_flush(true);

// Pastikan buffer di-flush dan file ditutup saat script selesai (normal/error/fatal)
register_shutdown_function(function () use (&$LOG_FH) {
    @ob_end_flush();
    if ($LOG_FH !== null) {
        fwrite($LOG_FH, "\n=== WAR LOG END @ " . date('Y-m-d H:i:s') . " ===\n");
        fclose($LOG_FH);
    }
});

// ----------------------------------------------------------------------
// KONFIGURASI WAR
// ----------------------------------------------------------------------
// Lead fallback dibaca dari lead.txt (per-VPS). Format: 1 angka dalam ms.
// Konvensi: NEGATIF = fire SEBELUM war start (duluan).
//           POSITIF = fire SETELAH war start (telat).
// Contoh isi lead.txt: -25 → fire T-25ms | 25 → fire T+25ms | 0 → tepat war.
const BURST_LEAD_MS_DEFAULT  = 0;            // Fallback kalau lead.txt tidak ada.
const MINI_PROBE2_LEAD_MS    = 1500;         // Warm-up T-1.5s sebelum burst (warm TLS pool).
const MINI_PROBE2_PARALLEL   = 5;            // Samakan dengan MAX_USERS supaya semua koneksi warm.
const MAX_RETRY_PER_USER     = 9;
const MAX_TOTAL_INQUIRIES    = 10;            // Hard cap: 11 inquiry call dalam 1 menit → HTTP 429.
const MAX_USERS              = 5;             // Max user per VPS, sama seperti war.go.
const TREAT_STOP_AS_RETRY    = false;         // PROD: out of stock → STOP global (hemat sisa kuota).
const INQUIRY_CONNECT_TO_MS  = 2200;
const INQUIRY_TIMEOUT_MS    = 5200;
const PAYMENT_CONNECT_TO_MS = 2200;
const PAYMENT_TIMEOUT_MS    = 5200;
const TARGET_SRV_MS_DEFAULT = 5.0;            // Target arrival server, ms setelah T=0.
const FINE_TUNE_START_BEFORE_MS = 12000;      // Mulai probe RTT sebelum warm-up T-1.5s.
const FINE_TUNE_PROBE_INTERVAL_MS = 1000;
const FINE_TUNE_PROBE_COUNT = 8;

$WARMUP_MEDIAN_RTT_MS = null;             // Diisi dari miniProbe2ReWarm; dipakai untuk estimasi one-way.
$WARMUP_MIN_RTT_MS    = null;             // RTT minimum warm-up; dipakai untuk dynamic lead.
$FINE_TUNE_RTTS_MS    = [];               // RTT probe H-12..H-5; fallback kalau warm-up spike.

// Pola pesan error dari endpoint inquiry
const STOP_PATTERNS       = ['out of stock', 'sold out', 'kuota habis', 'voucher habis', 'stok habis', 'sudah habis'];
const SKIP_USER_PATTERNS  = ['reached the redeem limit', 'already redeemed', 'sudah pernah', 'role_null', 'role null', 'invalid user', 'user not found', 'user_not_found', 'act_subscrip_no_config', 'subscrip_no_config'];
const REGION_BLOCK_PATTERNS = ['regional restrictions', 'region restriction', 'outside region', 'outside regional', 'di luar region', 'diluar region', 'luar region', 'luar zona promo', 'zona promo'];
const RETRY_PATTERNS      = ['not available', 'not yet', 'belum dimulai', 'belum tersedia', 'tidak tersedia', 'try again', 'temporarily', 'service unavailable'];

// ----------------------------------------------------------------------
// TIMING DARI waktu.txt
// ----------------------------------------------------------------------
function readTargetSrvMs(): float {
    foreach (['target_srv.txt', 'target_arr.txt'] as $name) {
        $path = __DIR__ . '/' . $name;
        if (!file_exists($path)) {
            continue;
        }
        $raw = trim((string) file_get_contents($path));
        if (is_numeric($raw)) {
            return (float) $raw;
        }
    }
    return TARGET_SRV_MS_DEFAULT;
}

function avgRtt(array $rtts): float {
    if (empty($rtts)) return 0.0;
    return array_sum($rtts) / count($rtts);
}

function effectiveLeadRTT(): array {
    global $WARMUP_MIN_RTT_MS, $WARMUP_MEDIAN_RTT_MS, $FINE_TUNE_RTTS_MS;

    if ($WARMUP_MIN_RTT_MS === null || $WARMUP_MIN_RTT_MS <= 0) {
        if ($WARMUP_MEDIAN_RTT_MS !== null && $WARMUP_MEDIAN_RTT_MS > 0) {
            return [(float) $WARMUP_MEDIAN_RTT_MS, false];
        }
        return [0.0, false];
    }

    $fineTuneAvg = avgRtt($FINE_TUNE_RTTS_MS);
    if ($fineTuneAvg > 0 && $WARMUP_MIN_RTT_MS > $fineTuneAvg) {
        return [$fineTuneAvg, true];
    }
    return [(float) $WARMUP_MIN_RTT_MS, false];
}

function resolveLeadMs(int $fallbackLeadMs, float $targetSrvMs): int {
    global $WARMUP_MIN_RTT_MS;

    [$leadRTT, $usedFineTuneAvg] = effectiveLeadRTT();
    $owEst = $leadRTT / 2.0;
    if ($owEst <= 0) {
        echo "[LEAD] Warm-up RTT tidak tersedia -> pakai lead.txt: {$fallbackLeadMs}ms\n";
        return $fallbackLeadMs;
    }

    $dynamic = (int) round($targetSrvMs - $owEst);
    if ($usedFineTuneAvg) {
        echo sprintf(
            "[LEAD] Dynamic: target_srv=%+.0fms | RTT_min=%.0fms > tune_avg=%.0fms -> pakai tune_avg | ow_est=%.0fms -> lead=%+dms\n",
            $targetSrvMs,
            (float) $WARMUP_MIN_RTT_MS,
            $leadRTT,
            $owEst,
            $dynamic
        );
    } else {
        echo sprintf(
            "[LEAD] Dynamic: target_srv=%+.0fms | RTT_min=%.0fms | ow_est=%.0fms -> lead=%+dms\n",
            $targetSrvMs,
            (float) $WARMUP_MIN_RTT_MS,
            $owEst,
            $dynamic
        );
    }
    echo "[LEAD] (lead.txt={$fallbackLeadMs}ms hanya dipakai sebagai fallback)\n";
    return $dynamic;
}

function addMilliseconds(DateTimeImmutable $dt, int $ms): DateTimeImmutable {
    if ($ms === 0) return $dt;
    $sign = $ms > 0 ? '+' : '-';
    return $dt->modify($sign . abs($ms) . ' milliseconds');
}

function dateTimeWallUs(DateTimeInterface $dt): int {
    return ((int) $dt->format('U')) * 1_000_000 + (int) $dt->format('u');
}

function waitUntilWallUs(int $targetWallUs): void {
    $remainingNs = max(0, (int) round(($targetWallUs - (microtime(true) * 1_000_000)) * 1000));
    $targetMono = hrtime(true) + $remainingNs;
    while (true) {
        $remaining = $targetMono - hrtime(true);
        if ($remaining <= 0) return;
        $remainingUs = intdiv($remaining, 1000);
        if ($remainingUs > 25000) usleep(12000);
        elseif ($remainingUs > 10000) usleep(4000);
        elseif ($remainingUs > 4000) usleep(1500);
        else continue;
    }
}

function waitForExactBurstTime(int $fallbackLeadMs, array $sampleOrder, string $captchaToken, ?callable $beforeBurst = null): bool {
    global $WAR_START_WALL_US, $FINE_TUNE_RTTS_MS;
    $file = 'waktu.txt';
    if (!file_exists($file)) die("❌ File 'waktu.txt' tidak ditemukan!\n");
    $content = trim(file_get_contents($file));
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $content, $m)) {
        die("❌ Format waktu.txt salah! Gunakan HH:MM atau HH:MM:SS\n");
    }
    $hour = (int)$m[1]; $minute = (int)$m[2]; $second = isset($m[3]) ? (int)$m[3] : 0;

    $now = new DateTimeImmutable('now');
    $target = $now->setTime($hour, $minute, $second, 0);
    if ($target < $now) $target = $target->modify('+1 day');

    $WAR_START_WALL_US = dateTimeWallUs($target);
    $targetSrvMs = readTargetSrvMs();
    $FINE_TUNE_RTTS_MS = [];

    echo "[TIME] Target burst (T=0): " . $target->format('H:i:s.v') . " WIB | target_srv: "
       . sprintf('%+.0fms', $targetSrvMs) . " | lead.txt fallback: {$fallbackLeadMs}ms\n";

    $warmupAt = addMilliseconds($target, -MINI_PROBE2_LEAD_MS);
    $warmupAtUs = dateTimeWallUs($warmupAt);
    $untilWarmupMs = ($warmupAtUs - (microtime(true) * 1_000_000)) / 1000;
    if ($untilWarmupMs > 15000) {
        usleep((int) (($untilWarmupMs - FINE_TUNE_START_BEFORE_MS) * 1000));
        echo "Masuk fase fine tuning (H-12s -> H-5s, " . FINE_TUNE_PROBE_COUNT . "x @ 1/detik)...\n";
        doFineTuneProbes($sampleOrder, $captchaToken);
    } elseif ($untilWarmupMs > 6000) {
        usleep((int) (($untilWarmupMs - 6000) * 1000));
        echo "Masuk fase fine tuning...\n";
    }

    waitUntilWallUs($warmupAtUs);

    if ($beforeBurst !== null) {
        $remainingToTargetMs = (dateTimeWallUs($target) - (microtime(true) * 1_000_000)) / 1000;
        $budgetMs = min(MINI_PROBE2_LEAD_MS - 200, (int) $remainingToTargetMs - 200);
        if ($budgetMs >= 150) {
            $beforeBurst($budgetMs);
        } else {
            echo "[WARM-UP] Skip - sisa waktu terlalu mepet, jaga burst tetap on-time\n";
        }
    }

    $leadMs = resolveLeadMs($fallbackLeadMs, $targetSrvMs);
    $execTarget = addMilliseconds($target, $leadMs);
    if (dateTimeWallUs($execTarget) < (int) round(microtime(true) * 1_000_000)) {
        echo "[LEAD] Dynamic lead {$leadMs}ms membuat exec di masa lalu -> fallback lead.txt {$fallbackLeadMs}ms\n";
        $leadMs = $fallbackLeadMs;
        $execTarget = addMilliseconds($target, $leadMs);
    }

    [$leadRTT] = effectiveLeadRTT();
    $owEst = $leadRTT / 2.0;
    if ($owEst > 0) {
        $estSrv = $leadMs + $owEst;
        echo sprintf(
            "[TIME] Lead: %+dms | Exec: %s WIB | estimasi srv: %+.0fms\n",
            $leadMs,
            $execTarget->format('H:i:s.v'),
            $estSrv
        );
    } else {
        echo sprintf("[TIME] Lead: %+dms | Exec: %s WIB\n", $leadMs, $execTarget->format('H:i:s.v'));
    }

    waitUntilWallUs(dateTimeWallUs($execTarget));
    echo "[BURST] START! [" . formatMicrotimeNow() . "] MULAI FULL FLOW!\n\n";
    return true;

}

// ----------------------------------------------------------------------
// FUNGSI PEMBANTU
// ----------------------------------------------------------------------
function getRandomUserAgent(): array {
    $androidVersions = ['11', '12', '13', '14'];
    $models = [
        'SM-A225F', 'SM-A135F', 'SM-A205F', 'SM-A326B', 'SM-A127F',
        'SM-A325F', 'SM-A528B', 'SM-A536B', 'SM-A546B', 'SM-A426B',
        'SM-M136B', 'SM-M326B', 'SM-A047F', 'SM-A057F', 'SM-A235F',
        'SM-A236B', 'SM-A256E', 'SM-A346B', 'SM-A356E', 'SM-A556E',
        'Redmi Note 12', 'Redmi Note 13', 'Redmi 13C', 'Poco X6',
        'RMX3395', 'RMX3780', 'RMX3686'
    ];
    $chromeVersions = ['135', '136', '137', '138', '139', '140'];
    $minorVersions = ['0', '1', '2', '3', '4', '5'];
    $androidVer = $androidVersions[array_rand($androidVersions)];
    $model = $models[array_rand($models)];
    $chromeVer = $chromeVersions[array_rand($chromeVersions)];
    $minor = $minorVersions[array_rand($minorVersions)];
    $builds = [
        'TP1A.220624.014', 'TP1A.221005.002', 'UP1A.231005.007',
        'UP1A.231105.003', 'AP1A.240305.019', 'BP1A.250205.002'
    ];
    $build = $builds[array_rand($builds)];
    $userAgent = "Mozilla/5.0 (Linux; Android {$androidVer}; {$model} Build/{$build}) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$chromeVer}.0.{$minor}.0 Mobile Safari/537.36";
    $secChUa = "\"Android WebView\";v=\"{$chromeVer}\", \"Chromium\";v=\"{$chromeVer}\", \"Not/A)Brand\";v=\"24\"";
    return [
        'user-agent' => $userAgent,
        'sec-ch-ua' => $secChUa
    ];
}

function generateSentryTrace(): array {
    $traceId = bin2hex(random_bytes(16));
    $parentId = bin2hex(random_bytes(8));
    return [
        'sentry-trace' => "$traceId-$parentId-1",
        'baggage' => "sentry-environment=production,sentry-release=vQMo5GDY05ylXAQzFup_V,sentry-public_key=3f2904ecef7bc7859d6299eaf817040c,sentry-trace_id=$traceId,sentry-sample_rate=1,sentry-sampled=true"
    ];
}

function formatMicrotimeNow(): string {
    $now = microtime(true);
    $sec = floor($now);
    $micros = (int)(($now - $sec) * 1_000_000);
    return date('H:i:s', (int)$sec) . '.' . str_pad((string)$micros, 6, '0', STR_PAD_LEFT);
}

function curlTimingMs($ch, array $info, string $timeTConst, string $secondsKey): ?float {
    if (defined($timeTConst)) {
        $value = curl_getinfo($ch, constant($timeTConst));
        if ($value !== false && is_numeric($value)) {
            return ((float) $value) / 1000;
        }
    }
    if (isset($info[$secondsKey]) && is_numeric($info[$secondsKey])) {
        return ((float) $info[$secondsKey]) * 1000;
    }
    return null;
}

function decodeResponseBody(string $resp): array {
    $decoded = json_decode($resp, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return $decoded;
    }
    return ['raw_response' => trim($resp)];
}

function extractApiErrorMessage($payload): string {
    if (!is_array($payload)) {
        return trim((string) $payload);
    }
    $message = $payload['errors'][0]['message']
        ?? $payload['errors'][0]['message_title']
        ?? $payload['data']['errors'][0]['message']
        ?? $payload['data']['errors'][0]['message_title']
        ?? $payload['message']
        ?? $payload['error']
        ?? $payload['data']['message']
        ?? $payload['data']['error']
        ?? $payload['raw_response']
        ?? $payload['data']['raw_response']
        ?? '';
    if ($message !== '') {
        return trim((string) $message);
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? '' : $json;
}

function buildHeaderLines(array $headers, bool $withCompression = true): array {
    $headerLines = [];
    foreach ($headers as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        if ($withCompression && strtolower($key) === 'accept-encoding') {
            continue;
        }
        $headerLines[] = "$key: $value";
    }
    if ($withCompression) {
        $headerLines[] = 'Accept-Encoding: gzip, deflate';
    }
    return $headerLines;
}

function createCurlSession() {
    $ch = curl_init();
    $baseOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => 'gzip,deflate',
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_FORBID_REUSE => false,
        CURLOPT_FRESH_CONNECT => false,
        CURLOPT_TCP_KEEPALIVE => 1,
        CURLOPT_DNS_CACHE_TIMEOUT => 300,
    ];
    if (defined('CURLOPT_TCP_FASTOPEN')) {
        $baseOptions[CURLOPT_TCP_FASTOPEN] = 1;
    }
    if (defined('CURLOPT_HTTP_VERSION') && defined('CURL_HTTP_VERSION_2TLS')) {
        $baseOptions[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
    }
    curl_setopt_array($ch, $baseOptions);
    attachShareToHandle($ch);
    return $ch;
}

function getSharedCurlHandle() {
    static $share = null;
    if ($share !== null) return $share;
    if (!function_exists('curl_share_init')) return null;
    $share = curl_share_init();
    if (defined('CURL_LOCK_DATA_DNS')) {
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
    }
    if (defined('CURL_LOCK_DATA_SSL_SESSION')) {
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
    }
    if (defined('CURL_LOCK_DATA_CONNECT')) {
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
    }
    return $share;
}

function attachShareToHandle($ch): void {
    $share = getSharedCurlHandle();
    if ($share !== null && defined('CURLOPT_SHARE')) {
        @curl_setopt($ch, CURLOPT_SHARE, $share);
    }
}

function configureCurlHandle($ch, string $url, string $method, array $headers, $body = null, array $options = []): void {
    $headerLines = buildHeaderLines($headers, true);
    if ($body !== null && !array_key_exists('content-type', array_change_key_case($headers, CASE_LOWER))) {
        $headerLines[] = 'Content-Type: application/json';
    }
    $connectTimeoutMs = (int)($options['connect_timeout_ms'] ?? 2500);
    $timeoutMs = (int)($options['timeout_ms'] ?? 7000);
    $curlOptions = [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
        CURLOPT_TIMEOUT_MS => $timeoutMs,
    ];
    if ($body !== null) {
        $curlOptions[CURLOPT_POSTFIELDS] = is_array($body) ? json_encode($body) : $body;
    } else {
        $curlOptions[CURLOPT_POSTFIELDS] = null;
    }
    curl_setopt_array($ch, $curlOptions);
}

function runMultiHandles($mh): void {
    do {
        do {
            $status = curl_multi_exec($mh, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($running > 0) {
            $selected = curl_multi_select($mh, 0.05);
            if ($selected === -1) {
                usleep(1000);
            }
        }
    } while ($running > 0 && $status === CURLM_OK);
}

function warmUpBurstSession(array $baseHeaders): void {
    // Deprecated stub: kept for backwards compat with any external caller.
}

/**
 * Warm-up T-MINI_PROBE2_LEAD_MS sebelum burst (default 1.5s): hantam endpoint
 * inquiry asli pakai MINI_PROBE2_PARALLEL koneksi paralel supaya TCP/TLS pool
 * benar-benar warm saat salvo war fire. Tidak konsumsi voucher (response =
 * "not available", war belum mulai). RTT yang dilaporkan hanya untuk informasi
 * di log, tidak dipakai untuk re-tune lead.
 *
 * $maxMs: budget timeout. Warm-up call di-cut kalau melebihi budget supaya
 *         TIDAK menunda burst (VPS koneksi cold/lambat tetap fire on-time).
 */
function miniProbe2ReWarm(array $sampleOrder, string $captchaToken, int $maxMs = 1200): array {
    $maxMs = max(150, $maxMs);
    $connectTo = min(INQUIRY_CONNECT_TO_MS, $maxMs);
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < MINI_PROBE2_PARALLEL; $i++) {
        $headers = buildInquiryHeaders($captchaToken);
        $body    = buildInquiryBody($sampleOrder);
        $ch = createCurlSession();
        configureCurlHandle(
            $ch,
            'https://gopay.co.id/games/v1/order/inquiry',
            'POST',
            $headers,
            $body,
            ['connect_timeout_ms' => $connectTo, 'timeout_ms' => $maxMs]
        );
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    // Hard deadline: jangan loop lebih lama dari budget (curl timeout sudah set,
    // tapi ini pengaman tambahan supaya loop multi tidak overshoot).
    $deadline = microtime(true) + ($maxMs / 1000);
    $running = null;
    do {
        do { $st = curl_multi_exec($mh, $running); } while ($st === CURLM_CALL_MULTI_PERFORM);
        if ($running > 0) {
            $sel = curl_multi_select($mh, 0.05);
            if ($sel === -1) usleep(1000);
        }
    } while ($running > 0 && $st === CURLM_OK && microtime(true) < $deadline);

    $rttMs = [];
    foreach ($handles as $ch) {
        $errno = curl_errno($ch);
        $info  = curl_getinfo($ch);
        $totalMs = curlTimingMs($ch, $info, 'CURLINFO_TOTAL_TIME_T', 'total_time');
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($errno) continue;
        if ($totalMs !== null && $totalMs > 0) $rttMs[] = $totalMs;
    }
    curl_multi_close($mh);
    return $rttMs;
}

function runInquiryProbe(array $sampleOrder, string $captchaToken, int $timeoutMs = INQUIRY_TIMEOUT_MS): array {
    $headers = buildInquiryHeaders($captchaToken);
    $body    = buildInquiryBody($sampleOrder);
    $ch = createCurlSession();
    $started = microtime(true);
    configureCurlHandle(
        $ch,
        'https://gopay.co.id/games/v1/order/inquiry',
        'POST',
        $headers,
        $body,
        ['connect_timeout_ms' => INQUIRY_CONNECT_TO_MS, 'timeout_ms' => $timeoutMs]
    );
    $resp = @curl_exec($ch);
    $info = curl_getinfo($ch);
    $code = (int) ($info['http_code'] ?? 0);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $totalMs = curlTimingMs($ch, $info, 'CURLINFO_TOTAL_TIME_T', 'total_time');
    $elapsedMs = (microtime(true) - $started) * 1000;
    curl_close($ch);

    $payload = is_string($resp) && $resp !== '' ? decodeResponseBody($resp) : null;
    $errText = $payload ? extractApiErrorMessage($payload) : ($errno ? "cURL[$errno] $err" : '');
    $verdict = classifyInquiryResponse($code, $errText, $payload);

    return [
        'rtt' => (float) ($totalMs ?? $elapsedMs),
        'http' => $code,
        'status' => $verdict['status'],
    ];
}

function doFineTuneProbes(array $sampleOrder, string $captchaToken): void {
    global $FINE_TUNE_RTTS_MS;

    echo "[FINE-TUNE] Probe RTT " . FINE_TUNE_PROBE_COUNT . "x @ 1 req/detik...\n";
    $FINE_TUNE_RTTS_MS = [];
    for ($i = 1; $i <= FINE_TUNE_PROBE_COUNT; $i++) {
        if ($i > 1) {
            usleep(FINE_TUNE_PROBE_INTERVAL_MS * 1000);
        }
        $probe = runInquiryProbe($sampleOrder, $captchaToken);
        if ($probe['rtt'] > 0) {
            $FINE_TUNE_RTTS_MS[] = $probe['rtt'];
        }
        echo sprintf(
            "  fine-tune #%d | rtt %.0fms | HTTP %d | %s\n",
            $i,
            $probe['rtt'],
            $probe['http'],
            $probe['status']
        );
    }
}

// ----------------------------------------------------------------------
// UTILITAS: percentile (dipakai di runAdaptiveInquiry summary)
// ----------------------------------------------------------------------
function percentile(array $values, float $p): float {
    if (empty($values)) return 0.0;
    sort($values);
    $idx = (int) ceil($p * count($values)) - 1;
    $idx = max(0, min(count($values) - 1, $idx));
    return $values[$idx];
}

// ----------------------------------------------------------------------
// CAPTCHA: ambil dari Google
// ----------------------------------------------------------------------
function getFreshCaptchaToken(bool $quiet = false): string {
    if (!$quiet) echo "[CAPTCHA] Mengambil token captcha baru dari Google...\n";
    $url = "https://www.google.com/recaptcha/api2/reload?k=6Le4GDcqAAAAAFTD31YUpEd1qMPgntTn1xFH7n_o";
    $headers = [
        'sec-ch-ua-platform' => '"Android"',
        'sec-ch-ua' => '"Android WebView";v="137", "Chromium";v="137", "Not/A)Brand";v="24"',
        'content-type' => 'application/x-protobuffer',
        'sec-ch-ua-mobile' => '?1',
        'origin' => 'https://www.google.com',
        'x-requested-with' => 'mark.via.gp',
        'sec-fetch-site' => 'same-origin',
        'sec-fetch-mode' => 'cors',
        'sec-fetch-dest' => 'empty',
        'referer' => 'https://www.google.com/recaptcha/api2/anchor?ar=1&k=6Le4GDcqAAAAAFTD31YUpEd1qMPgntTn1xFH7n_o&co=aHR0cHM6Ly9nb3BheS5jby5pZDo0NDM.&hl=en&v=79clEdOi5xQbrrpL2L8kGmK3&size=invisible&anchor-ms=20000&execute-ms=30000&cb=34spuflel6ax',
        'accept-language' => 'en-US,en;q=0.9',
        'cookie' => '_GRECAPTCHA=09AKhCRwjgcOklpqEngV5VzHCVLFDBttzjYVsQF9rHqCiF81J4gUV-koT2yYoYYMWQ65cGpZGNeDlgcD6AuDUHaXE; NID=530=KWlL-7aGLYQ7iV22k_iTZNjtlWxq7MMTpQq0u8sZfG2g5pM0duotIFiU3TGhRRcOdHcP6LZ4bYME6IegrhsnD0G9nKHB9cRSCGIRBj5W2Wyq8mVkj45oS7mt74yREaGoZGi_-AbUXLh2FE7NPNDvqLHmWFvEWrW_ZlapE-IZB7z36y_F6DCS_WYW5CRp6I_clI3zXw3f_XJAIVGOZJnq_UP7pDDvpsghYNmZCcgp96SxIonQxjlRKmrqaYFQ4FIwfCOHm36EKbA',
    ];
    $reloadBody = file_get_contents('reload.txt');
    if ($reloadBody === false) {
        throw new RuntimeException("Gagal membaca reload.txt");
    }
    $ch = createCurlSession();
    configureCurlHandle(
        $ch,
        $url,
        'POST',
        $headers,
        $reloadBody,
        ['connect_timeout_ms' => 4000, 'timeout_ms' => 10000]
    );
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $errorMsg = curl_error($ch);
    curl_close($ch);
    if ($errno) {
        throw new RuntimeException("Gagal mengambil captcha token: cURL [$errno] $errorMsg", $errno);
    }
    if ($httpCode !== 200 || empty($response)) {
        throw new RuntimeException("Gagal mengambil captcha token. HTTP Code: $httpCode");
    }
    if (preg_match('/"rresp","([^"]+)"/', $response, $matches)) {
        $token = $matches[1];
        saveCaptchaToken($token, $quiet);
        if (!$quiet) echo "[CAPTCHA] Token berhasil diambil (panjang: " . strlen($token) . " karakter)\n\n";
        return $token;
    }
    throw new RuntimeException("Gagal parse captcha token dari response Google");
}

function saveCaptchaToken(string $token, bool $quiet = false): void {
    file_put_contents('captcha_token.txt', $token);
    if (!$quiet) echo "[CAPTCHA] Token baru disimpan ke captcha_token.txt\n";
}

// ----------------------------------------------------------------------
// CLASSIFY RESPONSE INQUIRY
// Return: ['status' => 'success'|'retry'|'stop'|'skip_user'|'region_block'|'unknown', 'orderId' => ?string]
// ----------------------------------------------------------------------
function classifyInquiryResponse(int $code, ?string $errorText, ?array $payload): array {
    if (($code === 200 || $code === 201) && is_array($payload)) {
        $orderId = $payload['data']['orderId'] ?? $payload['orderId'] ?? null;
        if ($orderId) {
            return ['status' => 'success', 'orderId' => (string) $orderId];
        }
    }

    $msg = strtolower((string) $errorText);

    foreach (STOP_PATTERNS as $p) {
        if ($msg !== '' && strpos($msg, $p) !== false) {
            return ['status' => 'stop', 'orderId' => null];
        }
    }
    foreach (SKIP_USER_PATTERNS as $p) {
        if ($msg !== '' && strpos($msg, $p) !== false) {
            return ['status' => 'skip_user', 'orderId' => null];
        }
    }
    foreach (REGION_BLOCK_PATTERNS as $p) {
        if ($msg !== '' && strpos($msg, $p) !== false) {
            return ['status' => 'region_block', 'orderId' => null];
        }
    }
    foreach (RETRY_PATTERNS as $p) {
        if ($msg !== '' && strpos($msg, $p) !== false) {
            return ['status' => 'retry', 'orderId' => null];
        }
    }

    // curl error atau HTTP 4xx/5xx tanpa pola dikenal -> retry konservatif
    if ($code === 0 || ($code >= 400 && $code < 600)) {
        return ['status' => 'retry', 'orderId' => null];
    }

    return ['status' => 'unknown', 'orderId' => null];
}

// ----------------------------------------------------------------------
// REQUEST WRAPPER & POLLING
// ----------------------------------------------------------------------
function request(string $url, string $method = 'POST', array $headers = [], $body = null, $ch = null, array $options = []) {
    $ownHandle = false;
    if ($ch === null) {
        $ch = createCurlSession();
        $ownHandle = true;
    }
    configureCurlHandle($ch, $url, $method, $headers, $body, $options);
    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $errorMsg = curl_error($ch);
    if ($ownHandle) {
        curl_close($ch);
    }
    if ($errno) throw new RuntimeException("cURL error [$errno]: $errorMsg");
    if ($httpCode >= 400) {
        throw new RuntimeException("HTTP $httpCode - $responseBody");
    }
    $decoded = json_decode($responseBody, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : ['raw_response' => $responseBody];
}

function getTransactionUntilReady(string $txnId, array $headers, $ch = null, array $delaysMs = [90, 120, 160, 220, 300, 420, 560, 750, 950]): ?array {
    $url = "https://gopay.co.id/games/v1/transaction/$txnId";
    foreach ($delaysMs as $index => $delayMs) {
        try {
            $data = request($url, 'GET', $headers, null, $ch, [
                'connect_timeout_ms' => 1500,
                'timeout_ms' => 3500,
            ]);
            if (!empty($data['actionPayment']['paymentDirect']) || !empty($data['actionPayment']['deeplinkRedirect'])) {
                return $data;
            }
        } catch (Exception $e) {}
        if ($index !== array_key_last($delaysMs)) {
            usleep($delayMs * 1000);
        }
    }
    return null;
}

// ----------------------------------------------------------------------
// BUILD INQUIRY HEADERS (per-attempt: refresh sentry + x-timestamp)
// ----------------------------------------------------------------------
function buildInquiryHeaders(string $captchaToken): array {
    $ua = getRandomUserAgent();
    $sentry = generateSentryTrace();
    return [
        'sec-ch-ua-platform' => '"Android"',
        'authorization' => 'Bearer undefined',
        'sec-ch-ua' => $ua['sec-ch-ua'],
        'sec-ch-ua-mobile' => '?1',
        'baggage' => $sentry['baggage'],
        'sentry-trace' => $sentry['sentry-trace'],
        'user-agent' => $ua['user-agent'],
        'x-captcha-token' => $captchaToken,
        'content-type' => 'application/json',
        'x-client' => 'mobile',
        'accept' => '*/*',
        'origin' => 'https://gopay.co.id',
        'x-requested-with' => 'mark.via.gp',
        'sec-fetch-site' => 'same-origin',
        'sec-fetch-mode' => 'cors',
        'sec-fetch-dest' => 'empty',
        'referer' => 'https://gopay.co.id/games/mobile-legends-bang-bang',
        'accept-language' => 'en-US,en;q=0.9',
        'x-timestamp' => (string) round(microtime(true) * 1000),
        'cookie' => 'acw_tc=9581d31c17748587792257129e0deb0a34ec18f05b8a68459d00a474893677; slug=mobile-legends-bang-bang',
    ];
}

function buildInquiryBody(array $order): array {
    return [
        'productId' => 19,
        'productItemId' => 366,
        'data' => ['userId' => $order['userId'], 'zoneId' => $order['serverId']],
        'paymentChannelId' => 73,
        'phoneNumber' => '628783219212',
        'voucher' => 'WARWDPGG',
        'quantity' => 1,
    ];
}

// ----------------------------------------------------------------------
// FIRE INQUIRY (attach satu handle ke multi)
// ----------------------------------------------------------------------
function fireInquiry($mh, array $order, string $captchaToken, int $attempt): array {
    $headers = buildInquiryHeaders($captchaToken);
    $body    = buildInquiryBody($order);
    $ch = createCurlSession();
    configureCurlHandle(
        $ch,
        'https://gopay.co.id/games/v1/order/inquiry',
        'POST',
        $headers,
        $body,
        ['connect_timeout_ms' => INQUIRY_CONNECT_TO_MS, 'timeout_ms' => INQUIRY_TIMEOUT_MS]
    );
    curl_multi_add_handle($mh, $ch);
    return [
        'ch'       => $ch,
        'order'    => $order,
        'headers'  => $headers,
        'attempt'  => $attempt,
        'started'  => microtime(true),
    ];
}

// ----------------------------------------------------------------------
// ADAPTIVE INQUIRY LOOP
// ----------------------------------------------------------------------
function runAdaptiveInquiry(array $orders, string $captchaToken): array {
    $mh = curl_multi_init();
    $active     = [];      // map: (int)$ch -> meta
    $attempts   = [];      // userId|serverId -> attempt count
    $successMap = [];      // userId|serverId -> ['order','orderId','headers']
    $skipUsers  = [];      // userId|serverId -> true (user sudah pernah claim, percuma retry)
    $regionBlockedUsers = []; // userId|serverId -> true (di luar region promo, percuma retry)
    $stopGlobal = false;
    $totalInquiries = 0;

    $phaseStart = microtime(true);
    $eventLog   = []; // [tRelMs, label]
    $attemptStats = []; // [{user, try, rtt, srvArrival, http, verdict, status_eff}]

    // Salvo pertama: tembak semua user paralel
    foreach ($orders as $ord) {
        $key = $ord['userId'] . '|' . $ord['serverId'];
        $attempts[$key] = 1;
        $totalInquiries++;
        $meta = fireInquiry($mh, $ord, $captchaToken, 1);
        $active[(int) $meta['ch']] = $meta;
    }
    $eventLog[] = [0.0, "SALVO #1 fired (3 paralel)"];
    echo "🔥 SALVO #1: tembak " . count($orders) . " user paralel @ [" . formatMicrotimeNow() . "]\n";

    // Pump multi handle, panen response begitu siap, langsung fire retry
    $running = null;
    do {
        do {
            $status = curl_multi_exec($mh, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        // Panen handle yang selesai
        while ($info = curl_multi_info_read($mh)) {
            if ($info['msg'] !== CURLMSG_DONE) continue;

            $ch  = $info['handle'];
            $key = (int) $ch;
            if (!isset($active[$key])) {
                curl_multi_remove_handle($mh, $ch);
                @curl_close($ch);
                continue;
            }
            $meta = $active[$key];
            unset($active[$key]);

            $resp         = curl_multi_getcontent($ch);
            $curlInfo     = curl_getinfo($ch);
            $code         = (int) ($curlInfo['http_code'] ?? 0);
            $preTransferMs= curlTimingMs($ch, $curlInfo, 'CURLINFO_PRETRANSFER_TIME_T', 'pretransfer_time');
            $totalTimeMs  = curlTimingMs($ch, $curlInfo, 'CURLINFO_TOTAL_TIME_T', 'total_time');
            $curlErr      = curl_error($ch);
            $curlErrno    = curl_errno($ch);
            curl_multi_remove_handle($mh, $ch);

            $userKey  = $meta['order']['userId'] . '|' . $meta['order']['serverId'];
            $elapsedPhpMs = (microtime(true) - $meta['started']) * 1000;
            $elapsed  = (int) round($totalTimeMs ?? $elapsedPhpMs);
            $tRel     = (microtime(true) - $phaseStart) * 1000;

            // Estimasi waktu request sampai di server (relatif ke WAR_START / T=0).
            // Hybrid bounded: one-way warm-up dipakai, tapi di-cap oleh rtt request ini / 2.
            // Formula lama tetap dicatat sebagai pembanding: fire_wall + rtt PHP/2.
            global $WAR_START_WALL_US, $WARMUP_MEDIAN_RTT_MS;
            $serverArrivalMs = null;
            $oldServerArrivalMs = null;
            $warmServerArrivalMs = null;
            $fireOffsetMs = null;
            $oneWayMs = null;
            if (!empty($WAR_START_WALL_US)) {
                $fireWallUs = $meta['started'] * 1_000_000;
                $fireOffsetMs = ($fireWallUs - $WAR_START_WALL_US) / 1000;
                $oldServerArrivalUs = $fireWallUs + ($elapsedPhpMs * 1000 / 2);
                $oldServerArrivalMs = ($oldServerArrivalUs - $WAR_START_WALL_US) / 1000;
                $currentOneWayMs = ($totalTimeMs ?? $elapsedPhpMs) / 2;
                $preMs = $preTransferMs ?? 0.0;

                if ($WARMUP_MEDIAN_RTT_MS !== null) {
                    $warmOneWayMs = ((float) $WARMUP_MEDIAN_RTT_MS) / 2;
                    $warmServerArrivalMs = $fireOffsetMs + $preMs + $warmOneWayMs;
                    $oneWayMs = min($warmOneWayMs, $currentOneWayMs);
                } else {
                    $oneWayMs = $currentOneWayMs;
                }
                $serverArrivalMs = $fireOffsetMs + $preMs + $oneWayMs;
            }
            $sArr = $serverArrivalMs !== null
                ? sprintf('srv%+.0fms', $serverArrivalMs)
                : 'srv?';
            $fireTag = $fireOffsetMs !== null ? sprintf('[fire%+.0fms]', $fireOffsetMs) : '[fire?]';
            $preTag = $preTransferMs !== null ? sprintf('[pre %.0fms]', $preTransferMs) : '[pre ?]';
            $owTag  = $oneWayMs !== null ? sprintf('[ow %.0fms]', $oneWayMs) : '[ow ?]';
            $warmTag = $warmServerArrivalMs !== null ? sprintf('[warm%+.0fms]', $warmServerArrivalMs) : '[warm?]';
            $oldTag = $oldServerArrivalMs !== null ? sprintf('[old%+.0fms]', $oldServerArrivalMs) : '[old?]';

            $payload  = $resp !== false && $resp !== null ? decodeResponseBody((string) $resp) : null;
            $errText  = $payload ? extractApiErrorMessage($payload) : ($curlErrno ? "cURL[$curlErrno] $curlErr" : '');
            $verdict  = classifyInquiryResponse((int) $code, $errText, $payload);

            // TES MODE: paksa STOP -> RETRY supaya bisa ukur durasi penuh
            $effectiveStatus = $verdict['status'];
            if ($effectiveStatus === 'stop' && TREAT_STOP_AS_RETRY) {
                $effectiveStatus = 'retry';
            }

            // Capture attempt stat untuk auto-summary
            $attemptStats[] = [
                'user'        => $userKey,
                'try'         => $meta['attempt'],
                'rtt'         => $elapsed,
                'srv_arrival' => $serverArrivalMs,
                'http'        => (int) $code,
                'verdict'     => $verdict['status'],
                'effective'   => $effectiveStatus,
            ];

            $tag = "[+" . sprintf('%6.1f', $tRel) . "ms][$userKey][try {$meta['attempt']}/" . (MAX_RETRY_PER_USER + 1) . "][rtt {$elapsed}ms][$sArr][HTTP $code]{$fireTag}{$preTag}{$owTag}{$warmTag}{$oldTag}";

            if ($effectiveStatus === 'success') {
                echo "$tag ✅ OrderID: {$verdict['orderId']}\n";
                $eventLog[] = [$tRel, "[$userKey] response try {$meta['attempt']} → SUCCESS"];
                $successMap[$userKey] = [
                    'order'   => $meta['order'],
                    'orderId' => $verdict['orderId'],
                    'headers' => $meta['headers'],
                ];
                @curl_close($ch);
                continue;
            }

            @curl_close($ch);
            $shortErr = $errText !== '' ? substr($errText, 0, 80) : '(no message)';
            $verdictTag = $verdict['status'] === 'stop' && TREAT_STOP_AS_RETRY ? 'stop→retry(test)' : $verdict['status'];
            echo "$tag ⚠️  $verdictTag: $shortErr\n";
            $eventLog[] = [$tRel, "[$userKey] response try {$meta['attempt']} → $verdictTag"];

            if ($effectiveStatus === 'stop') {
                echo "🛑 STOP GLOBAL: voucher habis terdeteksi. Batalkan semua retry.\n";
                $stopGlobal = true;
                continue;
            }

            if ($effectiveStatus === 'skip_user') {
                echo "[$userKey] ⏭️  SKIP USER: sudah pernah claim, tidak retry.\n";
                $skipUsers[$userKey] = true;
                continue;
            }

            if ($effectiveStatus === 'region_block') {
                echo "[$userKey] 🌐 USER ID DILUAR REGION: tidak retry.\n";
                $regionBlockedUsers[$userKey] = true;
                continue;
            }

            // status: retry / unknown -> coba lagi kalau masih ada budget
            if ($stopGlobal) continue;
            if (isset($successMap[$userKey])) continue;
            if (isset($skipUsers[$userKey])) continue;
            if (isset($regionBlockedUsers[$userKey])) continue;

            $nextAttempt = $attempts[$userKey] + 1;
            if ($nextAttempt > (MAX_RETRY_PER_USER + 1)) {
                echo "[$userKey] ⛔ max retry tercapai\n";
                continue;
            }
            if ($totalInquiries >= MAX_TOTAL_INQUIRIES) {
                echo "[$userKey] ⛔ MAX_TOTAL_INQUIRIES (" . MAX_TOTAL_INQUIRIES . ") tercapai, hindari suspecious\n";
                continue;
            }

            $attempts[$userKey] = $nextAttempt;
            $totalInquiries++;
            $newMeta = fireInquiry($mh, $meta['order'], $captchaToken, $nextAttempt);
            $active[(int) $newMeta['ch']] = $newMeta;
            $tFire = (microtime(true) - $phaseStart) * 1000;
            echo "[+" . sprintf('%6.1f', $tFire) . "ms][$userKey] 🔁 retry #" . ($nextAttempt - 1) . " fired\n";
            $eventLog[] = [$tFire, "[$userKey] fire try $nextAttempt"];
        }

        if (!empty($active)) {
            $sel = curl_multi_select($mh, 0.05);
            if ($sel === -1) usleep(1000);
        }
    } while (!empty($active));

    // Cleanup sisa
    foreach ($active as $key => $meta) {
        curl_multi_remove_handle($mh, $meta['ch']);
        @curl_close($meta['ch']);
    }
    curl_multi_close($mh);

    $phaseElapsed = (microtime(true) - $phaseStart) * 1000;

    echo "\n📊 Inquiry summary:\n";
    echo "   - success           : " . count($successMap) . "/" . count($orders) . "\n";
    echo "   - total inquiry call: $totalInquiries\n";
    echo "   - phase duration    : " . sprintf('%.1f ms', $phaseElapsed) . "\n";
    echo "   - per-user attempts : ";
    foreach ($attempts as $uk => $att) echo "$uk=$att  ";
    echo "\n";
    if (TREAT_STOP_AS_RETRY) {
        echo "   ⚠️  TES MODE aktif: 'out of stock' diperlakukan sebagai retry. Ganti TREAT_STOP_AS_RETRY=false untuk produksi.\n";
    }

    // ===== AUTO-SUMMARY untuk evaluasi VPS =====
    if (!empty($attemptStats)) {
        // Group by salvo number
        $salvos = [];
        foreach ($attemptStats as $s) {
            $salvos[$s['try']][] = $s;
        }
        ksort($salvos);

        echo "\n📈 [VPS-EVAL] Per-salvo breakdown:\n";
        foreach ($salvos as $tryNum => $list) {
            $rtts        = array_column($list, 'rtt');
            $srvArr      = array_filter(array_column($list, 'srv_arrival'), fn($v) => $v !== null);
            $verdicts    = array_count_values(array_column($list, 'verdict'));
            $verdictStr  = implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($verdicts), $verdicts));

            $rttStr    = empty($rtts) ? '-' : sprintf('min=%dms med=%dms max=%dms', min($rtts), percentile($rtts, 0.5), max($rtts));
            $srvStr    = empty($srvArr) ? '-' : sprintf('min=%+dms med=%+dms max=%+dms', (int) min($srvArr), (int) percentile($srvArr, 0.5), (int) max($srvArr));

            echo sprintf("   - Salvo #%d (n=%d): rtt[%s] srvArrival[%s] verdicts[%s]\n",
                $tryNum, count($list), $rttStr, $srvStr, $verdictStr);
        }

        // Hitung first-success server-arrival (kalibrasi sweet spot)
        $firstSuccess = null;
        $firstStop    = null;
        foreach ($attemptStats as $s) {
            if ($firstSuccess === null && $s['verdict'] === 'success' && $s['srv_arrival'] !== null) {
                $firstSuccess = $s;
            }
            if ($firstStop === null && $s['verdict'] === 'stop' && $s['srv_arrival'] !== null) {
                $firstStop = $s;
            }
        }
        echo "\n📈 [VPS-EVAL] Window kalibrasi:\n";
        if ($firstSuccess) {
            echo sprintf("   - First SUCCESS server-arrival: %+dms (sweet spot lower bound)\n", (int) $firstSuccess['srv_arrival']);
        } else {
            echo "   - First SUCCESS: tidak ada (zonk run)\n";
        }
        if ($firstStop) {
            echo sprintf("   - First OUT-OF-STOCK server-arrival: %+dms (window upper bound)\n", (int) $firstStop['srv_arrival']);
        }

        // Vps verdict
        $successCount = count($successMap);
        $tier = '⚠️  POOR (zonk)';
        if ($successCount >= 3) $tier = '✅ EXCELLENT (3+ voucher)';
        elseif ($successCount === 2) $tier = '🟢 GOOD (2 voucher)';
        elseif ($successCount === 1) $tier = '🟡 OK (1 voucher)';

        echo "\n📈 [VPS-EVAL] Verdict: $tier";
        if ($phaseElapsed > 1000) echo " | ⚠️  PHASE > 1s (kemungkinan kena window war yang ketat)";
        echo "\n";

        // Salvo #1 RTT vs mini-probe (kalau ada)
        $salvo1 = $salvos[1] ?? [];
        if (!empty($salvo1)) {
            $salvo1MedianRtt = percentile(array_column($salvo1, 'rtt'), 0.5);
            $rttTier = '✅ excellent';
            if ($salvo1MedianRtt > 500)      $rttTier = '🔴 critical (replace VPS)';
            elseif ($salvo1MedianRtt > 350)  $rttTier = '🟠 slow (consider replace)';
            elseif ($salvo1MedianRtt > 250)  $rttTier = '🟡 acceptable';
            echo "📈 [VPS-EVAL] Salvo #1 median RTT: {$salvo1MedianRtt}ms → $rttTier\n";
        }
    }
    echo "\n";

    return array_values($successMap);
}

// ----------------------------------------------------------------------
// PARALLEL PAYMENT (sama seperti versi lama, tetap pakai inquirySuccess)
// ----------------------------------------------------------------------
function runParallelPayment(array $inquirySuccess): int {
    if (empty($inquirySuccess)) return 0;

    echo "Memulai Parallel Payment...\n";
    $paymentMulti = curl_multi_init();
    $paymentChannels = [];

    foreach ($inquirySuccess as $entry) {
        $orderId = $entry['orderId'];

        $paymentBody = [
            'orderId' => $orderId,
            'paymentChannelId' => 73,
            'phoneNumber' => '628783219212',
            'paymentPhoneNumber' => '',
            'quantity' => 1,
            'invoiceUrl' => 'https://gopay.co.id/games/payment/',
        ];

        $payHeaders = $entry['headers'];
        $payHeaders['x-timestamp'] = (string) round(microtime(true) * 1000);
        $ref = substr(hash('sha256', $orderId), 0, 32);
        $payHeaders['x-request-reference'] = $ref;
        $payHeaders['x-request-id']        = $ref;
        $payHeaders['idempotency-key']     = $ref;

        $ch = createCurlSession();
        configureCurlHandle(
            $ch,
            'https://gopay.co.id/games/v1/order/payment',
            'POST',
            $payHeaders,
            $paymentBody,
            ['connect_timeout_ms' => PAYMENT_CONNECT_TO_MS, 'timeout_ms' => PAYMENT_TIMEOUT_MS]
        );
        $paymentChannels[] = [
            'ch'       => $ch,
            'order'    => $entry['order'],
            'orderId'  => $orderId,
            'headers'  => $payHeaders,
            'ref'      => $ref,
        ];
        curl_multi_add_handle($paymentMulti, $ch);
    }

    runMultiHandles($paymentMulti);

    $success = 0;
    $bufferedWrites = [];
    foreach ($paymentChannels as $item) {
        $resp = curl_multi_getcontent($item['ch']);
        $code = curl_getinfo($item['ch'], CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($paymentMulti, $item['ch']);

        $uid = $item['order']['userId'];
        $sid = $item['order']['serverId'];
        $orderId = $item['orderId'];

        echo "[$uid | $sid] Payment → ";

        if ($code !== 200 && $code !== 201) {
            $errorPayload = decodeResponseBody((string) $resp);
            $errorText = extractApiErrorMessage($errorPayload);
            echo "HTTP $code";
            if ($errorText !== '') echo " - $errorText";
            echo "\n";
            curl_close($item['ch']);
            continue;
        }

        $payRes = decodeResponseBody((string) $resp);
        $txnId = $payRes['data'] ?? null;
        if (!$txnId) {
            echo "tidak ada txnId\n";
            curl_close($item['ch']);
            continue;
        }

        echo "TxnID: $txnId → ";
        $txnData = getTransactionUntilReady($txnId, $item['headers'], $item['ch']);
        curl_close($item['ch']);
        if ($txnData) {
            $payUrl = $txnData['actionPayment']['paymentDirect'] ?? $txnData['actionPayment']['deeplinkRedirect'] ?? '(tidak tersedia)';
            $txnUrl = "https://gopay.co.id/games/payment/$txnId";
            $bufferedWrites[] = ['transaksi_url.txt', "$uid|$sid|$txnUrl\n"];
            $bufferedWrites[] = ['deeplinks.txt', "$payUrl\n"];
            $bufferedWrites[] = ['order_ids.txt', "$uid|$sid|$orderId|$payUrl\n"];
            echo "✅ SUCCESS | Pay URL tersedia\n";
            $success++;
        } else {
            echo "Poll selesai tapi tidak ada payment link\n";
        }
    }

    curl_multi_close($paymentMulti);
    foreach ($bufferedWrites as [$file, $line]) {
        file_put_contents($file, $line, FILE_APPEND);
    }
    return $success;
}

// ----------------------------------------------------------------------
// PRE-WAR: tunggu sampai T-{secondsBefore}s sebelum war start (dari waktu.txt).
// ----------------------------------------------------------------------
function waitUntilPreWarTest(int $secondsBefore = 60): void {
    $file = 'waktu.txt';
    if (!file_exists($file)) die("❌ File 'waktu.txt' tidak ditemukan!\n");
    $content = trim(file_get_contents($file));
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $content, $m)) {
        die("❌ Format waktu.txt salah! Gunakan HH:MM atau HH:MM:SS\n");
    }
    $hour = (int)$m[1]; $minute = (int)$m[2]; $second = isset($m[3]) ? (int)$m[3] : 0;
    $target = new DateTime('now');
    $target->setTime($hour, $minute, $second, 0);
    if ($target < new DateTime('now')) $target->modify('+1 day');

    $testTs = $target->getTimestamp() - $secondsBefore;
    $diff = $testTs - time();
    if ($diff <= 0) {
        // Script start sudah di dalam T-{secondsBefore}s → langsung jalankan tes.
        return;
    }
    echo "Menunggu fase tes koneksi (T-{$secondsBefore}s sebelum war)...\n";
    while (($d = $testTs - time()) > 0) {
        if ($d > 5) sleep($d - 2);
        else usleep(200000);
    }
}

// ----------------------------------------------------------------------
// PRE-WAR: pemanasan koneksi — hantam endpoint inquiry asli (voucher sama
// seperti waktu H war) sebanyak $times kali secara sekuensial. War belum mulai
// jadi response = "not available" dan TIDAK mengkonsumsi voucher. Tujuannya
// menghangatkan TCP/TLS pool (share handle) sebelum burst.
// ----------------------------------------------------------------------
function preWarConnectionTest(array $sampleOrder, string $captchaToken, int $times = 10): void {
    for ($i = 0; $i < $times; $i++) {
        $headers = buildInquiryHeaders($captchaToken);
        $body    = buildInquiryBody($sampleOrder);
        $ch = createCurlSession();
        configureCurlHandle(
            $ch,
            'https://gopay.co.id/games/v1/order/inquiry',
            'POST',
            $headers,
            $body,
            ['connect_timeout_ms' => INQUIRY_CONNECT_TO_MS, 'timeout_ms' => INQUIRY_TIMEOUT_MS]
        );
        @curl_exec($ch);
        curl_close($ch);
    }
    echo "Tes koneksi {$times}x selesai\n";
}

// ----------------------------------------------------------------------
// MAIN
// ----------------------------------------------------------------------
function gpyPay(): void {
    echo "=== PHASE 1: PRE-COMPUTATION ===\n";

    $lines = file('user_server_wdp.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $orders = [];
    foreach ($lines as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) >= 2) $orders[] = ['userId' => $parts[0], 'serverId' => $parts[1]];
    }
    $orders = array_slice($orders, 0, MAX_USERS);
    if (empty($orders)) {
        die("❌ Tidak ada order valid di user_server_wdp.txt\n");
    }

    echo "✅ Loaded " . count($orders) . " order (max " . MAX_USERS . ")\n";
    echo "🔧 Dynamic lead"
       . " | MAX_RETRY_PER_USER=" . MAX_RETRY_PER_USER
       . " | MAX_TOTAL_INQUIRIES=" . MAX_TOTAL_INQUIRIES . "\n\n";

    // Captcha 1× di-fetch saat script start
    $captchaToken = getFreshCaptchaToken();

    // Lead fallback dibaca dari lead.txt. Konvensi:
    //   NEGATIF di lead.txt = fire SEBELUM war start (duluan).
    //   POSITIF di lead.txt = fire SETELAH war start (telat).
    //   0 atau file tidak ada = tepat di war start.
    // Konvensi sama seperti Go: negatif = fire sebelum war, positif = setelah war.
    $leadFile = __DIR__ . '/lead.txt';
    if (file_exists($leadFile)) {
        $offsetMs = (int) trim(file_get_contents($leadFile));
    } else {
        $offsetMs = BURST_LEAD_MS_DEFAULT;
    }
    $burstLeadMs = $offsetMs;

    if ($offsetMs > 0)      $desc = "+{$offsetMs}ms (setelah war)";
    elseif ($offsetMs < 0)  $desc = "{$offsetMs}ms (sebelum war)";
    else                    $desc = "0ms (tepat di war)";
    echo "⚡ Lead fallback: {$desc} (dari " . (file_exists($leadFile) ? "lead.txt" : "default") . ")\n\n";

    // ===================== PRE-WAR (T-60s) =====================
    // 60 detik sebelum war: tes koneksi langsung ke API gopay dengan voucher
    // (pemanasan retry 10x), lalu ambil captcha baru untuk dipakai saat war.
    waitUntilPreWarTest(60);
    preWarConnectionTest($orders[0], $captchaToken, 10);
    $captchaToken = getFreshCaptchaToken(true);
    echo "Captcha Baru diperbarui\n\n";

    // Tunggu dan fire — mini-probe2 T-1.5s untuk warm TLS pool sebelum burst.
    // Callback menerima $budgetMs = sisa waktu aman untuk warm-up tanpa menunda burst.
    waitForExactBurstTime($burstLeadMs, $orders[0], $captchaToken, static function (int $budgetMs = 1200) use ($orders, $captchaToken): void {
        global $WARMUP_MEDIAN_RTT_MS, $WARMUP_MIN_RTT_MS;
        echo "[WARM-UP] T-" . (MINI_PROBE2_LEAD_MS / 1000) . "s re-warm TLS pool ("
           . MINI_PROBE2_PARALLEL . " call paralel, budget {$budgetMs}ms)...\n";
        $rtts = miniProbe2ReWarm($orders[0], $captchaToken, $budgetMs);
        if (!empty($rtts)) {
            sort($rtts);
            $min = $rtts[0];
            $median = percentile($rtts, 0.5);
            $WARMUP_MEDIAN_RTT_MS = $median;
            $WARMUP_MIN_RTT_MS = $min;
            echo "[WARM-UP] RTT: " . implode('ms, ', array_map(fn($v) => number_format($v, 0), $rtts)) . "ms"
               . " | min: " . number_format($min, 0) . "ms"
               . " | median: " . number_format($median, 0) . "ms\n";
        } else {
            echo "[WARM-UP] Tidak ada response dalam budget (koneksi lambat) — burst tetap on-time.\n";
        }
    });

    // ===================== ADAPTIVE INQUIRY =====================
    $inquirySuccess = runAdaptiveInquiry($orders, $captchaToken);

    // ===================== PARALLEL PAYMENT =====================
    $success = runParallelPayment($inquirySuccess);

    echo "\n🏁 FULL FLOW SELESAI! Berhasil: $success / " . count($orders) . "\n";
}

gpyPay();
