#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
installer="$repo_root/install.sh"

source "$installer"

fail() {
  printf 'provider NTP test failed: %s\n' "$*" >&2
  exit 1
}

test_root="$(mktemp -d)"
trap 'rm -rf -- "$test_root"' EXIT
fake_bin="$test_root/bin"
mkdir -p "$fake_bin"

cat > "$fake_bin/cloud-id" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' oracle
EOF
chmod +x "$fake_bin/cloud-id"

CHRONY_PROVIDER_CONFIG_FILE="$test_root/98-wdp-provider.conf"
run_root() { "$@"; }

PATH="$fake_bin:$PATH" linux_is_oci \
  || fail "cloud-id=oracle tidak terdeteksi"
PATH="$fake_bin:$PATH" linux_configure_provider_ntp >/dev/null
[ "$CHRONY_RESTART_REQUIRED" -eq 1 ] \
  || fail "config baru tidak meminta restart"
grep -Fxq 'server 169.254.169.254 iburst prefer' \
  "$CHRONY_PROVIDER_CONFIG_FILE" \
  || fail "OCI local NTP tidak ditulis"

CHRONY_RESTART_REQUIRED=0
PATH="$fake_bin:$PATH" linux_configure_provider_ntp >/dev/null
[ "$CHRONY_RESTART_REQUIRED" -eq 0 ] \
  || fail "config identik masih meminta restart"

rm -f -- "$CHRONY_PROVIDER_CONFIG_FILE"
ln -s "$test_root/untrusted-target" "$CHRONY_PROVIDER_CONFIG_FILE"
if (PATH="$fake_bin:$PATH" linux_configure_provider_ntp >/dev/null 2>&1); then
  fail "symlink config provider tidak ditolak"
fi

printf 'install provider NTP: ok\n'
