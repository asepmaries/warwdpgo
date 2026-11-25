#!/usr/bin/env php
<?php
// ======================================================================
// claim_bodies.php (v2 device-headers) — Klaim stok body SEKALI dari pool Cloudflare
// (Worker v2 + D1 device-headers) dan simpan ke bodies.json di folder ini.
//
// Bedanya dari v1: /lease v2 mengembalikan SATU perangkat beserta header
// identitasnya ({device:{model, headers:{...}}}), dan bodies.json ikut
// menyimpan perangkat itu — war.php v2 membaca header dari sini, bukan
// dari hardcode atau identitas acak. Claim lama (v1) tanpa device tetap
// jalan di war.php v2 lewat fallback SM-X700, tapi klaim baru wajib
// membawa device.
//
// Dijalankan sekali per VPS (oleh controller), BUKAN tiap war. Setelah
// klaim, VPS memakai bodies.json ini berulang tiap war; token dibuat per
// order dari body-nya masing-masing saat sisa waktu war <= 60 detik
// (lihat war.php).
//
//   php claim_bodies.php --count 10
//   php claim_bodies.php --api https://gopay-pool...workers.dev --vps vps-01 --count 10
//   php claim_bodies.php --check            (verifikasi bodies.json yang ada)
//   php claim_bodies.php --force --count 10 (klaim ulang, timpa stok lama)
//
// Pool tidak memakai API key (proyek pribadi); bodies.json TIDAK berisi kunci.
// Pool mencatat leased_by = nama VPS, jadi body VPS mati bisa dibebaskan
// operator via POST /release {vps} atau kembali sendiri setelah TTL 7 hari.
// ======================================================================
if (php_sapi_name() !== 'cli') {
    die("Script ini hanya boleh dijalankan via CLI\n");
}
date_default_timezone_set('Asia/Jakarta');

const CLAIM_DEFAULT_API = 'https://gopay-pool.daniraflis482.workers.dev';

function claimUsage(): void {
    echo "Penggunaan:\n";
    echo "  php claim_bodies.php [--count 10] [--vps <nama>] [--out <file>]\n";
    echo "  php claim_bodies.php --check [--out <file>]\n";
    echo "  php claim_bodies.php --force [--count 10]\n";
}

function claimArgValue(array $argv, string $name): ?string {
    $idx = array_search($name, $argv, true);
    if ($idx === false || !isset($argv[$idx + 1]) || strpos($argv[$idx + 1], '--') === 0) {
        return null;
    }
    return $argv[$idx + 1];
}

function validateClaimBodies(array $items): array {
    // Return [bodies, error]. bodies = [['id'=>int,'body'=>binary], ...]
    if (empty($items)) return [[], 'tidak ada body'];
    $bodies = [];
    $seen = [];
    foreach ($items as $item) {
        $id = (int) ($item['id'] ?? 0);
        $bin = base64_decode((string) ($item['body_b64'] ?? ''), true);
        if ($id <= 0) return [[], 'body tanpa id valid'];
        if ($bin === false || strlen($bin) < 1000) {
            return [[], "body #{$id} tidak valid (gagal decode / terlalu kecil)"];
        }
        if (isset($seen[$id])) return [[], "body duplikat (id={$id})"];
        $seen[$id] = true;
        $bodies[] = ['id' => $id, 'body' => $bin];
    }
    return [$bodies, null];
}

function loadBodiesFile(string $path): array {
    if (!is_file($path)) return [[], "file tidak ditemukan: {$path}"];
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        return [[], 'bodies.json tidak valid (bukan JSON / tidak ada items)'];
    }
    [$bodies, $err] = validateClaimBodies($data['items']);
    if ($err !== null) return [[], $err];
    return [$bodies, null];
}

