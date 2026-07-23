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
  [ "${2:-0}" = "0" ] || fail "Default PHP tidak boleh memilih profil setup Go"
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
IS_MACOS=0
PLATFORM="linux"
APP_DIR="$test_root/app"
BINARY_MODE="source"
ALLOW_SOURCE_FALLBACK=1
mkdir -p "$APP_DIR"
printf 'legacy-go-binary-must-stay-untouched\n' > "$APP_DIR/war"
legacy_hash="$(sha256sum "$APP_DIR/war" | awk '{print $1}')"

install_output="$test_root/install-output.txt"
do_install_linux >"$install_output"
[ "$trace" = " clock download wait files cleanup verify" ] \
  || fail "urutan full install salah:$trace"
[ "$(tail -n 1 "$install_output")" = "__WDP_CLOCK_HEALTHY__" ] \
  || fail "marker clock bukan output terakhir full install"
[ "$(sha256sum "$APP_DIR/war" | awk '{print $1}')" = "$legacy_hash" ] \
  || fail "binary Go lama berubah saat full install PHP"

trace=""
update_output="$test_root/update-output.txt"
do_update_files >"$update_output"
[ "$trace" = " clock download wait files cleanup verify" ] \
  || fail "urutan update salah:$trace"
[ "$(tail -n 1 "$update_output")" = "__WDP_CLOCK_HEALTHY__" ] \
  || fail "marker clock bukan output terakhir update"
[ "$(sha256sum "$APP_DIR/war" | awk '{print $1}')" = "$legacy_hash" ] \
  || fail "binary Go lama berubah saat update PHP"

# macOS memakai HOME/wdp1, tanpa APT/Chrony/Golang, dan tetap selesai normal.
trace=""
IS_LINUX=0
IS_MACOS=1
PLATFORM="macos"
APP_DIR="$test_root/macos-home/wdp1"
macos_prepare_php() { trace="$trace mac"; }
mac_output="$test_root/macos-output.txt"
do_install_macos >"$mac_output"
[ "$trace" = " mac download files cleanup verify" ] \
  || fail "urutan install macOS salah:$trace"
[ "$(tail -n 1 "$mac_output")" = "__WDP_INSTALL_OK__" ] \
  || fail "marker install bukan output terakhir macOS"
if grep -Fq "__WDP_CLOCK_HEALTHY__" "$mac_output"; then
  fail "macOS mengklaim Chrony sehat tanpa clock gate Linux"
fi

# Pulihkan implementasi asli untuk menguji profil file secara nyata.
eval "$original_install_files"
eval "$original_download_package"
eval "$original_verify_php_setup"
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
[ -f "$APP_DIR/war.go" ] || fail "war.go dari paket tidak disalin ke wdp1"
[ -f "$APP_DIR/go.mod" ] || fail "go.mod dari paket tidak disalin ke wdp1"
[ -f "$APP_DIR/go.sum" ] || fail "go.sum dari paket tidak disalin ke wdp1"
for file in "${PHP_CORE_FILES[@]}" "${CONFIG_FILES[@]}" war.go "${GO_CORE_FILES[@]}"; do
  [ -f "$APP_DIR/$file" ] || fail "file paket hilang dari wdp1: $file"
done

APP_DIR="$test_root/go-app"
install_files_from_extract "$extract_dir" 1 >/dev/null
[ -f "$APP_DIR/war.go" ] || fail "profil Go tidak menyalin war.go"
[ -f "$APP_DIR/go.mod" ] || fail "profil Go tidak menyalin go.mod"
[ -f "$APP_DIR/go.sum" ] || fail "profil Go tidak menyalin go.sum"

# Default portable: setiap user mendapat folder wdp1 di home masing-masing.
IS_TERMUX=0
MODE="auto"
HOME="/root"
[ "$(default_app_dir)" = "/root/wdp1" ] \
  || fail "default root bukan /root/wdp1"
HOME="/home/ubuntu"
[ "$(default_app_dir)" = "/home/ubuntu/wdp1" ] \
  || fail "default ubuntu bukan /home/ubuntu/wdp1"
IS_MACOS=1
HOME="/Users/codex"
[ "$(default_app_dir)" = "/Users/codex/wdp1" ] \
  || fail "default macOS bukan /Users/codex/wdp1"
IS_TERMUX=1
[ "$(default_app_dir)" = "/sdcard/wdp1" ] \
  || fail "default Termux bukan /sdcard/wdp1"
IS_TERMUX=0
MODE="go-only"
[ "$(default_app_dir)" = "/Users/codex" ] \
  || fail "mode Go eksplisit berubah ikut ke folder wdp1"
MODE="auto"

