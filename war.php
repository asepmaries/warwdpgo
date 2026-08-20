<?php
// ======================================================================
// GOPAY MLBB WDP - WAR EDITION (Fixed Lead + Staggered Inquiry)
//
// Strategi:
//  - Lead time fix dibaca dari lead.txt (per VPS), bukan auto-tune.
//    Konvensi: positif = fire SETELAH war | negatif = fire SEBELUM war.
//    Contoh: lead.txt isi -25 → fire 25ms sebelum 17:00:00 (T-25ms).
//  - Tepat 10 user unik ditembak bertahap dari lead dengan jeda tetap 28ms.
//    Slot terakhir diturunkan otomatis dari lead dan jumlah user.
//  - Warm-up tunggal T-2.5s (10 paralel) untuk warm TLS pool sebelum burst.
//  - Hanya satu salvo inquiry per user; response gagal tidak dikirim ulang.
//  - Target server-arrival adalah 0ms (T=0).
//  - Captcha dipakai sekali untuk semua user dan dicoba ulang bila fetch gagal.
// ======================================================================
if (php_sapi_name() !== 'cli') {
    die("Script ini hanya boleh dijalankan via CLI\n");
}
$CLI_ARGS = $_SERVER['argv'] ?? [];
$TIMING_SELF_TEST_MODE =
    in_array('--timing-self-test', $CLI_ARGS, true)
    || in_array('--self-test-timing', $CLI_ARGS, true);
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
$LOG_FH   = $TIMING_SELF_TEST_MODE ? null : fopen($LOG_FILE, 'w');
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
const INQUIRY_STAGGER_MS     = 15;           // 10 slot mencakup 252ms; tidak memakai akhir_lead.txt.
const SCHEDULER_ARM_LEAD_MS  = 100;          // Scheduler mengambil alih 100ms sebelum slot pertama.
const MIN_DISPATCH_GAP_MS    = 8;            // Hindari request menumpuk bila host sempat stall.
const MINI_PROBE2_LEAD_MS    = 2500;         // Beri provider lambat waktu cukup untuk mengisi TLS pool.
const MINI_PROBE2_PARALLEL   = 10;           // Satu koneksi hangat untuk setiap slot inquiry.
const REQUIRED_USERS         = 10;           // War dibatalkan bila user unik kurang dari jumlah ini.
const MAX_USERS              = 10;           // Batas keras user per VPS/proses.
const CAPTCHA_MAX_ATTEMPTS   = 3;
const CAPTCHA_RETRY_BASE_MS  = 600;
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

function isPhpFunctionEnabled(string $name): bool {
    if (!function_exists($name)) return false;
    $disabled = array_filter(array_map(
        'trim',
        explode(',', (string) ini_get('disable_functions'))
    ));
    return !in_array($name, $disabled, true);
}

function trackingField(string $output, string $label): ?string {
    $pattern = '/^' . preg_quote($label, '/') . '\s*:\s*(.+)$/mi';
    if (!preg_match($pattern, $output, $match)) return null;
    return preg_replace('/\s+/', ' ', trim($match[1]));
}

