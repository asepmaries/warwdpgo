#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
installer="$repo_root/install.sh"
bundle_dir="$repo_root/runtime-bundle"

source "$installer"

fail() {
  printf 'runtime bundle test failed: %s\n' "$*" >&2
  exit 1
}

[ "$RUNTIME_BUNDLE_VERSION" = "v3" ] || fail "versi production bukan v3"
[ "$RUNTIME_BUNDLE_RELEASE_TAG" = "runtime-ubuntu24-v3.0.0" ] \
  || fail "tag release salah"
[ "$RUNTIME_BUNDLE_ARCHIVE" = "runtime-ubuntu24-amd64-v3.tar.gz" ] \
  || fail "nama archive salah"
[ "$RUNTIME_BUNDLE_SIZE_BYTES" = "36132390" ] \
  || fail "ukuran pinned salah"
[ "$RUNTIME_BUNDLE_SHA256" = \
  "1bf80c485efe1b929936bff4c3ae359c2bc45119b18788d5b8a3cbe015d714b4" ] \
  || fail "SHA-256 pinned salah"
[ "$RUNTIME_BUNDLE_URL" = \
  "https://github.com/asepmaries/warwdpgo/releases/download/runtime-ubuntu24-v3.0.0/runtime-ubuntu24-amd64-v3.tar.gz" ] \
  || fail "URL release salah: $RUNTIME_BUNDLE_URL"
[ "$(runtime_bundle_expected_marker)" = "runtime-ubuntu24-amd64-v3" ] \
  || fail "marker production salah"

runtime_archive_entry_is_unsafe "../escape" \
  || fail "parent traversal tidak ditolak"
runtime_archive_entry_is_unsafe "/absolute" \
  || fail "absolute path tidak ditolak"
if runtime_archive_entry_is_unsafe \
    "runtime-ubuntu24-amd64-v3/repo/package.deb"; then
  fail "path bundle valid ikut ditolak"
fi

test_root="$(mktemp -d)"
trap 'rm -rf -- "$test_root"' EXIT
fake_bin="$test_root/bin"
mkdir -p "$fake_bin"
for command_name in curl tar sha256sum chronyc; do
  printf '#!/usr/bin/env bash\nexit 0\n' > "$fake_bin/$command_name"
  chmod +x "$fake_bin/$command_name"
done

RUNTIME_BUNDLE_READY_FILE="$test_root/runtime-ready"
RUNTIME_BUNDLE_LEGACY_READY_FILE="$test_root/legacy-ready"
php_runtime_ready() { return 0; }
run_root() { "$@"; }

printf '%s\n' v3 > "$RUNTIME_BUNDLE_LEGACY_READY_FILE"
PATH="$fake_bin:$PATH" linux_install_runtime_bundle >/dev/null
[ "$(cat "$RUNTIME_BUNDLE_READY_FILE")" = "runtime-ubuntu24-amd64-v3" ] \
  || fail "legacy v3 tidak diadopsi ke marker production"
PATH="$fake_bin:$PATH" runtime_bundle_marker_ready \
  || fail "fast path marker sehat tidak terdeteksi"

printf '%s\n' runtime-ubuntu24-amd64-v2 > "$RUNTIME_BUNDLE_READY_FILE"
if PATH="$fake_bin:$PATH" runtime_bundle_marker_ready; then
  fail "marker versi lama diterima"
fi

for script in \
  "$bundle_dir/bootstrap-runtime-bundle.sh" \
  "$bundle_dir/build-ubuntu24-runtime.sh" \
  "$bundle_dir/install-runtime-bundle.sh"; do
  bash -n "$script"
done

if output="$(bash "$bundle_dir/build-ubuntu24-runtime.sh" v3 2>&1)"; then
  fail "builder mengizinkan overwrite v3"
fi
printf '%s\n' "$output" | grep -Fq 'v3 sudah immutable' \
  || fail "penolakan rebuild v3 tidak jelas"

artifact="$bundle_dir/dist/$RUNTIME_BUNDLE_ARCHIVE"
if [ -f "$artifact" ]; then
  [ "$(wc -c < "$artifact" | tr -d '[:space:]')" = \
    "$RUNTIME_BUNDLE_SIZE_BYTES" ] || fail "ukuran artifact lokal berubah"
  [ "$(sha256sum "$artifact" | awk '{print $1}')" = \
    "$RUNTIME_BUNDLE_SHA256" ] || fail "checksum artifact lokal berubah"
fi

printf 'install runtime bundle production: ok\n'
