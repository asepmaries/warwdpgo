#!/usr/bin/env bash
# Uji fase klaim stok body pool di install.sh (claim_pool_bodies).
# Tidak menyentuh jaringan: stok fixture memakai body dummy (bukan stok
# pool asli), dan claim_bodies.php melihat stok valid lalu tidak klaim lagi.
# Aturan v3: klaim WAJIB membawa perangkat (pool v2). bodies.json v1
# (tanpa perangkat) harus klaim ulang pakai --force; bodies.json v2
# (dengan perangkat) dipertahankan.
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
installer="$repo_root/install.sh"

source "$installer"

fail() {
  printf 'pool claim test failed: %s\n' "$*" >&2
  exit 1
}

test_root="$(mktemp -d)"
trap 'rm -rf "$test_root"' EXIT
APP_DIR="$test_root/app"
mkdir -p "$APP_DIR"
cp "$repo_root/claim_bodies.php" "$APP_DIR/claim_bodies.php"

# Fixture bodies.json v2 valid: 1 body dummy 1500 byte, id 999, + perangkat.
dummy_body="$(php -r 'echo base64_encode(random_bytes(1500));')"
printf '{"vps":"test","claimed_at":1,"pool":"https://example.invalid","device":{"model":"Sony G8342","headers":{"user-agent":"Mozilla/5.0 stub"}},"items":[{"id":999,"body_b64":"%s"}]}' \
  "$dummy_body" > "$APP_DIR/bodies.json"
chmod 0600 "$APP_DIR/bodies.json"

IS_LINUX=1
POOL_CLAIM_ENABLED=1
POOL_CLAIM_COUNT=10

# 0) bodies_has_device: v2 -> 0, v1 -> 1, invalid -> 1, hilang -> 1.
bodies_has_device "$APP_DIR/bodies.json" \
  || fail "bodies_has_device: fixture v2 tidak dikenali"
printf '{"vps":"test","claimed_at":1,"pool":"https://example.invalid","items":[{"id":999,"body_b64":"%s"}]}' \
  "$dummy_body" > "$test_root/v1.json"
bodies_has_device "$test_root/v1.json" \
  && fail "bodies_has_device: fixture v1 malah dikenali punya perangkat"
echo "bukan json" > "$test_root/bad.json"
bodies_has_device "$test_root/bad.json" \
  && fail "bodies_has_device: json invalid malah dikenali punya perangkat"
bodies_has_device "$test_root/tidak-ada.json" \
  && fail "bodies_has_device: file hilang malah dikenali punya perangkat"

# 1) Stok v2 sudah ada dan valid -> tidak klaim lagi, marker pool.
out="$(claim_pool_bodies)"
echo "$out" | grep -q "__WDP_POOL_OK__" \
  || fail "marker pool tidak dicetak saat stok sudah ada"
[ -f "$APP_DIR/bodies.json" ] \
  || fail "bodies.json hilang setelah klaim"

# 2) --no-pool -> dilewati, tanpa marker.
POOL_CLAIM_ENABLED=0
out="$(claim_pool_bodies)"
echo "$out" | grep -q "dilewati" \
  || fail "--no-pool tidak dilewati"
echo "$out" | grep -q "__WDP_POOL_OK__" \
  && fail "--no-pool malah mencetak marker pool"

# 3) Bukan Linux -> dilewati.
POOL_CLAIM_ENABLED=1
IS_LINUX=0
out="$(claim_pool_bodies)"
echo "$out" | grep -q "dilewati" \
  || fail "non-Linux tidak dilewati"

# 4) claim_bodies.php hilang -> install gagal (die, exit 1).
IS_LINUX=1
rm -f "$APP_DIR/claim_bodies.php"
if ( claim_pool_bodies ) >/dev/null 2>&1; then
  fail "claim_bodies.php hilang tapi klaim tidak gagal"
fi

# 5) bodies.json v1 (tanpa perangkat) -> klaim ulang pakai --force.
# Stub claim script (PHP) supaya tidak menyentuh jaringan: catat argumen,
# lalu tulis bodies.json v2 dummy.
cp "$repo_root/claim_bodies.php" "$APP_DIR/claim_bodies.php"
export CLAIM_STUB_LOG="$test_root/claim_args.log"
cat > "$APP_DIR/claim_bodies.php" <<'PHPSTUB'
<?php
$log = getenv('CLAIM_STUB_LOG');
if ($log !== false && $log !== '') {
  file_put_contents($log, implode(' ', array_slice($argv, 1)) . "\n", FILE_APPEND);
}
$out = null;
foreach ($argv as $i => $a) {
  if ($a === '--out' && isset($argv[$i + 1])) { $out = $argv[$i + 1]; break; }
}
if ($out !== null) {
  $body = base64_encode(random_bytes(1500));
  $json = '{"vps":"test","claimed_at":1,"pool":"https://example.invalid","device":{"model":"Sony G8342","headers":{"user-agent":"Mozilla/5.0 stub"}},"items":[{"id":1000,"body_b64":"' . $body . '"}]}';
  file_put_contents($out, $json);
  chmod($out, 0600);
}
exit(0);
PHPSTUB
cp "$test_root/v1.json" "$APP_DIR/bodies.json"
out="$(claim_pool_bodies)"
echo "$out" | grep -q "__WDP_POOL_OK__" \
  || fail "marker pool tidak dicetak saat klaim ulang v1"
grep -q -- "--force" "$CLAIM_STUB_LOG" \
  || fail "klaim ulang v1 tidak memakai --force (argumen: $(cat "$CLAIM_STUB_LOG" 2>/dev/null))"
bodies_has_device "$APP_DIR/bodies.json" \
  || fail "bodies.json setelah klaim ulang tidak punya perangkat"

printf 'install pool claim: ok\n'