function logClockSyncStatus(): void {
    $now = new DateTimeImmutable('now');
    $base = "[CLOCK] local=" . $now->format('Y-m-d H:i:s.uP')
          . " timezone=" . date_default_timezone_get();

    // PHP memakai clock OS. Chrony, bila aktif, mendisiplinkan clock OS ini.
    // Query dibatasi 2 detik dan dilakukan jauh sebelum fase war.
    if (!isPhpFunctionEnabled('shell_exec')) {
        echo $base . " chrony=not_checked(shell_exec_disabled)\n";
        return;
    }

    $tracking = @shell_exec('LC_ALL=C timeout 2s chronyc -n tracking 2>&1');
    if (is_string($tracking) && trackingField($tracking, 'Stratum') !== null) {
        $fields = [
            'stratum'         => trackingField($tracking, 'Stratum'),
            'system_time'     => trackingField($tracking, 'System time'),
            'last_offset'     => trackingField($tracking, 'Last offset'),
            'rms_offset'      => trackingField($tracking, 'RMS offset'),
            'root_delay'      => trackingField($tracking, 'Root delay'),
            'root_dispersion' => trackingField($tracking, 'Root dispersion'),
            'leap'            => trackingField($tracking, 'Leap status'),
        ];
        $parts = [];
        foreach ($fields as $key => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = $key . '="' . str_replace('"', '', $value) . '"';
            }
        }
        echo $base . " source=chrony " . implode(' ', $parts) . "\n";
        return;
    }

    $timedatectl = @shell_exec(
        'LC_ALL=C timeout 2s timedatectl show -p NTP -p NTPSynchronized --no-pager 2>&1'
    );
    $timedatectl = is_string($timedatectl)
        ? preg_replace('/\s+/', ' ', trim($timedatectl))
        : '';
    if ($timedatectl !== '' && stripos($timedatectl, 'not found') === false) {
        echo $base . ' source=timedatectl status="'
           . str_replace('"', '', $timedatectl) . "\"\n";
        return;
    }

    echo $base . " source=os chrony=unavailable\n";
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
                $prepareStart = hrtime(true);
                $prepareBurst();
                echo "[SCHED] Prepare terlambat selesai dalam "
                   . sprintf('%.3fms', (hrtime(true) - $prepareStart) / 1_000_000)
                   . "\n";
            }
            echo "[SCHED] MISSED_ARM: kontrol kembali "
               . sprintf('%.3fms', abs($remaining) / 1_000_000)
               . " setelah target slot pertama.\n";
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
                $warmStart = hrtime(true);
                $beforeBurst($budgetMs);
                echo "[SCHED] Warm-up duration="
                   . sprintf('%.3fms', (hrtime(true) - $warmStart) / 1_000_000)
                   . "\n";
            } elseif ($beforeBurst !== null) {
                echo "[WARM-UP] Skip — sisa waktu ke burst < 350ms (jaga burst tetap on-time)\n";
            }
            // Siapkan seluruh handle setelah warm-up agar slot pertama hanya perlu
            // add + curl_multi_exec, tanpa biaya membangun header/body/cURL.
            if ($prepareBurst !== null) {
                $prepareStart = hrtime(true);
                $prepareBurst();
                echo "[SCHED] Prepare " . REQUIRED_USERS . " handle duration="
                   . sprintf('%.3fms', (hrtime(true) - $prepareStart) / 1_000_000)
                   . "\n";
            }
            continue;
        }
        if ($remainingUs <= SCHEDULER_ARM_LEAD_MS * 1000) {
            echo "[SCHED] Armed dengan sisa="
               . sprintf('%.3fms', $remainingUs / 1000)
               . "; final wait memakai monotonic clock.\n";
            return true;
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

function curlHttpVersionLabel(int $version): string {
    $known = [
        'CURL_HTTP_VERSION_1_0' => '1.0',
        'CURL_HTTP_VERSION_1_1' => '1.1',
        'CURL_HTTP_VERSION_2_0' => '2',
        'CURL_HTTP_VERSION_2TLS' => '2',
        'CURL_HTTP_VERSION_3' => '3',
    ];
    foreach ($known as $constantName => $label) {
        if (defined($constantName) && $version === constant($constantName)) {
            return $label;
        }
    }
    return $version > 0 ? (string) $version : '?';
}

function collectCurlNetworkTiming($ch): array {
    $info = curl_getinfo($ch);
    $toMs = static fn($value): float => max(0.0, (float) $value * 1000.0);
    $numConnects = isset($info['num_connects']) ? (int) $info['num_connects'] : null;
    if (defined('CURLINFO_NUM_CONNECTS')) {
        $reportedConnects = @curl_getinfo($ch, constant('CURLINFO_NUM_CONNECTS'));
        if ($reportedConnects !== false) {
            $numConnects = (int) $reportedConnects;
        }
    }

    // Nilai *_time adalah timestamp kumulatif sejak curl dimulai.
    $dnsAt      = $toMs($info['namelookup_time'] ?? 0);
    $connectAt  = $toMs($info['connect_time'] ?? 0);
    $tlsAt      = $toMs($info['appconnect_time'] ?? 0);
    $readyAt    = $toMs($info['pretransfer_time'] ?? 0);
    $firstByteAt = $toMs($info['starttransfer_time'] ?? 0);
    $totalAt    = $toMs($info['total_time'] ?? 0);

    $tcpMs = $connectAt > 0 ? max(0.0, $connectAt - $dnsAt) : 0.0;
    $tlsMs = $tlsAt > 0 ? max(0.0, $tlsAt - $connectAt) : 0.0;
    $requestReadyAt = max($dnsAt, $connectAt, $tlsAt, $readyAt);
    $ttfbMs = $firstByteAt > 0
        ? max(0.0, $firstByteAt - $requestReadyAt)
        : null;
    $bodyMs = $firstByteAt > 0 && $totalAt >= $firstByteAt
        ? $totalAt - $firstByteAt
        : null;

    return [
        'dns_ms'       => $dnsAt,
        'tcp_ms'       => $tcpMs,
        'tls_ms'       => $tlsMs,
        'ttfb_ms'      => $ttfbMs,
        'body_ms'      => $bodyMs,
        'total_ms'     => $totalAt,
        'num_connects' => $numConnects,
        'http_version' => curlHttpVersionLabel((int) ($info['http_version'] ?? 0)),
        'remote_ip'    => (string) ($info['primary_ip'] ?? '?'),
        'remote_port'  => (int) ($info['primary_port'] ?? 0),
        'local_ip'     => (string) ($info['local_ip'] ?? '?'),
        'local_port'   => (int) ($info['local_port'] ?? 0),
    ];
}

function formatOptionalMs(?float $value): string {
    return $value === null ? '?' : sprintf('%.1fms', $value);
}

function formatCurlNetworkLog(
    string $userKey,
    float $plannedOffsetMs,
    float $firedOffsetMs,
    array $net,
    int $curlErrno
): string {
    $connects = $net['num_connects'] === null ? '?' : (string) $net['num_connects'];
    $reused = $net['num_connects'] === null
        ? '?'
        : ($net['num_connects'] === 0 ? 'yes' : 'no');
    return "[NET][$userKey]"
         . "[plan" . sprintf('%+.1f', $plannedOffsetMs) . "ms]"
         . "[fire" . sprintf('%+.1f', $firedOffsetMs) . "ms]"
         . "[dns" . formatOptionalMs($net['dns_ms']) . "]"
         . "[tcp" . formatOptionalMs($net['tcp_ms']) . "]"
         . "[tls" . formatOptionalMs($net['tls_ms']) . "]"
         . "[ttfb" . formatOptionalMs($net['ttfb_ms']) . "]"
         . "[body" . formatOptionalMs($net['body_ms']) . "]"
         . "[total" . formatOptionalMs($net['total_ms']) . "]"
         . "[connects=$connects reused=$reused http={$net['http_version']}]"
         . "[remote={$net['remote_ip']}:{$net['remote_port']}]"
         . "[local={$net['local_ip']}:{$net['local_port']}]"
         . "[errno=$curlErrno]";
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
 * Warm-up T-MINI_PROBE2_LEAD_MS sebelum burst (default 2.5s): HEAD ke root
 * host yang sama, memakai MINI_PROBE2_PARALLEL koneksi paralel supaya
 * TCP/TLS pool hangat tanpa menyentuh endpoint/order quota.
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
            'https://gopay.co.id/',
            'HEAD',
            $headers,
            null,
            ['connect_timeout_ms' => $connectTo, 'timeout_ms' => $maxMs]
        );
        curl_setopt($ch, CURLOPT_NOBODY, true);
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

    $multiResults = [];
    while ($multiInfo = curl_multi_info_read($mh)) {
        if (isset($multiInfo['handle'])) {
            $multiResults[curlHandleKey($multiInfo['handle'])] =
                (int) ($multiInfo['result'] ?? -1);
        }
    }
    $rttMs = [];
    foreach ($handles as $ch) {
        $errno = curl_errno($ch);
        $info  = curl_getinfo($ch);
        $multiResult = $multiResults[curlHandleKey($ch)] ?? null;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if (
            $errno !== 0
            || $multiResult !== CURLE_OK
            || (int) ($info['http_code'] ?? 0) <= 0
        ) {
            continue;
        }
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
    echo "[CAPTCHA] Mengambil token captcha baru dari Google"
       . " (maks " . CAPTCHA_MAX_ATTEMPTS . " percobaan)...\n";
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

    $lastError = 'unknown error';
    for ($attempt = 1; $attempt <= CAPTCHA_MAX_ATTEMPTS; $attempt++) {
        $ch = null;
        try {
            echo "[CAPTCHA] Percobaan {$attempt}/" . CAPTCHA_MAX_ATTEMPTS . "...\n";
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
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $errorMsg = curl_error($ch);

            if ($errno !== 0) {
                throw new RuntimeException("cURL [$errno] $errorMsg", $errno);
            }
            if ($httpCode !== 200 || !is_string($response) || $response === '') {
                throw new RuntimeException("HTTP {$httpCode} atau response kosong");
            }
            if (!preg_match('/"rresp","([^"]+)"/', $response, $matches)) {
                throw new RuntimeException("response Google tidak memuat rresp");
            }

            $token = $matches[1];
            saveCaptchaToken($token);
            echo "[CAPTCHA] Token berhasil diambil (panjang: "
               . strlen($token) . " karakter)\n\n";
            return $token;
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            echo "[CAPTCHA] Percobaan {$attempt} gagal: {$lastError}\n";
        } finally {
            if ($ch !== null) {
                @curl_close($ch);
            }
        }

        if ($attempt < CAPTCHA_MAX_ATTEMPTS) {
            $jitterMs = random_int(0, 250);
            $delayMs = (CAPTCHA_RETRY_BASE_MS * $attempt) + $jitterMs;
            echo "[CAPTCHA] Ulang dalam {$delayMs}ms...\n";
            usleep($delayMs * 1000);
        }
    }

    throw new RuntimeException(
        "Gagal mengambil CAPTCHA setelah " . CAPTCHA_MAX_ATTEMPTS
        . " percobaan: {$lastError}"
    );
}

function saveCaptchaToken(string $token): void {
    file_put_contents('captcha_token.txt', $token);
    echo "[CAPTCHA] Token baru disimpan ke captcha_token.txt\n";
}

// ----------------------------------------------------------------------
// CLASSIFY RESPONSE INQUIRY
// Status "retry" hanya label klasifikasi respons.
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

    // Cocokkan pola terhadap seluruh response body, bukan hanya pesan error
    // yang berhasil diekstrak.
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
        'productItemId' => 2434,
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
    // Easy handle baru dimasukkan ke satu shared multi-handle tepat pada
    // slotnya. Ini menghindari overhead memompa 10 multi-handle terpisah.
    return [
        'ch'       => $ch,
        'order'    => $order,
        'headers'  => $headers,
        'started'  => null,
    ];
}

