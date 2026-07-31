#!/usr/bin/env bash
set -Eeuo pipefail

die() { printf '\n[ERROR] %s\n' "$*" >&2; exit 1; }

BUNDLE_FILE=""
BUNDLE_SHA256=""
BUNDLE_URL=""
CACHE_ROOT="/var/cache/warwdpgo"

while [ $# -gt 0 ]; do
  case "$1" in
    --bundle-file)
      [ $# -ge 2 ] || die "--bundle-file membutuhkan path"
      shift; BUNDLE_FILE="$1"
      ;;
    --bundle-url)
      [ $# -ge 2 ] || die "--bundle-url membutuhkan URL"
      shift; BUNDLE_URL="$1"
      ;;
    --sha256)
      [ $# -ge 2 ] || die "--sha256 membutuhkan checksum"
      shift; BUNDLE_SHA256="$1"
      ;;
    *) die "Argumen tidak dikenal: $1" ;;
  esac
  shift
done

[ "$(id -u)" -eq 0 ] || die "Bootstrap wajib dijalankan sebagai root"
printf '%s\n' "$BUNDLE_SHA256" | grep -Eq '^[[:xdigit:]]{64}$' \
  || die "Checksum SHA-256 wajib diberikan"

mkdir -p "$CACHE_ROOT"
tmp_dir="$(mktemp -d "$CACHE_ROOT/download.XXXXXX")"
trap 'rm -rf -- "$tmp_dir"' EXIT
archive="$tmp_dir/runtime.tar.gz"

if [ -n "$BUNDLE_FILE" ]; then
  [ -f "$BUNDLE_FILE" ] || die "Bundle lokal tidak ditemukan"
  cp "$BUNDLE_FILE" "$archive"
elif [ -n "$BUNDLE_URL" ]; then
  case "$BUNDLE_URL" in https://*) ;; *) die "Bundle URL wajib HTTPS" ;; esac
  curl --fail --location --silent --show-error \
    --retry 2 --retry-delay 1 --connect-timeout 5 --max-time 45 \
    "$BUNDLE_URL" -o "$archive"
else
  die "Gunakan --bundle-file atau --bundle-url"
fi

actual_sha="$(sha256sum "$archive" | awk '{print $1}')"
[ "$actual_sha" = "$BUNDLE_SHA256" ] || die "Checksum bundle berbeda"

archive_listing="$(tar -tzf "$archive")" || die "Bundle bukan tar.gz valid"
[ -n "$archive_listing" ] || die "Bundle kosong"
top_dir="${archive_listing%%/*}"
printf '%s\n' "$top_dir" | grep -Eq '^runtime-ubuntu24-(amd64|arm64)-[A-Za-z0-9._-]+$' \
  || die "Root directory bundle tidak valid"
while IFS= read -r entry; do
  case "$entry" in
    /*|../*|*/../*|*/..)
      die "Path tidak aman di dalam bundle: $entry"
      ;;
    "$top_dir"|"$top_dir/"|"$top_dir/"*) ;;
    *) die "Bundle memiliki lebih dari satu root directory: $entry" ;;
  esac
done <<< "$archive_listing"

install_root="$CACHE_ROOT/$top_dir"
rm -rf -- "$install_root"
tar -xzf "$archive" -C "$CACHE_ROOT"
[ -x "$install_root/install-runtime-bundle.sh" ] \
  || die "Installer internal tidak tersedia"

exec "$install_root/install-runtime-bundle.sh"