# Pemilihan Go dari menu dihitung ulang setelah MODE berubah, bukan tertinggal
# di HOME/wdp1 yang ditampilkan saat menu pertama dibuka.
(
  unset APP_DIR
  HOME="/home/menu-user"
  APP_DIR_EXPLICIT=0
  MODE="auto"
  detect_platform() {
    IS_LINUX=1
    IS_TERMUX=0
    IS_MACOS=0
    PLATFORM="linux"
  }
  show_menu() {
    MODE="go-only"
    BINARY_MODE="source"
  }
  do_setup_golang_only() {
    [ "$APP_DIR" = "/home/menu-user" ] \
      || fail "menu Go memakai target PHP wdp1: $APP_DIR"
  }
  main --menu >/dev/null
)

IS_LINUX=0
IS_MACOS=0
PLATFORM="unknown"
uname() { printf '%s\n' "Darwin"; }
detect_platform
unset -f uname
[ "$IS_MACOS" -eq 1 ] && [ "$PLATFORM" = "macos" ] \
  || fail "Darwin tidak dideteksi sebagai macOS"

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

# Override arsip flat juga wajib diterima; tar dapat exit 0 sambil membuang
# semua entry bila --strip-components dipakai pada format ini.
flat_archive_root="$test_root/php-flat"
flat_archive="$test_root/php-flat.tar.gz"
mkdir -p "$flat_archive_root"
cp "$repo_root/war.php" "$flat_archive_root/war.php"
cp "$repo_root/install.sh" "$flat_archive_root/install.sh"
tar -czf "$flat_archive" -C "$flat_archive_root" .
ARCHIVE_SHA256="$(sha256sum "$flat_archive" | awk '{print $1}')"
curl_download() { cp "$flat_archive" "$2"; }
download_package >/dev/null
[ -f "$EXTRACT_DIR/war.php" ] && [ -f "$EXTRACT_DIR/install.sh" ] \
  || fail "arsip flat tidak diekstrak dengan benar"
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

# Verifikasi hanya lint dan tidak boleh mengeksekusi flow aplikasi.
APP_DIR="$test_root/no-exec-wdp1"
mkdir -p "$APP_DIR"
executed_marker="$test_root/war-was-executed"
printf '%s\n' '<?php' "file_put_contents('$executed_marker', 'ran');" \
  > "$APP_DIR/war.php"
printf '%s\n' '#!/usr/bin/env bash' > "$APP_DIR/install.sh"
for file in "${CONFIG_FILES[@]}"; do
  : > "$APP_DIR/$file"
done
verify_php_runtime() { :; }
verify_php_setup >/dev/null
[ ! -e "$executed_marker" ] \
  || fail "verify_php_setup mengeksekusi war.php"

# Target wdp1 dan file managed tidak boleh berupa symlink.
symlink_root="$test_root/symlink-root"
mkdir -p "$symlink_root/real"
APP_DIR="$symlink_root/link"
ln -s "$symlink_root/real" "$APP_DIR"
if [ -L "$APP_DIR" ]; then
  if (install_files_from_extract "$extract_dir") >/dev/null 2>&1; then
    fail "folder wdp1 berupa symlink diterima"
  fi
fi

APP_DIR="$test_root/symlink-file-wdp1"
mkdir -p "$APP_DIR"
outside_file="$test_root/outside-war.php"
printf 'outside-safe\n' > "$outside_file"
ln -s "$outside_file" "$APP_DIR/war.php"
if [ -L "$APP_DIR/war.php" ]; then
  if (install_files_from_extract "$extract_dir") >/dev/null 2>&1; then
    fail "symlink file managed diterima"
  fi
  [ "$(cat "$outside_file")" = "outside-safe" ] \
    || fail "referent luar symlink berubah"
fi

# Symlink config juga wajib ditolak meskipun file tersebut tidak tersedia
# di source package; referent luar tidak boleh dibuat/diubah.
APP_DIR="$test_root/symlink-config-wdp1"
missing_config_extract="$test_root/missing-config-extract"
mkdir -p "$APP_DIR" "$missing_config_extract"
cp "$extract_dir/war.php" "$missing_config_extract/war.php"
cp "$extract_dir/install.sh" "$missing_config_extract/install.sh"
outside_config="$test_root/outside-lead.txt"
ln -s "$outside_config" "$APP_DIR/lead.txt"
if [ -L "$APP_DIR/lead.txt" ]; then
  if (install_files_from_extract "$missing_config_extract") >/dev/null 2>&1; then
    fail "symlink config tanpa source diterima"
  fi
  [ ! -e "$outside_config" ] \
    || fail "referent luar config symlink dibuat/diubah"
fi

printf 'php-only default/update routing: ok\n'
