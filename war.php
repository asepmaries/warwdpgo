<?php
// ======================================================================
// GOPAY MLBB WDP - WAR EDITION (Fixed Lead + Staggered Inquiry)
//
// Strategi:
//  - Lead time fix dibaca dari lead.txt (per VPS), bukan auto-tune.
//    Konvensi: positif = fire SETELAH war | negatif = fire SEBELUM war.
//    Contoh: lead.txt isi -25 → fire 25ms sebelum 17:00:00 (T-25ms).
//  - Inquiry ditembak bertahap dari lead sampai tepat di akhir_lead.
//    Jeda = (akhir_lead - lead) / (jumlah user - 1).
//  - Warm-up tunggal T-1.5s (4 paralel) untuk warm TLS pool sebelum burst.
//  - Hanya satu salvo inquiry per user; response gagal tidak dikirim ulang.
//  - Target server-arrival adalah 0ms (T=0).
//  - Captcha dipakai sekali untuk semua user, di-cache 23 jam.
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
// Lead time dibaca dari lead.txt (per-VPS). Format: 1 angka dalam ms.
// Konvensi: NEGATIF = fire SEBELUM war start (duluan).
//           POSITIF = fire SETELAH war start (telat).
// Contoh isi lead.txt: -25 → fire T-25ms | 25 → fire T+25ms | 0 → tepat war.
const BURST_LEAD_MS_DEFAULT  = 0;            // Fallback kalau lead.txt tidak ada.
const END_LEAD_MS_DEFAULT    = -100;         // Fallback kalau akhir_lead.txt tidak ada.
const MINI_PROBE2_LEAD_MS    = 1500;         // Warm-up T-1.5s sebelum burst (warm TLS pool).
const MINI_PROBE2_PARALLEL   = 4;            // 4 koneksi paralel untuk warm-up TLS.
const MAX_USERS              = 10;           // Max user per VPS.
const INQUIRY_CONNECT_TO_MS  = 2200;
const INQUIRY_TIMEOUT_MS    = 5200;
const PAYMENT_CONNECT_TO_MS = 2200;
const PAYMENT_TIMEOUT_MS    = 5200;
const TARGET_SRV_MS_DEFAULT = 0.0;           // Target arrival server tepat T=0.

// Pola pesan error dari endpoint inquiry
const STOP_PATTERNS = [
    'out of stock', 'sold out', 'kuota habis', 'voucher habis',
    'stok habis', 'sudah habis', 'Transaction is suspicous',
];
const INVALID_USER_PATTERNS = [
    'role_null', 'role null', 'error_role_null',
    'invalid user', 'user not found', 'user_not_found',
    'error_invalidzoneid', 'invalid zone',
];
const SKIP_USER_PATTERNS = [
    'reached the redeem limit', 'already redeemed', 'sudah pernah',
    'act_subscrip_no_config', 'subscrip_no_config',
];
const REGION_BLOCK_PATTERNS = [
    'regional restrictions', 'region restriction', 'outside region',
    'outside regional', 'di luar region', 'diluar region', 'luar region',
    'luar zona promo', 'zona promo',
];
const RETRY_PATTERNS = [
    'not available', 'not yet', 'belum dimulai', 'belum tersedia',
    'tidak tersedia', 'try again', 'temporarily', 'service unavailable',
];

