#!/usr/bin/env bash
set -Eeuo pipefail

log() { printf '\n==> %s\n' "$*"; }
ok() { printf '    [OK] %s\n' "$*"; }
die() { printf '\n[ERROR] %s\n' "$*" >&2; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE_FILE="$SCRIPT_DIR/packages-ubuntu24.txt"
CHRONY_CONFIG_FILE="$SCRIPT_DIR/chrony-wdp.conf"
BUNDLE_VERSION="${1:-}"
OUTPUT_DIR="${OUTPUT_DIR:-$SCRIPT_DIR/dist}"

case "$BUNDLE_VERSION" in
  ''|*[!A-Za-z0-9._-]*) die "Versi bundle tidak valid: $BUNDLE_VERSION" ;;
esac
[ "$BUNDLE_VERSION" != "v3" ] \
  || die "v3 sudah immutable; gunakan versi baru (misalnya v4)"

[ "$(id -u)" -eq 0 ] || die "Builder wajib dijalankan sebagai root"
[ -f "$PACKAGE_FILE" ] || die "Daftar paket tidak ditemukan: $PACKAGE_FILE"
[ -f "$CHRONY_CONFIG_FILE" ] \
  || die "Config chrony tidak ditemukan: $CHRONY_CONFIG_FILE"

# shellcheck disable=SC1091
source /etc/os-release
[ "${ID:-}" = "ubuntu" ] || die "Builder wajib Ubuntu"
[ "${VERSION_ID:-}" = "24.04" ] || die "Builder wajib Ubuntu 24.04"

ARCH="$(dpkg --print-architecture)"
case "$ARCH" in
  amd64|arm64) ;;
  *) die "Arsitektur builder belum didukung: $ARCH" ;;
esac

mapfile -t runtime_packages < <(
  sed -e 's/[[:space:]]*#.*$//' -e '/^[[:space:]]*$/d' "$PACKAGE_FILE"
)
[ "${#runtime_packages[@]}" -gt 0 ] || die "Daftar paket runtime kosong"

work_dir="$(mktemp -d)"
trap 'rm -rf -- "$work_dir"' EXIT
bundle_name="runtime-ubuntu24-${ARCH}-${BUNDLE_VERSION}"
bundle_root="$work_dir/$bundle_name"
repo_dir="$bundle_root/repo"
mkdir -p "$repo_dir" "$OUTPUT_DIR"
chmod 0755 "$work_dir" "$bundle_root" "$repo_dir"
if id _apt >/dev/null 2>&1; then
  chown _apt:root "$repo_dir"
fi

apt_opts=(
  -o Acquire::ForceIPv4=true
  -o Acquire::Retries=2
  -o Acquire::http::Timeout=15
  -o Acquire::https::Timeout=20
  -o DPkg::Lock::Timeout=60
  -o Dpkg::Use-Pty=0
)

log "Siapkan alat builder"
apt-get "${apt_opts[@]}" update
env DEBIAN_FRONTEND=noninteractive \
  apt-get "${apt_opts[@]}" install -y --no-install-recommends \
    apt-rdepends dpkg-dev ca-certificates

log "Hitung dependency closure runtime"
closure_file="$work_dir/package-closure.txt"
apt-rdepends "${runtime_packages[@]}" 2>/dev/null \
  | sed -n '/^[^[:space:]]/p' \
  | sed 's/:any$//' \
  | grep -E '^[a-z0-9][a-z0-9+.-]*(:[a-z0-9]+)?$' \
  | sort -u > "$closure_file"
printf '%s\n' "${runtime_packages[@]}" >> "$closure_file"
sort -u -o "$closure_file" "$closure_file"

downloaded=0
skipped=0
while IFS= read -r package; do
  [ -n "$package" ] || continue
  candidate="$(apt-cache policy "$package" 2>/dev/null \
    | awk '/Candidate:/ && !candidate {candidate=$2} END {print candidate}')"
  if [ -z "$candidate" ] || [ "$candidate" = "(none)" ]; then
    printf '    [SKIP] tidak ada kandidat paket nyata: %s\n' "$package"
    skipped=$((skipped + 1))
    continue
  fi
  log "Download $package=$candidate"
  (cd "$repo_dir" && apt-get "${apt_opts[@]}" download "$package=$candidate")
  downloaded=$((downloaded + 1))
done < "$closure_file"

[ "$downloaded" -gt 0 ] || die "Tidak ada paket yang berhasil didownload"

log "Buat index repository lokal"
(cd "$repo_dir" && dpkg-scanpackages --multiversion . /dev/null > Packages)
gzip -9c "$repo_dir/Packages" > "$repo_dir/Packages.gz"

cp "$PACKAGE_FILE" "$bundle_root/packages.txt"
cp "$SCRIPT_DIR/install-runtime-bundle.sh" "$bundle_root/install-runtime-bundle.sh"
cp "$CHRONY_CONFIG_FILE" "$bundle_root/chrony-wdp.conf"
chmod +x "$bundle_root/install-runtime-bundle.sh"

cat > "$bundle_root/manifest.env" <<EOF
BUNDLE_FORMAT=1
BUNDLE_VERSION=$BUNDLE_VERSION
BUNDLE_OS_ID=ubuntu
BUNDLE_OS_VERSION=24.04
BUNDLE_ARCH=$ARCH
BUNDLE_BUILT_AT_UTC=$(date -u +%Y-%m-%dT%H:%M:%SZ)
BUNDLE_PACKAGE_COUNT=$downloaded
BUNDLE_SKIPPED_VIRTUAL_COUNT=$skipped
EOF

while IFS= read -r -d '' deb_file; do
  dpkg-deb -f "$deb_file" \
    '${Package}\t${Version}\t${Architecture}\n'
done < <(find "$repo_dir" -maxdepth 1 -type f -name '*.deb' -print0 \
  | sort -z) \
  | sort -u > "$bundle_root/package-versions.txt"

(cd "$bundle_root" && \
  find . -type f ! -name SHA256SUMS -print0 \
    | sort -z \
    | xargs -0 sha256sum > SHA256SUMS)

archive="$OUTPUT_DIR/$bundle_name.tar.gz"
log "Buat archive bundle"
tar --owner=0 --group=0 --numeric-owner -czf "$archive" \
  -C "$work_dir" "$bundle_name"
(cd "$OUTPUT_DIR" && sha256sum "$(basename "$archive")" \
  > "$(basename "$archive").sha256")

ok "Bundle selesai: $archive"
du -h "$archive"
printf '%s\n' "__WDP_RUNTIME_BUNDLE_BUILD_READY__"
