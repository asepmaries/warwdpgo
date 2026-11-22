#!/usr/bin/env bash
# Uji fase klaim stok body pool di install.sh (claim_pool_bodies).
# Tidak menyentuh jaringan: stok fixture memakai body dummy (bukan stok
# pool asli), dan claim_bodies.php melihat stok valid lalu tidak klaim lagi.
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
WDP1_DIR="$APP_DIR/wdp1"
mkdir -p "$WDP1_DIR"
cp "$repo_root/claim_bodies.php" "$APP_DIR/claim_bodies.php"

# Fixture bodies.json valid: 1 body dummy 1500 byte, id 999.
dummy_body="$(php -r 'echo base64_encode(random_bytes(1500));')"
printf '{"vps":"test","claimed_at":1,"pool":"https://example.invalid","items":[{"id":999,"body_b64":"%s"}]}' \
  "$dummy_body" > "$APP_DIR/bodies.json"
chmod 0600 "$APP_DIR/bodies.json"

IS_LINUX=1
POOL_CLAIM_ENABLED=1
POOL_CLAIM_COUNT=10

# 1) Stok sudah ada dan valid -> tidak klaim lagi, salin ke wdp1, marker pool.
out="$(claim_pool_bodies)"
echo "$out" | grep -q "__WDP_POOL_OK__" \
  || fail "marker pool tidak dicetak saat stok sudah ada"
[ -f "$WDP1_DIR/bodies.json" ] \
  || fail "bodies.json tidak disalin ke wdp1"
cmp -s "$APP_DIR/bodies.json" "$WDP1_DIR/bodies.json" \
  || fail "salinan bodies.json di wdp1 berbeda"

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

printf 'install pool claim: ok\n'
