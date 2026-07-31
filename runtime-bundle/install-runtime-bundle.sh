#!/usr/bin/env bash
set -Eeuo pipefail

log() { printf '\n==> %s\n' "$*"; }
ok() { printf '    [OK] %s\n' "$*"; }
die() { printf '\n[ERROR] %s\n' "$*" >&2; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MANIFEST_FILE="$SCRIPT_DIR/manifest.env"
REPO_DIR="$SCRIPT_DIR/repo"
PACKAGE_FILE="$SCRIPT_DIR/packages.txt"
CHRONY_CONFIG_FILE="$SCRIPT_DIR/chrony-wdp.conf"
READY_DIR="/var/lib/warwdpgo"

[ "$(id -u)" -eq 0 ] || die "Installer runtime wajib dijalankan sebagai root"
[ -f "$MANIFEST_FILE" ] || die "manifest.env tidak ditemukan"
[ -f "$PACKAGE_FILE" ] || die "packages.txt tidak ditemukan"
[ -f "$CHRONY_CONFIG_FILE" ] || die "chrony-wdp.conf tidak ditemukan"
[ -d "$REPO_DIR" ] || die "Repository paket lokal tidak ditemukan"

# File ini hanya berisi assignment yang dibuat builder sendiri.
# shellcheck disable=SC1090
source "$MANIFEST_FILE"

[ "${BUNDLE_OS_ID:-}" = "ubuntu" ] || die "Bundle OS tidak valid"
[ "${BUNDLE_OS_VERSION:-}" = "24.04" ] || die "Bundle bukan Ubuntu 24.04"
[ -n "${BUNDLE_ARCH:-}" ] || die "Arsitektur bundle tidak tersedia"
[ -n "${BUNDLE_VERSION:-}" ] || die "Versi bundle tidak tersedia"

# shellcheck disable=SC1091
source /etc/os-release
[ "${ID:-}" = "ubuntu" ] || die "Target bukan Ubuntu"
[ "${VERSION_ID:-}" = "24.04" ] || die "Target wajib Ubuntu 24.04"
[ "$(dpkg --print-architecture)" = "$BUNDLE_ARCH" ] \
  || die "Arsitektur target berbeda dari bundle $BUNDLE_ARCH"

log "Verifikasi checksum internal bundle"
(cd "$SCRIPT_DIR" && sha256sum -c SHA256SUMS)
ok "Checksum internal valid"

mapfile -t runtime_packages < <(
  sed -e 's/[[:space:]]*#.*$//' -e '/^[[:space:]]*$/d' "$PACKAGE_FILE"
)
[ "${#runtime_packages[@]}" -gt 0 ] || die "Daftar paket runtime kosong"

# apt memakai user _apt saat membaca repository. Bundle hanya berisi artefak
# publik sehingga aman dibuat readable selama instalasi.
chmod -R a+rX "$SCRIPT_DIR"

apt_root="$(mktemp -d)"
trap 'rm -rf -- "$apt_root"' EXIT
mkdir -p "$apt_root/lists/partial" "$apt_root/cache/archives/partial"
chmod a+rx "$apt_root" "$apt_root/lists" "$apt_root/lists/partial" \
  "$apt_root/cache" "$apt_root/cache/archives" \
  "$apt_root/cache/archives/partial"
printf 'deb [trusted=yes] file:%s ./\n' "$REPO_DIR" > "$apt_root/sources.list"
chmod a+r "$apt_root/sources.list"

apt_opts=(
  -o "Dir::Etc::sourcelist=$apt_root/sources.list"
  -o "Dir::Etc::sourceparts=-"
  -o "Dir::State::lists=$apt_root/lists"
  -o "Dir::Cache::archives=$apt_root/cache/archives"
  -o "APT::Get::List-Cleanup=0"
  -o "Acquire::Languages=none"
  -o "DPkg::Lock::Timeout=30"
  -o "Dpkg::Use-Pty=0"
)

log "Baca index repository lokal (tanpa mirror internet)"
apt-get "${apt_opts[@]}" update

log "Pasang runtime dari paket lokal"
env DEBIAN_FRONTEND=noninteractive \
  apt-get "${apt_opts[@]}" install -y --no-install-recommends \
    "${runtime_packages[@]}"

log "Konfigurasi runtime dan clock"
timedatectl set-timezone Asia/Jakarta
systemctl disable --now systemd-timesyncd.service >/dev/null 2>&1 || true
install -D -m 0644 "$CHRONY_CONFIG_FILE" \
  /etc/chrony/conf.d/99-wdp-runtime.conf

if systemctl cat chrony.service >/dev/null 2>&1; then
  systemctl enable chrony.service
  systemctl restart chrony.service
elif systemctl cat chronyd.service >/dev/null 2>&1; then
  systemctl enable chronyd.service
  systemctl restart chronyd.service
else
  die "Unit chrony/chronyd tidak ditemukan"
fi

chronyc -a makestep 0.1 3 >/dev/null 2>&1 || true
chronyc -a online >/dev/null 2>&1 || true

php -r '
  if (PHP_SAPI !== "cli" || PHP_VERSION_ID < 70400) exit(1);
  if (!extension_loaded("curl") || !extension_loaded("json")) exit(2);
  $curl = curl_version();
  if (empty($curl["ssl_version"])) exit(3);
' || die "Runtime PHP tidak memenuhi kebutuhan aplikasi"

clock_burst_rounds="${CLOCK_BURST_ROUNDS:-3}"
clock_round_tries="${CLOCK_ROUND_TRIES:-15}"
clock_wait_interval="${CLOCK_WAIT_INTERVAL_SEC:-1}"
case "$clock_burst_rounds:$clock_round_tries:$clock_wait_interval" in
  *[!0-9:]*|0:*|*:0:*|*:0) die "Config clock wait wajib bilangan bulat positif" ;;
esac

clock_synced=0
for ((round = 1; round <= clock_burst_rounds; round++)); do
  log "Clock bootstrap round $round/$clock_burst_rounds"
  chronyc -a burst 8/8 >/dev/null 2>&1 || true
  if chronyc -n waitsync "$clock_round_tries" 0.005 100 \
    "$clock_wait_interval"; then
    clock_synced=1
    break
  fi
done

if [ "$clock_synced" -ne 1 ]; then
  chronyc -n tracking >&2 || true
  chronyc -n sources -v >&2 || true
  die "Clock belum sehat dalam batas waktu"
fi

mkdir -p "$READY_DIR"
printf 'runtime-ubuntu24-%s-%s\n' "$BUNDLE_ARCH" "$BUNDLE_VERSION" \
  > "$READY_DIR/runtime-ready"

ok "Runtime siap: $(php -r 'printf("PHP %s", PHP_VERSION);')"
printf '%s\n' "__WDP_RUNTIME_BUNDLE_READY__"