function main(array $argv): int {
    $api = (string) (claimArgValue($argv, '--api') ?: CLAIM_DEFAULT_API);
    $vps = (string) (claimArgValue($argv, '--vps') ?: php_uname('n'));
    $count = (int) (claimArgValue($argv, '--count') ?: 10);
    $count = max(1, min(10, $count));
    $out = (string) (claimArgValue($argv, '--out') ?: (__DIR__ . '/bodies.json'));
    $check = in_array('--check', $argv, true);
    $force = in_array('--force', $argv, true);

    if ($check) {
        [$bodies, $err] = loadBodiesFile($out);
        if ($err !== null) {
            fwrite(STDERR, "❌ bodies.json tidak valid: {$err}\n");
            return 1;
        }
        $data = json_decode((string) file_get_contents($out), true);
        $dev = is_array($data['device'] ?? null) ? $data['device'] : null;
        echo "✅ bodies.json valid: " . count($bodies) . " body"
           . " (pool id: " . implode(',', array_column($bodies, 'id')) . ")"
           . " | ukuran: " . implode(',', array_map(static fn($b) => strlen($b['body']), $bodies)) . " byte\n";
        if ($dev !== null) {
            echo "   Perangkat: " . ($dev['model'] ?? '?')
               . " | UA: " . substr((string) ($dev['headers']['user-agent'] ?? '?'), 0, 80) . "\n";
        } else {
            echo "   ⚠️  Tanpa perangkat (klaim v1 lama) — war.php akan pakai fallback SM-X700.\n";
        }
        return 0;
    }

    if (is_file($out) && !$force) {
        [$bodies, $err] = loadBodiesFile($out);
        if ($err === null) {
            echo "ℹ️  Stok body sudah ada: " . count($bodies) . " body di {$out}."
               . " Pakai --force untuk klaim ulang (stok lama akan ditimpa; body lama tetap tercatat di pool atas nama VPS ini sampai di-release).\n";
            return 0;
        }
        echo "⚠️  {$out} ada tapi tidak valid ({$err}) — klaim ulang.\n";
    }

    echo "Klaim {$count} body dari {$api} untuk VPS '{$vps}'...\n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => rtrim($api, '/') . '/lease',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: gopay-claim/1.0',
        ],
        CURLOPT_POSTFIELDS => json_encode(['vps' => $vps, 'count' => $count]),
        CURLOPT_CONNECTTIMEOUT_MS => 5000,
        CURLOPT_TIMEOUT_MS => 30000,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);
    if ($resp === false || $resp === '' || $code !== 200) {
        fwrite(STDERR, "❌ Lease gagal (HTTP {$code}" . ($err !== '' ? ", cURL: {$err}" : "") . ").\n");
        return 1;
    }
    $data = json_decode((string) $resp, true);
    if (!is_array($data) || ($data['ok'] ?? false) !== true || empty($data['items'])) {
        fwrite(STDERR, "❌ Pool tidak memberi body: " . substr((string) $resp, 0, 200) . "\n");
        return 1;
    }
    $device = is_array($data['device'] ?? null) ? $data['device'] : null;
    if ($device === null || empty($device['headers']['user-agent'] ?? '')) {
        fwrite(STDERR, "❌ Pool v2 tidak mengembalikan perangkat+header. "
            . "Pastikan Worker v2 sudah ter-deploy (atau klaim pakai claim_bodies-v1.php).\n");
        return 1;
    }
    [$bodies, $verr] = validateClaimBodies($data['items']);
    if ($verr !== null) {
        fwrite(STDERR, "❌ Body dari pool tidak valid: {$verr}\n");
        return 1;
    }

    $payload = [
        'vps' => $vps,
        'claimed_at' => time(),
        'pool' => rtrim($api, '/'),
        'device' => $device,
        'items' => array_map(
            static fn($b) => ['id' => $b['id'], 'body_b64' => base64_encode($b['body'])],
            $bodies
        ),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($out, $json) === false) {
        fwrite(STDERR, "❌ Gagal menulis {$out}\n");
        return 1;
    }
    chmod($out, 0600);
    echo "✅ Stok tersimpan: " . count($bodies) . " body -> {$out}"
       . " (pool id: " . implode(',', array_column($bodies, 'id')) . ")\n";
    echo "   Perangkat: " . ($device['model'] ?? '?')
       . " — header identitas tersimpan di bodies.json untuk war.php.\n";
    echo "   Pool mencatat body ini atas nama VPS '{$vps}' (lease 7 hari, perpanjang via /renew).\n";
    return 0;
}

exit(main($_SERVER['argv'] ?? []));