// ----------------------------------------------------------------------
// SINGLE INQUIRY BERTAHAP
// ----------------------------------------------------------------------
function pumpCurlMultiNonBlocking($multi, int &$running): int {
    do {
        $status = curl_multi_exec($multi, $running);
    } while ($status === CURLM_CALL_MULTI_PERFORM);
    return $status;
}

function schedulerSpinGuardUs(): int {
    // PHP/Windows pada host lama dapat membulatkan usleep pendek menjadi
    // 15-30ms. Linux VPS punya sleep resolusi tinggi; guard 2ms memberi hasil
    // terbaik pada A/B VPS 1-core sekaligus hemat CPU/steal time.
    return PHP_OS_FAMILY === 'Windows' ? 25000 : 2000;
}

/**
 * Tunggu slot monotonic dengan sleep bertahap lalu spin pada guard terakhir.
 * Pump hanya boleh berjalan di luar guard; durasinya tetap dicatat agar log
 * dapat membedakan jitter host dari curl_multi yang melintasi target.
 */
function waitForMonotonicSlot(
    int $targetMonoNs,
    ?callable $pump = null,
    ?array &$telemetry = null
): int {
    $spinGuardUs = schedulerSpinGuardUs();
    $telemetry = [
        'spin_guard_us'       => $spinGuardUs,
        'pump_calls'          => 0,
        'pump_max_ms'         => 0.0,
        'pump_crossed_target' => false,
    ];

    while (true) {
        $nowMonoNs = hrtime(true);
        $remainingNs = $targetMonoNs - $nowMonoNs;
        if ($remainingNs <= 0) {
            return $nowMonoNs;
        }

        $remainingUs = intdiv($remainingNs, 1000);
        if ($remainingUs <= $spinGuardUs) {
            do {
                $releasedMonoNs = hrtime(true);
            } while ($releasedMonoNs < $targetMonoNs);
            return $releasedMonoNs;
        }

        if ($pump !== null) {
            $pumpStartMonoNs = hrtime(true);
            $pump();
            $pumpEndMonoNs = hrtime(true);
            $pumpDurationMs = ($pumpEndMonoNs - $pumpStartMonoNs) / 1_000_000;
            $telemetry['pump_calls']++;
            $telemetry['pump_max_ms'] = max(
                $telemetry['pump_max_ms'],
                $pumpDurationMs
            );
            if (
                $pumpStartMonoNs < $targetMonoNs
                && $pumpEndMonoNs >= $targetMonoNs
            ) {
                $telemetry['pump_crossed_target'] = true;
            }

            // Pump dapat menghabiskan sebagian/seluruh budget. Hitung ulang
            // sebelum sleep agar tidak tidur melewati target.
            $nowMonoNs = hrtime(true);
            $remainingNs = $targetMonoNs - $nowMonoNs;
            if ($remainingNs <= 0) {
                return $nowMonoNs;
            }
            $remainingUs = intdiv($remainingNs, 1000);
            if ($remainingUs <= $spinGuardUs) {
                do {
                    $releasedMonoNs = hrtime(true);
                } while ($releasedMonoNs < $targetMonoNs);
                return $releasedMonoNs;
            }
        }

        $sleepBudgetUs = $remainingUs - $spinGuardUs;
        $sleepFloorUs = PHP_OS_FAMILY === 'Windows' ? 16000 : 500;
        if ($sleepBudgetUs <= $sleepFloorUs) {
            do {
                $releasedMonoNs = hrtime(true);
            } while ($releasedMonoNs < $targetMonoNs);
            return $releasedMonoNs;
        }
        $sleepUs = min(
            $sleepBudgetUs,
            12000,
            max($sleepFloorUs, intdiv($sleepBudgetUs, 2))
        );
        usleep($sleepUs);
    }
}

function curlHandleKey($handle): string {
    return is_object($handle)
        ? 'o' . spl_object_id($handle)
        : 'r' . (int) $handle;
}

function guardedDispatchTargetMonoNs(
    int $nominalTargetMonoNs,
    ?int $lastDispatchMonoNs
): int {
    if ($lastDispatchMonoNs === null) {
        return $nominalTargetMonoNs;
    }
    return max(
        $nominalTargetMonoNs,
        $lastDispatchMonoNs + (MIN_DISPATCH_GAP_MS * 1_000_000)
    );
}