// ----------------------------------------------------------------------
// TIMING DARI waktu.txt
// ----------------------------------------------------------------------
function readOffsetMs(string $path, int $defaultMs, string $label): array {
    if (!file_exists($path)) {
        return [$defaultMs, false];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $raw = trim((string) ($lines[0] ?? ''));
    if (!preg_match('/^[+-]?\d+$/', $raw)) {
        die("❌ Nilai {$label} tidak valid. Baris pertama harus berupa angka milidetik.\n");
    }
    return [(int) $raw, true];
}

function waitForExactBurstTime(
    int $leadMs,
    ?callable $beforeBurst = null,
    ?callable $prepareBurst = null
): bool {
    global $WAR_START_WALL_US;
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
    // Simpan war start absolut (T=0) sebelum dimodify untuk lead
    $WAR_START_WALL_US = $target->getTimestamp() * 1_000_000 + ((int) $target->format('v')) * 1000;
    $target->modify($leadMs >= 0 ? "-{$leadMs} milliseconds" : "+" . abs($leadMs) . " milliseconds");
    $leadDescription = $leadMs >= 0
        ? "T-" . $leadMs . "ms (sebelum war)"
        : "T+" . abs($leadMs) . "ms (setelah war start)";
    echo "⏰ Target burst dari waktu.txt: {$content}.000 WIB\n";
    echo "🎯 Target server-arrival     : " . sprintf('%.0fms (T=0)', TARGET_SRV_MS_DEFAULT) . "\n";
    echo "⚡ Lead time eksekusi       : {$leadDescription}\n";
    echo "⚡ Burst dieksekusi pada    : " . $target->format('Y-m-d H:i:s') . sprintf('.%03d', (int) $target->format('v')) . " WIB\n";
    echo "Menunggu waktu tepat...\n\n";
    $targetTimestamp = $target->getTimestamp();
    $targetWallMicro = $targetTimestamp * 1_000_000;
    // FIX: getTimestamp() ter-floor ke detik. Tambahkan komponen milidetik via format('v').
    $targetWallMicro += ((int) $target->format('v')) * 1000;
    $diff = $targetTimestamp - time();
    if ($diff > 15) {
        sleep($diff - 12);
        echo "Masuk fase fine tuning (last 12 detik)...\n";
    } elseif ($diff > 6) {
        sleep($diff - 6);
        echo "Masuk fase fine tuning...\n";
    }
    $remainingNs = max(0, (int) round(($targetWallMicro - (microtime(true) * 1_000_000)) * 1000));
    $targetMono = hrtime(true) + $remainingNs;
    $preBurstTriggered = false;
    while (true) {
        $remaining = $targetMono - hrtime(true);
        if ($remaining <= 0) {
            if (!$preBurstTriggered && $prepareBurst !== null) {
                $prepareBurst();
            }
            return true;
        }
        $remainingUs = intdiv($remaining, 1000);
        if (!$preBurstTriggered && $remainingUs <= MINI_PROBE2_LEAD_MS * 1000) {
            $preBurstTriggered = true;
            // CRITICAL: warm-up tidak boleh menunda burst. Hitung budget = sisa waktu ke
            // burst dikurangi safety margin 200ms. Warm-up curl di-cut kalau lewat budget,
            // supaya VPS dengan koneksi cold/lambat tetap fire burst ON-TIME.
            // (Bukti war 30 Mei: warm-up 2000ms → burst telat 500ms → zonk total.)
            $budgetMs = intdiv($remainingUs, 1000) - 200;
            if ($beforeBurst !== null && $budgetMs >= 150) {
                $beforeBurst($budgetMs);
            } elseif ($beforeBurst !== null) {
                echo "[WARM-UP] Skip — sisa waktu ke burst < 350ms (jaga burst tetap on-time)\n";
            }
            // Siapkan seluruh handle setelah warm-up agar slot pertama hanya perlu
            // add + curl_multi_exec, tanpa biaya membangun header/body/cURL.
            if ($prepareBurst !== null) {
                $prepareBurst();
            }
            continue;
        }
        if ($remainingUs > 50000) usleep(12000);
        elseif ($remainingUs > 25000) usleep(4000);
        else continue;
    }
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

function formatWallTime(float $timestamp, int $fractionDigits = 4): string {
    $scale = 10 ** $fractionDigits;
    $ticks = (int) round($timestamp * $scale);
    $seconds = intdiv($ticks, $scale);
    $fraction = $ticks % $scale;
    return date('H:i:s', $seconds)
        . '.'
        . str_pad((string) $fraction, $fractionDigits, '0', STR_PAD_LEFT);
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
    $method = strtoupper($method);
    $headerLines = buildHeaderLines($headers, true);
    if ($body !== null && !array_key_exists('content-type', array_change_key_case($headers, CASE_LOWER))) {
        $headerLines[] = 'Content-Type: application/json';
    }
    $connectTimeoutMs = (int)($options['connect_timeout_ms'] ?? 2500);
    $timeoutMs = (int)($options['timeout_ms'] ?? 7000);
    $curlOptions = [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
        CURLOPT_TIMEOUT_MS => $timeoutMs,
    ];
    if ($body !== null) {
        $curlOptions[CURLOPT_POSTFIELDS] = is_array($body) ? json_encode($body) : $body;
    } else {
        $curlOptions[CURLOPT_POSTFIELDS] = null;
    }
    if ($method === 'GET') {
        // Pastikan handle yang pernah dipakai POST benar-benar kembali menjadi
        // GET tanpa request body.
        $curlOptions[CURLOPT_HTTPGET] = true;
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
 * Warm-up T-MINI_PROBE2_LEAD_MS sebelum burst (default 1.5s): GET endpoint
 * inquiry tanpa body/order/voucher, memakai MINI_PROBE2_PARALLEL koneksi
 * paralel supaya TCP/TLS pool benar-benar warm saat salvo war fire.
 * RTT yang dilaporkan hanya untuk informasi di log, tidak dipakai untuk
 * re-tune lead.
 *
 * $maxMs: budget timeout. Warm-up call di-cut kalau melebihi budget supaya
 *         TIDAK menunda burst (VPS koneksi cold/lambat tetap fire on-time).
 */
function miniProbe2ReWarm(int $maxMs = 1200): array {
    $maxMs = max(150, $maxMs);
    $connectTo = min(INQUIRY_CONNECT_TO_MS, $maxMs);
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < MINI_PROBE2_PARALLEL; $i++) {
        $ua = getRandomUserAgent();
        $headers = [
            'user-agent' => $ua['user-agent'],
            'sec-ch-ua' => $ua['sec-ch-ua'],
            'sec-ch-ua-platform' => '"Android"',
            'sec-ch-ua-mobile' => '?1',
            'accept' => '*/*',
            'origin' => 'https://gopay.co.id',
            'referer' => 'https://gopay.co.id/games/mobile-legends-bang-bang',
            'sec-fetch-site' => 'same-origin',
            'sec-fetch-mode' => 'cors',
            'sec-fetch-dest' => 'empty',
            'accept-language' => 'en-US,en;q=0.9',
        ];
        $ch = createCurlSession();
        configureCurlHandle(
            $ch,
            'https://gopay.co.id/games/v1/order/inquiry',
            'GET',
            $headers,
            null,
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
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($errno) continue;
        $totalSec = (float) ($info['total_time'] ?? 0);
        if ($totalSec > 0) $rttMs[] = $totalSec * 1000;
    }
    curl_multi_close($mh);
    return $rttMs;
}

// ----------------------------------------------------------------------
// UTILITAS: percentile (dipakai di ringkasan inquiry)
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
function getFreshCaptchaToken(): string {
    echo "[CAPTCHA] Mengambil token captcha baru dari Google...\n";
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
        saveCaptchaToken($token);
        echo "[CAPTCHA] Token berhasil diambil (panjang: " . strlen($token) . " karakter)\n\n";
        return $token;
    }
    throw new RuntimeException("Gagal parse captcha token dari response Google");
}

function saveCaptchaToken(string $token): void {
    file_put_contents('captcha_token.txt', $token);
    echo "[CAPTCHA] Token baru disimpan ke captcha_token.txt\n";
}

// ----------------------------------------------------------------------
// CLASSIFY RESPONSE INQUIRY
// Status "retry" hanya label klasifikasi agar sama dengan war.go.
// runStaggeredInquiry tetap single-shot dan tidak mengirim inquiry ulang.
// Return: ['status' => 'success'|'stop'|'user_invalid'|'skip_user'|'region_block'|'retry'|'unknown', 'orderId' => ?string]
// ----------------------------------------------------------------------
function classifyInquiryResponse(int $code, ?string $errorText, ?array $payload): array {
    if (($code === 200 || $code === 201) && is_array($payload)) {
        $orderId = $payload['data']['orderId'] ?? $payload['orderId'] ?? null;
        if (is_string($orderId) && $orderId !== '') {
            return ['status' => 'success', 'orderId' => $orderId];
        }
    }

    // war.go mencocokkan pola terhadap seluruh response body, bukan hanya
    // pesan error yang berhasil diekstrak.
    $payloadText = is_array($payload)
        ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : '';
    $msg = strtolower(trim((string) $errorText . ' ' . ($payloadText !== false ? $payloadText : '')));

    foreach (STOP_PATTERNS as $p) {
        if ($msg !== '' && strpos($msg, $p) !== false) {
            return ['status' => 'stop', 'orderId' => null];
        }
    }
    foreach (INVALID_USER_PATTERNS as $p) {
        if ($msg !== '' && strpos($msg, $p) !== false) {
            return ['status' => 'user_invalid', 'orderId' => null];
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
// PREPARE INQUIRY (satu handle per user, belum dikirim)
// ----------------------------------------------------------------------
function prepareInquiry(array $order, string $captchaToken): array {
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
    // Satu multi handle per user memungkinkan easy handle dipasang sebelum
    // jadwal. Pada slot tembak, curl_multi_exec tinggal mengaktifkan request.
    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $ch);
    return [
        'ch'       => $ch,
        'mh'       => $mh,
        'order'    => $order,
        'headers'  => $headers,
        'started'  => null,
    ];
}

// ----------------------------------------------------------------------
// SINGLE INQUIRY BERTAHAP
// ----------------------------------------------------------------------
function runStaggeredInquiry(
    array $preparedInquiries,
    int $leadOffsetMs,
    int $endLeadOffsetMs
): array {
    global $WAR_START_WALL_US;

    $totalUsers = count($preparedInquiries);
    if ($totalUsers === 0) return [];

    $distanceMs = $endLeadOffsetMs - $leadOffsetMs;
    if ($distanceMs <= 0 && $totalUsers > 1) {
        throw new InvalidArgumentException(
            "akhir_lead ({$endLeadOffsetMs}ms) harus lebih besar dari lead ({$leadOffsetMs}ms)"
        );
    }
    // Slot pertama tepat di lead dan slot terakhir tepat di akhir_lead.
    // Satu user tidak membutuhkan jeda dan ditembak tepat di lead.
    $intervalMs = $totalUsers > 1
        ? $distanceMs / ($totalUsers - 1)
        : 0.0;

    $successMap = [];      // userId|serverId -> ['order','orderId','headers']

    // Petakan T=0 wall-clock ke monotonic clock satu kali. Semua slot dihitung
    // dari anchor yang sama agar tidak terkena drift akibat usleep/RTT.
    $clockSampleWallUs = microtime(true) * 1_000_000;
    $clockSampleMonoNs = hrtime(true);
    $warStartMonoNs = $clockSampleMonoNs
        + (int) round(($WAR_START_WALL_US - $clockSampleWallUs) * 1000);
    $phaseStart = microtime(true);
    $inquiryStats = []; // [{user, rtt, srvArrival, http, verdict}]
    $launched = [];

    foreach ($preparedInquiries as $index => $meta) {
        $plannedOffsetMs = $index === $totalUsers - 1 && $totalUsers > 1
            ? (float) $endLeadOffsetMs
            : $leadOffsetMs + ($intervalMs * $index);
        $targetMonoNs = $warStartMonoNs + (int) round($plannedOffsetMs * 1_000_000);

        // Sambil menunggu slot berikutnya, request yang sudah ditembak tetap
        // dipompa oleh curl_multi. Dua puluh lima milidetik terakhir memakai
        // busy-wait agar resolusi sleep OS tidak membuat slot meleset.
        while (true) {
            foreach ($launched as $launchedIndex) {
                do {
                    $status = curl_multi_exec(
                        $preparedInquiries[$launchedIndex]['mh'],
                        $running
                    );
                } while ($status === CURLM_CALL_MULTI_PERFORM);
                $preparedInquiries[$launchedIndex]['running'] = $running;
            }

            $remainingNs = $targetMonoNs - hrtime(true);
            if ($remainingNs <= 0) break;

            $remainingUs = intdiv($remainingNs, 1000);
            if ($remainingUs > 50000) {
                usleep(12000);
            } elseif ($remainingUs > 25000) {
                usleep(4000);
            } else {
                continue;
            }
        }

        $preparedInquiries[$index]['planned_offset_ms'] = $plannedOffsetMs;
        $preparedInquiries[$index]['started'] = microtime(true);
        $preparedInquiries[$index]['fired_offset_ms'] = (
            $preparedInquiries[$index]['started'] * 1_000_000 - $WAR_START_WALL_US
        ) / 1000;
        do {
            $status = curl_multi_exec($preparedInquiries[$index]['mh'], $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        $preparedInquiries[$index]['running'] = $running;
        $launched[] = $index;
    }

    $scheduleSummary = sprintf(
        "? INQUIRY BERTAHAP: %d user | lead=%+dms | akhir=%+dms | jarak=%dms | jeda=%.3fms\n",
        $totalUsers,
        $leadOffsetMs,
        $endLeadOffsetMs,
        $distanceMs,
        $intervalMs
    );

    // Semua request sudah ditembak. Lanjutkan pump tanpa blocking select agar
    // response selesai secepat mungkin.
    do {
        $hasRunning = false;
        foreach ($launched as $launchedIndex) {
            do {
                $status = curl_multi_exec(
                    $preparedInquiries[$launchedIndex]['mh'],
                    $running
                );
            } while ($status === CURLM_CALL_MULTI_PERFORM);
            $preparedInquiries[$launchedIndex]['running'] = $running;
            if ($running > 0) {
                $hasRunning = true;
            }
        }
        if ($hasRunning) usleep(1000);
    } while ($hasRunning);

    // Panen response setelah seluruh handle selesai, tanpa mengirim ulang.
    foreach ($preparedInquiries as $meta) {
        $ch = $meta['ch'];
        $resp = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        $curlErrno = curl_errno($ch);

        $userKey = $meta['order']['userId'] . '|' . $meta['order']['serverId'];
        // Pakai total_time milik cURL, bukan waktu panen response. Response
        // user awal bisa sudah selesai saat scheduler masih menunggu slot lain.
        $totalTimeSec = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        if ($totalTimeSec <= 0) {
            $totalTimeSec = microtime(true) - $meta['started'];
        }
        $elapsed = (int) round($totalTimeSec * 1000);
        $responseCompletedAt = $meta['started'] + $totalTimeSec;
        $responseWallTime = formatWallTime($responseCompletedAt, 4);
        $tRel = ($responseCompletedAt - $phaseStart) * 1000;

        // Estimasi waktu request sampai di server (relatif ke WAR_START / T=0).
        // Asumsi RTT simetris: server-arrival = fire_wall + (rtt/2)
        $serverArrivalMs = null;
        if (!empty($WAR_START_WALL_US)) {
            $fireWallUs = $meta['started'] * 1_000_000;
            $serverArrivalUs = $fireWallUs + ($elapsed * 1000 / 2);
            $serverArrivalMs = ($serverArrivalUs - $WAR_START_WALL_US) / 1000;
        }
        $sArr = $serverArrivalMs !== null
            ? sprintf('srv%+.0fms', $serverArrivalMs)
            : 'srv?';

        $payload = $resp !== false && $resp !== null
            ? decodeResponseBody((string) $resp)
            : null;
        $errText = $payload
            ? extractApiErrorMessage($payload)
            : ($curlErrno ? "cURL[$curlErrno] $curlErr" : '');
        $verdict = classifyInquiryResponse((int) $code, $errText, $payload);

        $inquiryStats[] = [
            'user'         => $userKey,
            'rtt'          => $elapsed,
            'srv_arrival'  => $serverArrivalMs,
            'http'         => (int) $code,
            'verdict'      => $verdict['status'],
            'planned_fire' => $meta['planned_offset_ms'],
            'actual_fire'  => $meta['fired_offset_ms'],
        ];

        $tag = "[$responseWallTime][+" . sprintf('%6.1f', $tRel) . "ms][$userKey]"
             . "[plan" . sprintf('%+.1f', $meta['planned_offset_ms']) . "ms]"
             . "[fire" . sprintf('%+.1f', $meta['fired_offset_ms']) . "ms]"
             . "[single][rtt {$elapsed}ms][$sArr][HTTP $code]";

        if ($verdict['status'] === 'success') {
            echo "$tag ✅ OrderID: {$verdict['orderId']}\n";
            $successMap[$userKey] = [
                'order'   => $meta['order'],
                'orderId' => $verdict['orderId'],
                'headers' => $meta['headers'],
            ];
        } else {
            $shortErr = $errText !== '' ? substr($errText, 0, 80) : '(no message)';
            echo "$tag ⚠️  {$verdict['status']}: $shortErr\n";
        }

        curl_multi_remove_handle($meta['mh'], $ch);
        curl_multi_close($meta['mh']);
        @curl_close($ch);
    }

    $phaseElapsed = (microtime(true) - $phaseStart) * 1000;

    echo "\n" . $scheduleSummary;
    echo "? Inquiry summary:\n";
    echo "   - success           : " . count($successMap) . "/" . $totalUsers . "\n";
    echo "   - total inquiry call: " . $totalUsers . "\n";
    echo "   - phase duration    : " . sprintf('%.1f ms', $phaseElapsed) . "\n";

    // ===== AUTO-SUMMARY untuk evaluasi VPS =====
    if (!empty($inquiryStats)) {
        $fireDrifts = array_map(
            fn($s) => $s['actual_fire'] - $s['planned_fire'],
            $inquiryStats
        );
        $rtts        = array_column($inquiryStats, 'rtt');
        $srvArr      = array_filter(array_column($inquiryStats, 'srv_arrival'), fn($v) => $v !== null);
        $verdicts    = array_count_values(array_column($inquiryStats, 'verdict'));
        $verdictStr  = implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($verdicts), $verdicts));
        $rttStr      = empty($rtts) ? '-' : sprintf('min=%dms med=%dms max=%dms', min($rtts), percentile($rtts, 0.5), max($rtts));
        $srvStr      = empty($srvArr) ? '-' : sprintf('min=%+dms med=%+dms max=%+dms', (int) min($srvArr), (int) percentile($srvArr, 0.5), (int) max($srvArr));

        echo "\n? [VPS-EVAL] Inquiry bertahap:\n";
        echo sprintf("   - n=%d: rtt[%s] srvArrival[%s] verdicts[%s]\n",
            count($inquiryStats), $rttStr, $srvStr, $verdictStr);
        echo sprintf(
            "   - presisi fire: drift min=%+.3fms med=%+.3fms max=%+.3fms\n",
            min($fireDrifts),
            percentile($fireDrifts, 0.5),
            max($fireDrifts)
        );

        // Hitung first-success server-arrival (kalibrasi sweet spot)
        $firstSuccess = null;
        $firstStop    = null;
        foreach ($inquiryStats as $s) {
            if ($firstSuccess === null && $s['verdict'] === 'success' && $s['srv_arrival'] !== null) {
                $firstSuccess = $s;
            }
            if ($firstStop === null && $s['verdict'] === 'stop' && $s['srv_arrival'] !== null) {
                $firstStop = $s;
            }
        }
        echo "\n? [VPS-EVAL] Window kalibrasi:\n";
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
        elseif ($successCount === 2) $tier = '? GOOD (2 voucher)';
        elseif ($successCount === 1) $tier = '? OK (1 voucher)';

        echo "\n? [VPS-EVAL] Verdict: $tier";
        if ($phaseElapsed > 1000) echo " | ⚠️  PHASE > 1s (kemungkinan kena window war yang ketat)";
        echo "\n";

        // RTT inquiry bertahap vs mini-probe (kalau ada)
        if (!empty($inquiryStats)) {
            $salvo1MedianRtt = percentile(array_column($inquiryStats, 'rtt'), 0.5);
            $rttTier = '✅ excellent';
            if ($salvo1MedianRtt > 500)      $rttTier = '? critical (replace VPS)';
            elseif ($salvo1MedianRtt > 350)  $rttTier = '? slow (consider replace)';
            elseif ($salvo1MedianRtt > 250)  $rttTier = '? acceptable';
            echo "? [VPS-EVAL] Inquiry bertahap median RTT: {$salvo1MedianRtt}ms → $rttTier\n";
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
    echo "? Fixed lead from lead.txt"
       . " | TARGET_SRV=" . sprintf('%.0fms', TARGET_SRV_MS_DEFAULT)
       . " | STAGGERED_INQUIRY\n\n";

    // Lead time dibaca dari lead.txt. Konvensi:
    //   NEGATIF di lead.txt = fire SEBELUM war start (duluan).
    //   POSITIF di lead.txt = fire SETELAH war start (telat).
    //   0 atau file tidak ada = tepat di war start.
    // Internal `waitForExactBurstTime` pakai konvensi terbalik (positif = sebelum war),
    // jadi negate.
    $leadFile = __DIR__ . '/lead.txt';
    [$offsetMs, $leadFromFile] = readOffsetMs(
        $leadFile,
        BURST_LEAD_MS_DEFAULT,
        'lead.txt'
    );
    $burstLeadMs = -$offsetMs;

    // akhir_lead.txt adalah waktu tembak user terakhir (batas inklusif).
    // Jika file tidak tersedia, gunakan -100ms.
    $endLeadFile = __DIR__ . '/akhir_lead.txt';
    [$endOffsetMs, $endLeadFromFile] = readOffsetMs(
        $endLeadFile,
        END_LEAD_MS_DEFAULT,
        'akhir_lead.txt'
    );
    if ($endOffsetMs <= $offsetMs && count($orders) > 1) {
        die(
            "❌ Konfigurasi lead tidak valid: akhir_lead ({$endOffsetMs}ms) "
            . "harus lebih besar dari lead ({$offsetMs}ms)\n"
        );
    }

    if ($offsetMs > 0)      $desc = "+{$offsetMs}ms (setelah war)";
    elseif ($offsetMs < 0)  $desc = "{$offsetMs}ms (sebelum war)";
    else                    $desc = "0ms (tepat di war)";
    $intervalMs = count($orders) > 1
        ? ($endOffsetMs - $offsetMs) / (count($orders) - 1)
        : 0.0;
    echo "⚡ Lead offset : {$desc} (dari " . ($leadFromFile ? "lead.txt" : "default") . ")\n";
    echo "⚡ Akhir lead  : " . sprintf('%+dms', $endOffsetMs)
       . " (dari " . ($endLeadFromFile ? "akhir_lead.txt" : "default -100") . ")\n";
    echo "⚡ Jeda tembak : " . sprintf('%.3fms', $intervalMs)
       . " untuk " . count($orders) . " user\n\n";

    // Captcha 1× di-fetch setelah konfigurasi timing dinyatakan valid.
    $captchaToken = getFreshCaptchaToken();

    // Tunggu dan fire — mini-probe2 T-1.5s untuk warm TLS pool sebelum burst.
    // Callback menerima $budgetMs = sisa waktu aman untuk warm-up tanpa menunda burst.
    $preparedInquiries = [];
    waitForExactBurstTime(
        $burstLeadMs,
        static function (int $budgetMs = 1200): void {
            echo "[WARM-UP] T-" . (MINI_PROBE2_LEAD_MS / 1000) . "s re-warm TLS pool via GET tanpa voucher ("
               . MINI_PROBE2_PARALLEL . " call paralel, budget {$budgetMs}ms)...\n";
            $rtts = miniProbe2ReWarm($budgetMs);
            if (!empty($rtts)) {
                $median = percentile($rtts, 0.5);
                echo "[WARM-UP] RTT: " . implode('ms, ', array_map(fn($v) => number_format($v, 0), $rtts)) . "ms"
                   . " | median: " . number_format($median, 0) . "ms\n";
            } else {
                echo "[WARM-UP] Tidak ada response dalam budget (koneksi lambat) — burst tetap on-time.\n";
            }
        },
        static function () use (&$preparedInquiries, $orders, $captchaToken): void {
            foreach ($orders as $order) {
                $preparedInquiries[] = prepareInquiry($order, $captchaToken);
            }
        }
    );

    // ===================== SINGLE INQUIRY BERTAHAP =====================
    $inquirySuccess = runStaggeredInquiry($preparedInquiries, $offsetMs, $endOffsetMs);

    // ===================== PARALLEL PAYMENT =====================
    $success = runParallelPayment($inquirySuccess);

    echo "\n? FULL FLOW SELESAI! Berhasil: $success / " . count($orders) . "\n";
}

gpyPay();
