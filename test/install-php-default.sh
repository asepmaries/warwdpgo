#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
installer="$repo_root/install.sh"

source "$installer"

fail() {
  printf 'php default test failed: %s\n' "$*" >&2
  exit 1
}

case " ${PHP_CORE_FILES[*]} " in
  *" dm.php "*) ;;
  *) fail "dm.php belum terdaftar sebagai file inti" ;;
esac
case " ${CONFIG_FILES[*]} " in
  *" user_server_dm.txt "*) ;;
  *) fail "user_server_dm.txt belum terdaftar sebagai config" ;;
esac

test_root="$(mktemp -d)"
trap 'rm -rf "$test_root"' EXIT
extract_dir="$test_root/extract"
mkdir -p "$extract_dir"

original_install_files="$(declare -f install_files_from_extract)"
original_download_package="$(declare -f download_package)"
original_verify_php_setup="$(declare -f verify_php_setup)"
trace=""

linux_prepare_clock() {
  [ "${1:-0}" = "1" ] || fail "PHP Linux wajib meminta dependency PHP"
  trace="$trace clock"
}
download_package() {
  EXTRACT_DIR="$extract_dir"
  trace="$trace download"
}
linux_wait_clock_health() {
  CLOCK_GATE_PASSED=1
  trace="$trace wait"
}
install_files_from_extract() {
  case "${2:-$APP_DIR}" in
    "$APP_DIR") trace="$trace files-main" ;;
    "$WDP1_DIR") trace="$trace files-wdp1" ;;
    *) fail "Target copy tidak dikenal: ${2:-}" ;;
  esac
}
cleanup_download() { trace="$trace cleanup"; }
verify_php_setup() { trace="$trace verify"; }

IS_LINUX=1
IS_TERMUX=0
IS_MACOS=0
PLATFORM="linux"
APP_DIR="$test_root/app"
WDP1_DIR="$APP_DIR/wdp1"

install_output="$test_root/install-output.txt"
do_install_linux > "$install_output"
[ "$trace" = " clock download wait files-main files-wdp1 cleanup verify" ] \
  || fail "urutan full install salah:$trace"
[ "$(tail -n 1 "$install_output")" = "__WDP_CLOCK_HEALTHY__" ] \
  || fail "marker clock bukan output terakhir full install"

trace=""
update_output="$test_root/update-output.txt"
do_update_files > "$update_output"
[ "$trace" = " clock download wait files-main files-wdp1 cleanup verify" ] \
  || fail "urutan update salah:$trace"
[ "$(tail -n 1 "$update_output")" = "__WDP_CLOCK_HEALTHY__" ] \
  || fail "marker clock bukan output terakhir update"

trace=""
IS_LINUX=0
IS_MACOS=1
PLATFORM="macos"
APP_DIR="$test_root/macos-home"
WDP1_DIR="$APP_DIR/wdp1"
macos_prepare_php() { trace="$trace mac"; }
mac_output="$test_root/macos-output.txt"
do_install_macos > "$mac_output"
[ "$trace" = " mac download files-main files-wdp1 cleanup verify" ] \
  || fail "urutan install macOS salah:$trace"
[ "$(tail -n 1 "$mac_output")" = "__WDP_INSTALL_OK__" ] \
  || fail "marker install bukan output terakhir macOS"

eval "$original_install_files"
eval "$original_download_package"
eval "$original_verify_php_setup"

for file in "${PHP_CORE_FILES[@]}"; do
  printf 'fixture:%s\n' "$file" > "$extract_dir/$file"
done
for file in "${CONFIG_FILES[@]}"; do
  printf 'config:%s\n' "$file" > "$extract_dir/$file"
done
printf 'jangan disalin\n' > "$extract_dir/unexpected.bin"

APP_DIR="$test_root/php-main"
WDP1_DIR="$APP_DIR/wdp1"
install_files_from_extract "$extract_dir" "$APP_DIR" >/dev/null
install_files_from_extract "$extract_dir" "$WDP1_DIR" >/dev/null
for target in "$APP_DIR" "$WDP1_DIR"; do
  for file in "${PHP_CORE_FILES[@]}" "${CONFIG_FILES[@]}"; do
    [ -f "$target/$file" ] \
      || fail "file paket hilang dari $target: $file"
  done
  [ ! -e "$target/unexpected.bin" ] \
    || fail "file di luar allowlist ikut terpasang"
done

printf 'config-lokal\n' > "$APP_DIR/waktu.txt"
install_files_from_extract "$extract_dir" "$APP_DIR" >/dev/null
[ "$(cat "$APP_DIR/waktu.txt")" = "config-lokal" ] \
  || fail "config lokal tertimpa tanpa --force"
FORCE_OVERWRITE=1
install_files_from_extract "$extract_dir" "$APP_DIR" >/dev/null
[ "$(cat "$APP_DIR/waktu.txt")" = "config:waktu.txt" ] \
  || fail "--force tidak mengganti config"
FORCE_OVERWRITE=0

IS_TERMUX=0
HOME="/root"
[ "$(default_app_dir)" = "/root" ] \
  || fail "direktori utama root salah"
HOME="/home/ubuntu"
[ "$(default_app_dir)" = "/home/ubuntu" ] \
  || fail "direktori utama user salah"
IS_TERMUX=1
[ "$(default_app_dir)" = "/sdcard/wdp" ] \
  || fail "direktori utama Termux salah"

archive_root="$test_root/archive/warwdpgo-main"
fixture_archive="$test_root/github-source.tar.gz"
mkdir -p "$archive_root"
for file in "${PHP_CORE_FILES[@]}"; do
  cp "$repo_root/$file" "$archive_root/$file"
done
for file in "${CONFIG_FILES[@]}"; do
  cp "$repo_root/$file" "$archive_root/$file"
done
tar -czf "$fixture_archive" -C "$test_root/archive" warwdpgo-main

downloaded_url=""
curl_download() {
  downloaded_url="$1"
  cp "$fixture_archive" "$2"
}
GITHUB_REPO="example/project"
GITHUB_REF="main"
download_package >/dev/null
[ "$downloaded_url" = \
  "https://github.com/example/project/archive/main.tar.gz" ] \
  || fail "URL GitHub salah: $downloaded_url"
[ -f "$EXTRACT_DIR/war.php" ] \
  || fail "arsip GitHub tidak diekstrak"
[ -f "$EXTRACT_DIR/dm.php" ] \
  || fail "dm.php tidak diekstrak dari arsip GitHub"
[ -f "$EXTRACT_DIR/user_server_dm.txt" ] \
  || fail "user_server_dm.txt tidak diekstrak dari arsip GitHub"
cleanup_download

MODE="auto"
parse_args --github-ref stable
[ "$GITHUB_REF" = "stable" ] \
  || fail "--github-ref tidak diterapkan"

printf 'install PHP default: ok\n'
