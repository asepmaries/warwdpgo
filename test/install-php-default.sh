#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
installer="$repo_root/install.sh"

source "$installer"

fail() {
  printf 'php default test failed: %s\n' "$*" >&2
  exit 1
}

test_root="$(mktemp -d)"
trap 'rm -rf "$test_root"' EXIT
extract_dir="$test_root/extract"
mkdir -p "$extract_dir"

original_install_files="$(declare -f install_files_from_extract)"
original_download_package="$(declare -f download_package)"
trace=""

linux_prepare_clock() {
  [ "${1:-0}" = "1" ] || fail "PHP Linux wajib meminta dependency PHP"
  trace="$trace clock"
}
download_package() {
  EXTRACT_DIR="$extract_dir"
  trace="$trace download"
}
linux_wait_clock_health() { trace="$trace wait"; }
install_files_from_extract() {
  [ "${2:-0}" = "0" ] || fail "Default PHP tidak boleh menyalin source Go"
  trace="$trace files"
}
cleanup_download() { trace="$trace cleanup"; }
verify_php_setup() { trace="$trace verify"; }

go_tripwire() {
  fail "Jalur PHP memanggil persiapan Golang: $*"
}
linux_install_golang() { go_tripwire linux_install_golang; }
linux_install_golang_official() { go_tripwire linux_install_golang_official; }
setup_go_mod() { go_tripwire setup_go_mod; }
prebuild_war_binary() { go_tripwire prebuild_war_binary; }
try_install_prebuilt_war_binary() { go_tripwire try_install_prebuilt_war_binary; }
install_linux_war_binary() { go_tripwire install_linux_war_binary; }
verify_linux_setup() { go_tripwire verify_linux_setup; }

IS_LINUX=1
IS_TERMUX=0
PLATFORM="linux"
APP_DIR="$test_root/app"
BINARY_MODE="source"
ALLOW_SOURCE_FALLBACK=1
mkdir -p "$APP_DIR"
printf 'legacy-go-binary-must-stay-untouched\n' > "$APP_DIR/war"
legacy_hash="$(sha256sum "$APP_DIR/war" | awk '{print $1}')"

do_install_linux >/dev/null
[ "$trace" = " clock download wait files cleanup verify" ] \
  || fail "urutan full install salah:$trace"
[ "$(sha256sum "$APP_DIR/war" | awk '{print $1}')" = "$legacy_hash" ] \
  || fail "binary Go lama berubah saat full install PHP"

trace=""
do_update_files >/dev/null
[ "$trace" = " clock download wait files cleanup verify" ] \
  || fail "urutan update salah:$trace"
[ "$(sha256sum "$APP_DIR/war" | awk '{print $1}')" = "$legacy_hash" ] \
  || fail "binary Go lama berubah saat update PHP"

# Pulihkan implementasi asli untuk menguji profil file secara nyata.
eval "$original_install_files"
eval "$original_download_package"
for file in war.php install.sh war.go go.mod go.sum; do
  printf 'fixture:%s\n' "$file" > "$extract_dir/$file"
done
for file in "${CONFIG_FILES[@]}"; do
  printf 'config:%s\n' "$file" > "$extract_dir/$file"
done

APP_DIR="$test_root/php-app"
install_files_from_extract "$extract_dir" >/dev/null
[ -f "$APP_DIR/war.php" ] || fail "war.php tidak disalin"
[ -f "$APP_DIR/install.sh" ] || fail "install.sh tidak disalin"
[ ! -e "$APP_DIR/war.go" ] || fail "war.go ikut tersalin di profil PHP"
[ ! -e "$APP_DIR/go.mod" ] || fail "go.mod ikut tersalin di profil PHP"
[ ! -e "$APP_DIR/go.sum" ] || fail "go.sum ikut tersalin di profil PHP"

APP_DIR="$test_root/go-app"
install_files_from_extract "$extract_dir" 1 >/dev/null
[ -f "$APP_DIR/war.go" ] || fail "profil Go tidak menyalin war.go"
[ -f "$APP_DIR/go.mod" ] || fail "profil Go tidak menyalin go.mod"
[ -f "$APP_DIR/go.sum" ] || fail "profil Go tidak menyalin go.sum"

# Arsip PHP-only sah untuk default/update, tetapi ditolak profil Go.
php_archive_root="$test_root/php-archive/warwdpgo"
php_archive="$test_root/php-only.tar.gz"
mkdir -p "$php_archive_root"
cp "$repo_root/war.php" "$php_archive_root/war.php"
cp "$repo_root/install.sh" "$php_archive_root/install.sh"
tar -czf "$php_archive" -C "$test_root/php-archive" warwdpgo
ARCHIVE_URL="https://example.invalid/php-only.tar.gz"
ARCHIVE_SHA256="$(sha256sum "$php_archive" | awk '{print $1}')"
curl_download() { cp "$php_archive" "$2"; }

MODE="auto"
download_package >/dev/null
[ -f "$EXTRACT_DIR/war.php" ] || fail "arsip PHP-only tidak diterima default"
[ ! -e "$EXTRACT_DIR/war.go" ] || fail "fixture PHP-only tiba-tiba berisi Go"
cleanup_download

if (
  MODE="go-only"
  download_package
) >/dev/null 2>&1; then
  fail "profil Go menerima arsip tanpa war.go/go.mod/go.sum"
fi

MODE="auto"
BINARY_MODE="release"
parse_args --build-from-source
[ "$MODE" = "go-only" ] || fail "--build-from-source bukan alias --go-only"
[ "$BINARY_MODE" = "source" ] || fail "--build-from-source tidak memilih source"

cp "$repo_root/war.php" "$test_root/runtime-war.php"
runtime_output="$(php "$test_root/runtime-war.php" --check-runtime)"
[ "$runtime_output" = "__WDP_PHP_RUNTIME_OK__" ] \
  || fail "self-check war.php tidak mengeluarkan marker"
[ ! -e "$test_root/loghasil.txt" ] \
  || fail "self-check war.php membuat log/transaksi"

printf 'php-only default/update routing: ok\n'
