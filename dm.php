<?php
// ======================================================================
// GOPAY MLBB WDP - FULL FLOW PARALLEL (FIX HTTP 201 + Confirm Payment)
// Pre-compute Captcha + Parallel Inquiry + Payment + Poll
// ======================================================================
if (php_sapi_name() !== 'cli') {
    die("Script ini hanya boleh dijalankan via CLI\n");
}
date_default_timezone_set('Asia/Jakarta');
set_time_limit(0);
ignore_user_abort(true);

const CAPTCHA_FILE = 'captchadm.txt';
const CAPTCHA_FETCH_DELAY_MS = 450;
const DEFAULT_VOUCHER_CODE = 'WARWDPGG';

// ----------------------------------------------------------------------
// FUNGSI PEMBANTU (sama seperti script single kamu)
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
    return $ch;
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

function resolveVoucherCode(array $argv): string {
    $rawVoucher = $argv[1] ?? DEFAULT_VOUCHER_CODE;
    $voucher = trim($rawVoucher);
    if ($voucher !== '' && $voucher[0] === '-' && strlen($voucher) > 1) {
        $voucher = substr($voucher, 1);
    }

    if ($voucher === '') {
        die("❌ Kode voucher tidak boleh kosong.\n");
    }

    return $voucher;
}

function loadSavedCaptchaTokens(): array {
    if (!file_exists(CAPTCHA_FILE)) {
        return [];
    }

    $lines = file(CAPTCHA_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $lines), static fn(string $token): bool => $token !== ''));
}

function saveCaptchaTokens(array $tokens): void {
    if (empty($tokens)) {
        return;
    }

    file_put_contents(CAPTCHA_FILE, implode(PHP_EOL, $tokens) . PHP_EOL);
    echo "[CAPTCHA] " . count($tokens) . " token disimpan ke " . CAPTCHA_FILE . "\n";
}