function runStaggeredInquiry(
    array $preparedInquiries,
    int $leadOffsetMs
): array {
    global $WAR_START_WALL_US;

    $totalUsers = count($preparedInquiries);
    if ($totalUsers === 0) return [];

    // Slot pertama tepat di lead. Sembilan slot berikutnya maju 28ms.
    // Contoh lead -426ms: -426, -398, ... hingga -174ms.
    $intervalMs = (float) INQUIRY_STAGGER_MS;
    $distanceMs = $totalUsers > 1
        ? INQUIRY_STAGGER_MS * ($totalUsers - 1)
        : 0;
    $endLeadOffsetMs = $leadOffsetMs + $distanceMs;

    $successMap = [];      // userId|serverId -> ['order','orderId','headers']

    // Petakan T=0 wall-clock ke monotonic clock satu kali. Semua slot dihitung
    // dari anchor yang sama agar tidak terkena drift akibat usleep/RTT.
    $clockSampleMonoBeforeNs = hrtime(true);
    $clockSampleWallUs = microtime(true) * 1_000_000;
    $clockSampleMonoAfterNs = hrtime(true);
    $clockSampleMonoNs = intdiv(
        $clockSampleMonoBeforeNs + $clockSampleMonoAfterNs,
        2
    );
    $clockSampleWindowUs = (
        $clockSampleMonoAfterNs - $clockSampleMonoBeforeNs
    ) / 1000;
    $warStartMonoNs = $clockSampleMonoNs
        + (int) round(($WAR_START_WALL_US - $clockSampleWallUs) * 1000);
    $phaseStart = microtime(true);
    $inquiryStats = []; // [{user, rtt, srvArrival, http, verdict}]
    $sharedMulti = curl_multi_init();
    if ($sharedMulti === false) {
        throw new RuntimeException('Gagal membuat shared curl_multi untuk inquiry');
    }
    $running = 0;
    $multiStatus = CURLM_OK;
    $lastDispatchMonoNs = null;
    echo "[SCHED] platform=" . PHP_OS_FAMILY
       . " spinGuard=" . sprintf('%.3fms', schedulerSpinGuardUs() / 1000)
       . " arm=" . SCHEDULER_ARM_LEAD_MS . "ms"
       . " minGap=" . MIN_DISPATCH_GAP_MS . "ms"
       . " clockMapWindow=" . sprintf('%.3fus', $clockSampleWindowUs) . "\n";

    foreach ($preparedInquiries as $index => $meta) {
        $plannedOffsetMs = $leadOffsetMs + ($intervalMs * $index);
        $nominalTargetMonoNs = $warStartMonoNs
            + (int) round($plannedOffsetMs * 1_000_000);
        $guardedTargetMonoNs = guardedDispatchTargetMonoNs(
            $nominalTargetMonoNs,
            $lastDispatchMonoNs
        );
        $guardDelayMs = max(
            0.0,
            ($guardedTargetMonoNs - $nominalTargetMonoNs) / 1_000_000
        );
        $waitTelemetry = null;

        waitForMonotonicSlot(
            $guardedTargetMonoNs,
            static function () use ($sharedMulti, &$running, &$multiStatus): void {
                $multiStatus = pumpCurlMultiNonBlocking($sharedMulti, $running);
            },
            $waitTelemetry
        );

        $preparedInquiries[$index]['planned_offset_ms'] = $plannedOffsetMs;
        $preparedInquiries[$index]['guarded_offset_ms'] = (
            $guardedTargetMonoNs - $warStartMonoNs
        ) / 1_000_000;
        $preparedInquiries[$index]['guard_delay_ms'] = $guardDelayMs;
        $preparedInquiries[$index]['wait_pump_max_ms'] =
            $waitTelemetry['pump_max_ms'] ?? 0.0;
        $preparedInquiries[$index]['wait_pump_crossed_target'] =
            (bool) ($waitTelemetry['pump_crossed_target'] ?? false);
        $addStartMonoNs = hrtime(true);
        $addStatus = curl_multi_add_handle(
            $sharedMulti,
            $preparedInquiries[$index]['ch']
        );
        $preparedInquiries[$index]['add_duration_ms'] = (
            hrtime(true) - $addStartMonoNs
        ) / 1_000_000;
        if ($addStatus !== CURLM_OK) {
            throw new RuntimeException(
                "Gagal memasang inquiry slot " . ($index + 1)
                . " ke shared multi: CURLM {$addStatus}"
            );
        }

        // add_handle belum mengirim request. Timestamp fire diambil tepat
        // sebelum curl_multi_exec yang mengaktifkan transfer.
        $preparedInquiries[$index]['started'] = microtime(true);
        $dispatchStartMonoNs = hrtime(true);
        $lastDispatchMonoNs = $dispatchStartMonoNs;
        $preparedInquiries[$index]['fired_offset_ms'] = (
            $dispatchStartMonoNs - $warStartMonoNs
        ) / 1_000_000;
        $multiStatus = pumpCurlMultiNonBlocking($sharedMulti, $running);
        $preparedInquiries[$index]['dispatch_duration_ms'] = (
            hrtime(true) - $dispatchStartMonoNs
        ) / 1_000_000;
        if ($multiStatus !== CURLM_OK) {
            throw new RuntimeException(
                "Scheduler inquiry gagal pada slot " . ($index + 1)
                . ": CURLM {$multiStatus}"
            );
        }
    }

    $scheduleSummary = sprintf(
        "? INQUIRY BERTAHAP: %d user | lead=%+dms | akhir=%+dms | jarak=%dms | jeda=%.3fms\n",
        $totalUsers,
        $leadOffsetMs,
        $endLeadOffsetMs,
        $distanceMs,
        $intervalMs
    );

    // Semua request sudah ditembak. Lanjutkan satu event loop bersama sampai
    // seluruh response selesai.
    do {
        $multiStatus = pumpCurlMultiNonBlocking($sharedMulti, $running);
        if ($multiStatus !== CURLM_OK) {
            throw new RuntimeException("Event loop inquiry gagal: CURLM {$multiStatus}");
        }
        if ($running > 0) {
            $selected = curl_multi_select($sharedMulti, 0.01);
            if ($selected === -1) {
                usleep(500);
            }
        }
    } while ($running > 0);

    $multiResults = [];
    while ($multiInfo = curl_multi_info_read($sharedMulti)) {
        if (isset($multiInfo['handle'])) {
            $multiResults[curlHandleKey($multiInfo['handle'])] =
                (int) ($multiInfo['result'] ?? 0);
        }
    }

    // Panen response setelah seluruh handle selesai, tanpa mengirim ulang.
    foreach ($preparedInquiries as $meta) {
        $ch = $meta['ch'];
        $multiResult = $multiResults[curlHandleKey($ch)] ?? 0;
        $resp = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        $curlErrno = curl_errno($ch);
        // Error transfer per-handle berada di queue curl_multi_info_read().
        // Tanpa ini timeout multi pernah tercatat menyesatkan sebagai errno=0.
        if ($curlErrno === 0 && $multiResult !== 0) {
            $curlErrno = $multiResult;
            if ($curlErr === '' && function_exists('curl_strerror')) {
                $curlErr = curl_strerror($multiResult);
            }
        }
        $net = collectCurlNetworkTiming($ch);

        $userKey = $meta['order']['userId'] . '|' . $meta['order']['serverId'];
        // Pakai total_time milik cURL, bukan waktu panen response. Response
        // user awal bisa sudah selesai saat scheduler masih menunggu slot lain.
        $totalTimeSec = $net['total_ms'] / 1000.0;
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
            'guarded_fire' => $meta['guarded_offset_ms'],
            'guard_delay'  => $meta['guard_delay_ms'],
            'actual_fire'  => $meta['fired_offset_ms'],
            'add_ms'       => $meta['add_duration_ms'] ?? null,
            'exec_ms'      => $meta['dispatch_duration_ms'] ?? null,
            'pump_max_ms'  => $meta['wait_pump_max_ms'] ?? null,
            'pump_crossed' => $meta['wait_pump_crossed_target'] ?? false,
            'dns_ms'       => $net['dns_ms'],
            'tcp_ms'       => $net['tcp_ms'],
            'tls_ms'       => $net['tls_ms'],
            'ttfb_ms'      => $net['ttfb_ms'],
            'num_connects' => $net['num_connects'],
        ];

        $tag = "[$responseWallTime][+" . sprintf('%6.1f', $tRel) . "ms][$userKey]"
             . "[plan" . sprintf('%+.1f', $meta['planned_offset_ms']) . "ms]"
             . "[fire" . sprintf('%+.1f', $meta['fired_offset_ms']) . "ms]"
             . "[single guard" . sprintf('%+.1f', $meta['guard_delay_ms']) . "ms]"
             . "[rtt {$elapsed}ms][$sArr][HTTP $code]";

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

        echo formatCurlNetworkLog(
            $userKey,
            (float) $meta['planned_offset_ms'],
            (float) $meta['fired_offset_ms'],
            $net,
            $curlErrno
        ) . "\n";

        curl_multi_remove_handle($sharedMulti, $ch);
        @curl_close($ch);
    }
    curl_multi_close($sharedMulti);

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
        $addValues = array_values(array_filter(
            array_column($inquiryStats, 'add_ms'),
            static fn($value) => $value !== null
        ));
        $execValues = array_values(array_filter(
            array_column($inquiryStats, 'exec_ms'),
            static fn($value) => $value !== null
        ));
        $dispatchWindowValues = [];
        foreach ($inquiryStats as $stat) {
            if ($stat['add_ms'] !== null && $stat['exec_ms'] !== null) {
                $dispatchWindowValues[] = $stat['add_ms'] + $stat['exec_ms'];
            }
        }
        $guardValues = array_column($inquiryStats, 'guard_delay');
        $guardCount = count(array_filter(
            $guardValues,
            static fn($value) => $value > 0.001
        ));
        $pumpCrossedCount = count(array_filter(
            array_column($inquiryStats, 'pump_crossed')
        ));
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
        echo sprintf(
            "   - anti-tumpuk: minGap=%dms guard=%d/%d maxShift=%.3fms"
            . " pumpCrossed=%d\n",
            MIN_DISPATCH_GAP_MS,
            $guardCount,
            count($inquiryStats),
            empty($guardValues) ? 0.0 : max($guardValues),
            $pumpCrossedCount
        );
        if (!empty($dispatchWindowValues)) {
            echo sprintf(
                "   - dispatch window: add med=%.3fms | exec med=%.3fms"
                . " | total p95=%.3fms max=%.3fms\n",
                percentile($addValues, 0.5),
                percentile($execValues, 0.5),
                percentile($dispatchWindowValues, 0.95),
                max($dispatchWindowValues)
            );
        }
        $dnsValues = array_column($inquiryStats, 'dns_ms');
        $tcpValues = array_column($inquiryStats, 'tcp_ms');
        $tlsValues = array_column($inquiryStats, 'tls_ms');
        $ttfbValues = array_values(array_filter(
            array_column($inquiryStats, 'ttfb_ms'),
            static fn($value) => $value !== null
        ));
        $knownConnects = array_values(array_filter(
            array_column($inquiryStats, 'num_connects'),
            static fn($value) => $value !== null
        ));
        $reusedCount = count(array_filter(
            $knownConnects,
            static fn($value) => $value === 0
        ));
        echo sprintf(
            "   - curl median: dns=%.1fms tcp=%.1fms tls=%.1fms ttfb=%s | reused=%s\n",
            percentile($dnsValues, 0.5),
            percentile($tcpValues, 0.5),
            percentile($tlsValues, 0.5),
            empty($ttfbValues) ? '?' : sprintf('%.1fms', percentile($ttfbValues, 0.5)),
            empty($knownConnects) ? '?' : "{$reusedCount}/" . count($knownConnects)
        );

        // Estimasi fire + RTT/2 disimpan hanya sebagai diagnostik.
        // TTFB mengandung antrean/backend, jadi ini bukan arrival origin.
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
        echo "\n? [VPS-EVAL] srvEst (diagnostik, bukan dasar tuning lead):\n";
        if ($firstSuccess) {
            echo sprintf("   - First SUCCESS srvEst: %+dms\n", (int) $firstSuccess['srv_arrival']);
        } else {
            echo "   - First SUCCESS: tidak ada (zonk run)\n";
        }
        if ($firstStop) {
            echo sprintf("   - First OUT-OF-STOCK srvEst: %+dms\n", (int) $firstStop['srv_arrival']);
        }

        // Vps verdict
        $successCount = count($successMap);
        $tier = '⚠️  POOR (zonk)';
        if ($successCount >= 3) $tier = '✅ EXCELLENT (3+ voucher)';
        elseif ($successCount === 2) $tier = '? GOOD (2 voucher)';
        elseif ($successCount === 1) $tier = '? OK (1 voucher)';

        echo "\n? [VPS-EVAL] Verdict: $tier";
        if ($phaseElapsed > 1000) echo " | ⚠️  PHASE > 1s (TTFB/antrean backend tinggi)";
        echo "\n";

        // RTT war didominasi TTFB origin. Kualitas VPS dinilai dari clock,
        // connection reuse, TCP dan TLS; bukan angka RTT ini saja.
        if (!empty($inquiryStats)) {
            $salvo1MedianRtt = percentile(array_column($inquiryStats, 'rtt'), 0.5);
            echo "? [VPS-EVAL] Inquiry median RTT/TTFB: {$salvo1MedianRtt}ms"
               . " (observasi backend; bukan alasan tunggal mengganti VPS)\n";
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
            'body'     => $paymentBody,
            'ref'      => $ref,
        ];
        curl_multi_add_handle($paymentMulti, $ch);
    }

    runMultiHandles($paymentMulti);

    $success = 0;
    $bufferedWrites = [];
    foreach ($paymentChannels as $item) {
        $paymentHandle = $item['ch'];
        $paymentHeaders = $item['headers'];
        $resp = curl_multi_getcontent($paymentHandle);
        $code = curl_getinfo($paymentHandle, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($paymentMulti, $paymentHandle);

        $uid = $item['order']['userId'];
        $sid = $item['order']['serverId'];
        $orderId = $item['orderId'];

        echo "[$uid | $sid] Payment → ";

        // Inquiry/order sudah berhasil. Untuk kegagalan transport tanpa HTTP
        // response atau origin 5xx, retry sekali memakai idempotency key sama.
        if ($code === 0 || $code >= 500) {
            echo "HTTP $code; retry idempotent 1x → ";
            curl_close($paymentHandle);

            $paymentHeaders['x-timestamp'] = (string) round(microtime(true) * 1000);
            $paymentHandle = createCurlSession();
            configureCurlHandle(
                $paymentHandle,
                'https://gopay.co.id/games/v1/order/payment',
                'POST',
                $paymentHeaders,
                $item['body'],
                ['connect_timeout_ms' => PAYMENT_CONNECT_TO_MS, 'timeout_ms' => PAYMENT_TIMEOUT_MS]
            );
            $resp = curl_exec($paymentHandle);
            $code = curl_getinfo($paymentHandle, CURLINFO_HTTP_CODE);
        }

        if ($code !== 200 && $code !== 201) {
            $errorPayload = decodeResponseBody((string) $resp);
            $errorText = extractApiErrorMessage($errorPayload);
            echo "HTTP $code";
            if ($errorText !== '') echo " - $errorText";
            echo "\n";
            curl_close($paymentHandle);
            continue;
        }

        $payRes = decodeResponseBody((string) $resp);
        $txnId = $payRes['data'] ?? null;
        if (!$txnId) {
            echo "tidak ada txnId\n";
            curl_close($paymentHandle);
            continue;
        }

        echo "TxnID: $txnId → ";
        $txnData = getTransactionUntilReady($txnId, $paymentHeaders, $paymentHandle);
        curl_close($paymentHandle);
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
// OFFLINE TIMING SELF-TEST
// ----------------------------------------------------------------------
function timingSelfTestIterations(array $args, int $default = 20): int {
    foreach ($args as $arg) {
        if (preg_match('/^--iterations=(\d+)$/', (string) $arg, $match)) {
            return max(1, min(200, (int) $match[1]));
        }
    }
    return $default;
}

function runTimingSelfTest(int $iterations = 20): int {
    $shots = REQUIRED_USERS;
    $intervalMs = (float) INQUIRY_STAGGER_MS;
    $intervalNs = (int) round($intervalMs * 1_000_000);
    $expectedSpanMs = $intervalMs * max(0, $shots - 1);

    $latenessMs = [];
    $intervalErrorAbsMs = [];
    $spanErrorAbsMs = [];
    $perShotWorstMs = array_fill(0, $shots, 0.0);
    $lateOver1 = 0;
    $lateOver5 = 0;
    $lateOver10 = 0;
    $lateAtLeastInterval = 0;
    $dispatchOver1 = 0;
    $dispatchOver5 = 0;
    $dispatchOver10 = 0;
    $dispatchAtLeastInterval = 0;
    $badRounds = 0;
    $missedIntervalRounds = 0;
    $guardApplications = 0;
    $pumpCrossedTarget = 0;
    $pumpMaxMs = 0.0;
    $addDurationMs = [];
    $dispatchDurationMs = [];
    $dispatchEndLatenessMs = [];
    $dispatchEndOver1 = 0;
    $dispatchEndOver5 = 0;
    $dispatchEndOver10 = 0;
    $dispatchEndAtLeastInterval = 0;
    $fileTransfersValidated = 0;
    $worstRound = 0;
    $worstShot = 0;
    $worstLateMs = -INF;

    echo "[TIMING-TEST] OFFLINE: tidak menghubungi Google/GoPay"
       . " dan tidak menyentuh loghasil.txt\n";
    echo "[TIMING-TEST] iterations={$iterations} shots={$shots}"
       . " interval=" . sprintf('%.3fms', $intervalMs)
       . " span=" . sprintf('%.3fms', $expectedSpanMs) . "\n";
    echo "[TIMING-TEST] platform=" . PHP_OS_FAMILY
       . " spin_guard=" . sprintf('%.3fms', schedulerSpinGuardUs() / 1000)
       . " arm=" . SCHEDULER_ARM_LEAD_MS . "ms"
       . " min_dispatch_gap=" . MIN_DISPATCH_GAP_MS . "ms\n";

    // Boundary unit-check anti-tumpuk.
    $unitPreviousNominalNs = 1_000_000_000;
    $unitNextNominalNs = $unitPreviousNominalNs + $intervalNs;
    $antiStackCases = [
        ['name' => 'first', 'late_ms' => null, 'shift_ms' => 0.0],
        ['name' => 'on_time', 'late_ms' => 0.0, 'shift_ms' => 0.0],
        ['name' => 'late20', 'late_ms' => 20.0, 'shift_ms' => 0.0],
        ['name' => 'late21', 'late_ms' => 21.0, 'shift_ms' => 1.0],
        ['name' => 'late27', 'late_ms' => 27.0, 'shift_ms' => 7.0],
        ['name' => 'late56', 'late_ms' => 56.0, 'shift_ms' => 36.0],
    ];
    foreach ($antiStackCases as $case) {
        $lastDispatchNs = $case['late_ms'] === null
            ? null
            : $unitPreviousNominalNs
                + (int) round($case['late_ms'] * 1_000_000);
        $unitGuardedNs = guardedDispatchTargetMonoNs(
            $unitNextNominalNs,
            $lastDispatchNs
        );
        $unitGuardShiftMs = (
            $unitGuardedNs - $unitNextNominalNs
        ) / 1_000_000;
        $gapValid = $lastDispatchNs === null
            || $unitGuardedNs - $lastDispatchNs
                >= MIN_DISPATCH_GAP_MS * 1_000_000;
        if (
            abs($unitGuardShiftMs - $case['shift_ms']) > 0.000001
            || $unitGuardedNs < $unitNextNominalNs
            || !$gapValid
        ) {
            throw new RuntimeException(
                "Unit-check anti-tumpuk {$case['name']} gagal:"
                . " shift={$unitGuardShiftMs}ms"
            );
        }
    }
    echo "[TIMING-TEST] anti_stack_unit=PASS cases="
       . count($antiStackCases) . " shifts=0,0,0,1,7,36ms\n";

    $selfTestPath = str_replace('\\', '/', __FILE__);
    $selfTestFileUrl = (PHP_OS_FAMILY === 'Windows' ? 'file:///' : 'file://')
        . str_replace(' ', '%20', $selfTestPath);

    for ($round = 0; $round < $iterations; $round++) {
        // Easy handle disiapkan sebelum arm, sama seperti inquiry produksi.
        $testHandles = [];
        for ($shot = 0; $shot < $shots; $shot++) {
            $testHandle = curl_init($selfTestFileUrl);
            if ($testHandle === false) {
                throw new RuntimeException('Gagal membuat local cURL self-test');
            }
            curl_setopt_array($testHandle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_NOSIGNAL => true,
            ]);
            $testHandles[] = $testHandle;
        }

        // Anchor ditentukan sebelum setup multi agar budget arm 100ms ikut diuji.
        $anchorMonoNs = hrtime(true)
            + (SCHEDULER_ARM_LEAD_MS * 1_000_000);
        // Shared multi memakai file:// lokal: add_handle + exec ikut diuji tanpa
        // mengirim trafik jaringan.
        $testMulti = curl_multi_init();
        if ($testMulti === false) {
            throw new RuntimeException('Gagal membuat curl_multi untuk timing self-test');
        }
        $running = 0;
        $multiStatus = CURLM_OK;
        $released = [];
        $lastReleaseMonoNs = null;
        $roundWorstDispatchEndLateMs = 0.0;

        for ($shot = 0; $shot < $shots; $shot++) {
            $nominalTargetMonoNs = $anchorMonoNs + ($shot * $intervalNs);
            $guardedTargetMonoNs = guardedDispatchTargetMonoNs(
                $nominalTargetMonoNs,
                $lastReleaseMonoNs
            );
            if ($guardedTargetMonoNs > $nominalTargetMonoNs) {
                $guardApplications++;
            }

            $waitTelemetry = null;
            waitForMonotonicSlot(
                $guardedTargetMonoNs,
                static function () use ($testMulti, &$running, &$multiStatus): void {
                    $multiStatus = pumpCurlMultiNonBlocking($testMulti, $running);
                },
                $waitTelemetry
            );
            if ($multiStatus !== CURLM_OK) {
                curl_multi_close($testMulti);
                throw new RuntimeException(
                    "curl_multi self-test gagal: CURLM {$multiStatus}"
                );
            }

            $addStartMonoNs = hrtime(true);
            $addStatus = curl_multi_add_handle(
                $testMulti,
                $testHandles[$shot]
            );
            $addMs = (hrtime(true) - $addStartMonoNs) / 1_000_000;
            $addDurationMs[] = $addMs;
            if ($addStatus !== CURLM_OK) {
                curl_multi_close($testMulti);
                throw new RuntimeException(
                    "add_handle self-test gagal: CURLM {$addStatus}"
                );
            }
            // Ini titik fire yang sama dengan produksi: tepat sebelum exec.
            $actualMonoNs = hrtime(true);
            $multiStatus = pumpCurlMultiNonBlocking($testMulti, $running);
            $dispatchMs = (
                hrtime(true) - $actualMonoNs
            ) / 1_000_000;
            $dispatchDurationMs[] = $dispatchMs;
            if ($dispatchMs > 1.0) $dispatchOver1++;
            if ($dispatchMs > 5.0) $dispatchOver5++;
            if ($dispatchMs > 10.0) $dispatchOver10++;
            if ($dispatchMs >= $intervalMs) $dispatchAtLeastInterval++;
            if ($multiStatus !== CURLM_OK) {
                curl_multi_close($testMulti);
                throw new RuntimeException(
                    "dispatch self-test gagal: CURLM {$multiStatus}"
                );
            }

            $lastReleaseMonoNs = $actualMonoNs;
            $pumpMaxMs = max(
                $pumpMaxMs,
                (float) ($waitTelemetry['pump_max_ms'] ?? 0.0)
            );
            if (!empty($waitTelemetry['pump_crossed_target'])) {
                $pumpCrossedTarget++;
            }

            $released[] = $actualMonoNs;
            // Tetap ukur terhadap target nominal. Shift anti-tumpuk tidak
            // di-clamp agar host stall tetap terlihat apa adanya.
            $late = ($actualMonoNs - $nominalTargetMonoNs) / 1_000_000;
            $latenessMs[] = $late;
            $perShotWorstMs[$shot] = max($perShotWorstMs[$shot], $late);
            if ($late > 1.0) $lateOver1++;
            if ($late > 5.0) $lateOver5++;
            if ($late > 10.0) $lateOver10++;
            if ($late >= $intervalMs) $lateAtLeastInterval++;
            $dispatchEndLateMs = $late + $dispatchMs;
            $dispatchEndLatenessMs[] = $dispatchEndLateMs;
            $roundWorstDispatchEndLateMs = max(
                $roundWorstDispatchEndLateMs,
                $dispatchEndLateMs
            );
            if ($dispatchEndLateMs > 1.0) $dispatchEndOver1++;
            if ($dispatchEndLateMs > 5.0) $dispatchEndOver5++;
            if ($dispatchEndLateMs > 10.0) $dispatchEndOver10++;
            if ($dispatchEndLateMs >= $intervalMs) {
                $dispatchEndAtLeastInterval++;
            }
            if ($late > $worstLateMs) {
                $worstLateMs = $late;
                $worstRound = $round + 1;
                $worstShot = $shot + 1;
            }

            if ($shot > 0) {
                $actualIntervalMs = (
                    $actualMonoNs - $released[$shot - 1]
                ) / 1_000_000;
                $intervalErrorAbsMs[] = abs($actualIntervalMs - $intervalMs);
            }
        }

        $actualSpanMs = (
            $released[$shots - 1] - $released[0]
        ) / 1_000_000;
        $spanErrorAbsMs[] = abs($actualSpanMs - $expectedSpanMs);
        if ($roundWorstDispatchEndLateMs > 5.0) {
            $badRounds++;
        }
        if ($roundWorstDispatchEndLateMs >= $intervalMs) {
            $missedIntervalRounds++;
        }

        do {
            $multiStatus = pumpCurlMultiNonBlocking($testMulti, $running);
        } while ($multiStatus === CURLM_OK && $running > 0);
        if ($multiStatus !== CURLM_OK) {
            curl_multi_close($testMulti);
            throw new RuntimeException(
                "finalize self-test gagal: CURLM {$multiStatus}"
            );
        }
        $testResults = [];
        while ($multiInfo = curl_multi_info_read($testMulti)) {
            if (isset($multiInfo['handle'])) {
                $testResults[curlHandleKey($multiInfo['handle'])] =
                    (int) ($multiInfo['result'] ?? -1);
            }
        }
        foreach ($testHandles as $testHandle) {
            $testResult = $testResults[curlHandleKey($testHandle)] ?? null;
            if ($testResult !== CURLE_OK || curl_errno($testHandle) !== 0) {
                curl_multi_remove_handle($testMulti, $testHandle);
                curl_close($testHandle);
                curl_multi_close($testMulti);
                throw new RuntimeException(
                    "Transfer file:// self-test gagal; result="
                    . var_export($testResult, true)
                );
            }
            $fileTransfersValidated++;
            curl_multi_remove_handle($testMulti, $testHandle);
            curl_close($testHandle);
        }
        curl_multi_close($testMulti);
    }

    $medianLate = percentile($latenessMs, 0.5);
    $p95Late = percentile($latenessMs, 0.95);
    $p99Late = percentile($latenessMs, 0.99);
    $maxLate = max($latenessMs);
    $p95Interval = percentile($intervalErrorAbsMs, 0.95);
    $maxInterval = max($intervalErrorAbsMs);
    $p95Span = percentile($spanErrorAbsMs, 0.95);
    $maxSpan = max($spanErrorAbsMs);
    $localDispatchWindowMs = [];
    foreach ($addDurationMs as $index => $addMs) {
        $localDispatchWindowMs[] = $addMs + $dispatchDurationMs[$index];
    }
    $p95Add = percentile($addDurationMs, 0.95);
    $p95Exec = percentile($dispatchDurationMs, 0.95);
    $p95LocalWindow = percentile($localDispatchWindowMs, 0.95);
    $maxLocalWindow = max($localDispatchWindowMs);
    $p95DispatchEndLate = percentile($dispatchEndLatenessMs, 0.95);
    $maxDispatchEndLate = max($dispatchEndLatenessMs);

    echo sprintf(
        "[TIMING-TEST] lateness  min=%.3fms med=%.3fms"
        . " p95=%.3fms p99=%.3fms max=%.3fms\n",
        min($latenessMs),
        $medianLate,
        $p95Late,
        $p99Late,
        $maxLate
    );
    echo sprintf(
        "[TIMING-TEST] interval |error| p95=%.3fms max=%.3fms\n",
        $p95Interval,
        $maxInterval
    );
    echo sprintf(
        "[TIMING-TEST] span %.0fms |error| p95=%.3fms max=%.3fms\n",
        $expectedSpanMs,
        $p95Span,
        $maxSpan
    );
    echo "[TIMING-TEST] worst/shot: "
       . implode(', ', array_map(
           static fn($index, $value) => sprintf(
               'T%d=%.3fms',
               $index + 1,
               $value
           ),
           array_keys($perShotWorstMs),
           $perShotWorstMs
       ))
       . "\n";

    $badRoundRate = ($badRounds / $iterations) * 100;
    echo sprintf(
        "[TIMING-TEST] fire outliers >1ms=%d >5ms=%d >10ms=%d >=%.0fms=%d\n",
        $lateOver1,
        $lateOver5,
        $lateOver10,
        $intervalMs,
        $lateAtLeastInterval
    );
    echo sprintf(
        "[TIMING-TEST] dispatch-end lateness p95=%.3fms max=%.3fms"
        . " | >1ms=%d >5ms=%d >10ms=%d >=%.0fms=%d"
        . " | bad_rounds=%d/%d (%.1f%%)\n",
        $p95DispatchEndLate,
        $maxDispatchEndLate,
        $dispatchEndOver1,
        $dispatchEndOver5,
        $dispatchEndOver10,
        $intervalMs,
        $dispatchEndAtLeastInterval,
        $badRounds,
        $iterations,
        $badRoundRate
    );
    echo sprintf(
        "[TIMING-TEST] guard_applied=%d pump_crossed_target=%d"
        . " pump_max=%.3fms | worst=round%d/T%d %.3fms\n",
        $guardApplications,
        $pumpCrossedTarget,
        $pumpMaxMs,
        $worstRound,
        $worstShot,
        $worstLateMs
    );
    echo sprintf(
        "[TIMING-TEST] LOCAL_CURL_DISPATCH_SYNTHETIC"
        . " add[p95=%.3fms] exec[p95=%.3fms]"
        . " total[med=%.3fms p95=%.3fms max=%.3fms]"
        . " validated=%d/%d\n",
        $p95Add,
        $p95Exec,
        percentile($localDispatchWindowMs, 0.5),
        $p95LocalWindow,
        $maxLocalWindow,
        $fileTransfersValidated,
        $iterations * $shots
    );
    echo sprintf(
        "[TIMING-TEST] synthetic exec outliers >1ms=%d >5ms=%d >10ms=%d"
        . " >=%.0fms=%d\n",
        $dispatchOver1,
        $dispatchOver5,
        $dispatchOver10,
        $intervalMs,
        $dispatchAtLeastInterval
    );

    $corePass = $medianLate <= 0.10
             && $p95Late <= 1.0
             && $p95Interval <= 1.5;
    $coreWarn = !$corePass
             && $medianLate <= 0.25
             && $p95Late <= 2.0
             && $p95Interval <= 3.0;
    $coreStatus = $corePass ? 'PASS' : ($coreWarn ? 'WARN' : 'FAIL');

    $localPass = $p95LocalWindow <= 2.0
              && $fileTransfersValidated === $iterations * $shots;
    $localWarn = !$localPass
              && $p95LocalWindow <= 4.0
              && $fileTransfersValidated === $iterations * $shots;
    $localStatus = $localPass ? 'PASS' : ($localWarn ? 'WARN' : 'FAIL');

    $hostGood = $badRounds === 0
             && $missedIntervalRounds === 0
             && $p95Span <= 3.0;
    $hostWarn = !$hostGood
             && $badRoundRate <= 5.0
             && $missedIntervalRounds === 0
             && $p95Span <= 6.0;
    $hostStatus = $hostGood ? 'GOOD' : ($hostWarn ? 'WARN' : 'FAIL');

    if (
        $coreStatus === 'FAIL'
        || $localStatus === 'FAIL'
        || $hostStatus === 'FAIL'
    ) {
        $result = 'FAIL';
        $exitCode = 1;
    } elseif (
        $coreStatus === 'PASS'
        && $localStatus === 'PASS'
        && $hostStatus === 'GOOD'
    ) {
        $result = 'PASS';
        $exitCode = 0;
    } else {
        $result = 'WARN';
        $exitCode = 2;
    }

    echo "[TIMING-TEST] SCHEDULER_CORE={$coreStatus}"
       . " LOCAL_CURL_DISPATCH={$localStatus}"
       . " HOST_JITTER={$hostStatus}\n";
    echo "[TIMING-TEST] RESULT={$result}"
       . " (kualifikasi final: ulang >=50x di VPS Linux idle dan saat CPU load)\n";
    return $exitCode;
}

// ----------------------------------------------------------------------
// MAIN
// ----------------------------------------------------------------------
function gpyPay(): void {
    echo "=== PHASE 1: PRE-COMPUTATION ===\n";
    logClockSyncStatus();

    $lines = file('user_server_wdp.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $orders = [];
    $seenOrders = [];
    $duplicateOrders = [];
    $invalidOrderLines = [];
    foreach ($lines as $line) {
        $parts = array_map('trim', explode('|', $line));
        $userId = ltrim((string) ($parts[0] ?? ''), "\xEF\xBB\xBF");
        $serverId = (string) ($parts[1] ?? '');
        if (count($parts) < 2 || $userId === '' || $serverId === '') {
            $invalidOrderLines[] = trim($line);
            continue;
        }

        $orderKey = $userId . '|' . $serverId;
        if (isset($seenOrders[$orderKey])) {
            $duplicateOrders[$orderKey] = true;
            continue;
        }
        $seenOrders[$orderKey] = true;
        $orders[] = ['userId' => $userId, 'serverId' => $serverId];
    }
    if (!empty($invalidOrderLines)) {
        throw new RuntimeException(
            "user_server_wdp.txt memuat " . count($invalidOrderLines)
            . " baris tidak valid; setiap baris minimal wajib userId|serverId"
        );
    }
    if (!empty($duplicateOrders)) {
        throw new RuntimeException(
            "user_server_wdp.txt memuat user duplikat: "
            . implode(', ', array_keys($duplicateOrders))
        );
    }
    if (count($orders) !== REQUIRED_USERS || count($orders) > MAX_USERS) {
        throw new RuntimeException(
            "Diperlukan tepat " . REQUIRED_USERS
            . " akun unik (userId|serverId) per VPS; ditemukan " . count($orders)
        );
    }

    echo "✅ Loaded " . count($orders) . " order (max " . MAX_USERS . ")\n";
    echo "[CONFIG] Akun unik tervalidasi: " . count($orders)
       . "/" . REQUIRED_USERS . "\n";
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

    if ($offsetMs > 0)      $desc = "+{$offsetMs}ms (setelah war)";
    elseif ($offsetMs < 0)  $desc = "{$offsetMs}ms (sebelum war)";
    else                    $desc = "0ms (tepat di war)";
    $intervalMs = count($orders) > 1 ? (float) INQUIRY_STAGGER_MS : 0.0;
    $endOffsetMs = $offsetMs + (int) round($intervalMs * max(0, count($orders) - 1));
    echo "⚡ Lead offset : {$desc} (dari " . ($leadFromFile ? "lead.txt" : "default") . ")\n";
    echo "⚡ Akhir lead  : " . sprintf('%+dms', $endOffsetMs)
       . " (otomatis dari lead + jeda tetap)\n";
    echo "⚡ Jeda tembak : " . sprintf('%.3fms', $intervalMs)
       . " untuk " . count($orders) . " user\n\n";

    // Captcha 1× di-fetch setelah konfigurasi timing dinyatakan valid.
    $captchaToken = getFreshCaptchaToken();

    // Tunggu dan fire — mini-probe2 T-2.5s untuk warm TLS pool sebelum burst.
    // Callback menerima $budgetMs = sisa waktu aman untuk warm-up tanpa menunda burst.
    $preparedInquiries = [];
    waitForExactBurstTime(
        $burstLeadMs,
        static function (int $budgetMs = 1200): void {
            echo "[WARM-UP] T-" . (MINI_PROBE2_LEAD_MS / 1000)
               . "s re-warm TLS pool via HEAD root tanpa endpoint order ("
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
    $inquirySuccess = runStaggeredInquiry($preparedInquiries, $offsetMs);

    // ===================== PARALLEL PAYMENT =====================
    $success = runParallelPayment($inquirySuccess);

    echo "\n? FULL FLOW SELESAI! Berhasil: $success / " . count($orders) . "\n";
}

if ($TIMING_SELF_TEST_MODE) {
    exit(runTimingSelfTest(timingSelfTestIterations($CLI_ARGS)));
}

try {
    gpyPay();
} catch (Throwable $e) {
    echo "\n[FATAL] " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}