function getCaptchaTokens(int $needed): array {
    if ($needed <= 0) {
        return [];
    }

    $tokens = loadSavedCaptchaTokens();
    $available = count($tokens);

    if ($available > 0) {
        echo "[CAPTCHA] Ditemukan {$available} token di " . CAPTCHA_FILE . "\n";
    }

    if ($available >= $needed) {
        echo "[CAPTCHA] Menggunakan {$needed} token yang sudah tersedia.\n";
        return array_slice($tokens, 0, $needed);
    }

    $missing = $needed - $available;
    echo "[CAPTCHA] Token tersedia kurang {$missing}, mengambil captcha baru...\n";

    for ($i = 0; $i < $missing; $i++) {
        $token = getFreshCaptchaToken();
        $tokens[] = $token;

        if ($i < $missing - 1) {
            usleep(CAPTCHA_FETCH_DELAY_MS * 1000);
        }
    }

    saveCaptchaTokens($tokens);
    return array_slice($tokens, 0, $needed);
}

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
        echo "[CAPTCHA] Token berhasil diambil (panjang: " . strlen($token) . " karakter)\n\n";
        return $token;
    }
    throw new RuntimeException("Gagal parse captcha token dari response Google");
}
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
// MAIN FULL FLOW
// ----------------------------------------------------------------------
function gpyPay(string $voucherCode): void {
    echo "=== PHASE 1: PRE-COMPUTATION ===\n";
    echo "🎟️ Voucher yang digunakan: {$voucherCode}\n";

    $lines = file('user_server_dm.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $orders = [];
    foreach ($lines as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) >= 2) $orders[] = ['userId' => $parts[0], 'serverId' => $parts[1]];
    }
    $orders = array_slice($orders, 0, 10);

    echo "✅ Loaded " . count($orders) . " order(s)\n";
    $captchaTokens = getCaptchaTokens(count($orders));

    $prepared = [];
    $totalOrders = count($orders);
    foreach ($orders as $index => $ord) {
        $no = $index + 1;
        echo "[ORDER $no/$totalOrders] Menyiapkan request untuk {$ord['userId']} | {$ord['serverId']} dengan captcha ke-$no...\n";
        $ua = getRandomUserAgent();
        $sentry = generateSentryTrace();

        $headers = [ /* header lengkap kamu */
            'sec-ch-ua-platform' => '"Android"',
            'authorization' => 'Bearer undefined',
            'sec-ch-ua' => $ua['sec-ch-ua'],
            'sec-ch-ua-mobile' => '?1',
            'baggage' => $sentry['baggage'],
            'sentry-trace' => $sentry['sentry-trace'],
            'user-agent' => $ua['user-agent'],
            'x-captcha-token' => $captchaTokens[$index],
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

        $body = [
            'productId' => 19,
            'productItemId' => 425,
            'data' => ['userId' => $ord['userId'], 'zoneId' => $ord['serverId']],
            'paymentChannelId' => 73,
            'phoneNumber' => '628783219212',
            'voucher' => $voucherCode,
            'quantity' => 1,
        ];

        $prepared[] = ['headers' => $headers, 'body' => $body, 'order' => $ord];
    }

    echo "\n✅ Semua request siap menggunakan captcha yang tersedia.\n";
    echo "🚀 Langsung mengeksekusi inquiry tanpa menunggu waktu terjadwal.\n\n";

    // ===================== PARALLEL INQUIRY =====================
    echo "Memulai Parallel Inquiry...\n";
    $mh = curl_multi_init();
    $channels = [];

    foreach ($prepared as $p) {
        $headers = $p['headers'];
        $headers['x-timestamp'] = (string) round(microtime(true) * 1000);
        $ch = createCurlSession();
        configureCurlHandle(
            $ch,
            'https://gopay.co.id/games/v1/order/inquiry',
            'POST',
            $headers,
            $p['body'],
            ['connect_timeout_ms' => 2200, 'timeout_ms' => 5200]
        );
        $channels[] = ['ch' => $ch, 'order' => $p['order'], 'headers' => $p['headers']];
        curl_multi_add_handle($mh, $ch);
    }

    runMultiHandles($mh);

    $inquirySuccess = [];
    foreach ($channels as $item) {
        $resp = curl_multi_getcontent($item['ch']);
        $code = curl_getinfo($item['ch'], CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $item['ch']);
        curl_close($item['ch']);

        $uid = $item['order']['userId'];
        $sid = $item['order']['serverId'];

        echo "[$uid | $sid] → ";

        if ($code !== 200 && $code !== 201) {
            $errorPayload = decodeResponseBody($resp);
            $errorText = extractApiErrorMessage($errorPayload);
            echo "Inquiry gagal HTTP $code";
            if ($errorText !== '') {
                echo " - $errorText";
            }
            echo "\n";
            continue;
        }

        $data = decodeResponseBody($resp);

        // PERBAIKAN: Ambil orderId meskipun ada "Confirm Payment"
        $orderId = $data['data']['orderId'] ?? $data['orderId'] ?? null;

        if (!$orderId) {
            echo "Tidak mendapat OrderID\n";
            continue;
        }

        echo "OrderID: $orderId\n";
        $inquirySuccess[] = [
            'order' => $item['order'],
            'orderId' => $orderId,
            'headers' => $item['headers'],
        ];
    }

    curl_multi_close($mh);

    // ===================== PARALLEL PAYMENT =====================
    echo "\nMemulai Parallel Payment...\n";
    $paymentMulti = curl_multi_init();
    $paymentChannels = [];

    foreach ($inquirySuccess as $entry) {
        $uid = $entry['order']['userId'];
        $sid = $entry['order']['serverId'];
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
        $payHeaders['x-request-id'] = $ref;
        $payHeaders['idempotency-key'] = $ref;

        $ch = createCurlSession();
        configureCurlHandle(
            $ch,
            'https://gopay.co.id/games/v1/order/payment',
            'POST',
            $payHeaders,
            $paymentBody,
            ['connect_timeout_ms' => 2200, 'timeout_ms' => 5200]
        );
        $paymentChannels[] = [
            'ch' => $ch,
            'order' => $entry['order'],
            'orderId' => $orderId,
            'headers' => $payHeaders,
            'ref' => $ref,
        ];
        curl_multi_add_handle($paymentMulti, $ch);
    }

    runMultiHandles($paymentMulti);

    // Process Payment + Poll
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
            $errorPayload = decodeResponseBody($resp);
            $errorText = extractApiErrorMessage($errorPayload);
            echo "HTTP $code";
            if ($errorText !== '') {
                echo " - $errorText";
            }
            echo "\n";
            curl_close($item['ch']);
            continue;
        }

        $payRes = decodeResponseBody($resp);
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
    echo "\n🎉 FULL FLOW SELESAI! Berhasil: $success / " . count($orders) . "\n";
}


$voucherCode = resolveVoucherCode($argv);
gpyPay($voucherCode);
