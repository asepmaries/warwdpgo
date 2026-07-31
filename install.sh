#!/usr/bin/env bash
# ======================================================================
# WARWDPGO installer - PHP-only
#
# Termux : paket utama di /sdcard/wdp + salinan di /sdcard/wdp/wdp1
# Linux  : paket utama di $HOME + salinan di $HOME/wdp1
# macOS  : paket utama di $HOME + salinan di $HOME/wdp1
#
# Aplikasi berasal dari arsip repository GitHub. Ubuntu 24.04 AMD64 memakai
# runtime bundle GitHub Release yang versioned dan checksum-pinned.
#
# Jalankan:
#   bash install.sh
#   bash install.sh --menu
#   bash install.sh --update
#   bash install.sh --clock-only
#   bash install.sh --verify-clock
#   APP_DIR=/path bash install.sh
# ======================================================================
set -Eeuo pipefail

GITHUB_REPO="${GITHUB_REPO:-asepmaries/warwdpgo}"
GITHUB_REF="${GITHUB_REF:-main}"

# Runtime bundle production untuk fast path Ubuntu 24.04 AMD64. Asset release
# bersifat versioned; checksum tidak diambil dari jaringan dan wajib cocok.
RUNTIME_BUNDLE_REPO="${RUNTIME_BUNDLE_REPO:-asepmaries/warwdpgo}"
RUNTIME_BUNDLE_VERSION="v3"
RUNTIME_BUNDLE_RELEASE_TAG="runtime-ubuntu24-amd64-v3"
RUNTIME_BUNDLE_ARCHIVE="runtime-ubuntu24-amd64-v3.tar.gz"
RUNTIME_BUNDLE_SHA256="1bf80c485efe1b929936bff4c3ae359c2bc45119b18788d5b8a3cbe015d714b4"
RUNTIME_BUNDLE_SIZE_BYTES="36132390"
RUNTIME_BUNDLE_URL="${RUNTIME_BUNDLE_URL:-https://github.com/$RUNTIME_BUNDLE_REPO/releases/download/$RUNTIME_BUNDLE_RELEASE_TAG/$RUNTIME_BUNDLE_ARCHIVE}"
RUNTIME_BUNDLE_FILE="${RUNTIME_BUNDLE_FILE:-}"
RUNTIME_BUNDLE_DOWNLOAD_TIMEOUT_SEC="${RUNTIME_BUNDLE_DOWNLOAD_TIMEOUT_SEC:-60}"
RUNTIME_BUNDLE_INSTALL_TIMEOUT_SEC="${RUNTIME_BUNDLE_INSTALL_TIMEOUT_SEC:-90}"
RUNTIME_BUNDLE_CACHE_ROOT="/var/cache/warwdpgo"
RUNTIME_BUNDLE_READY_FILE="/var/lib/warwdpgo/runtime-ready"
RUNTIME_BUNDLE_LEGACY_READY_FILE="/var/lib/warwdpgo-experiment/runtime-ready"

# Policy kesehatan clock. Unit correction/error adalah detik; skew adalah ppm.
CLOCK_WAIT_TRIES="${CLOCK_WAIT_TRIES:-30}"
CLOCK_WAIT_INTERVAL_SEC="${CLOCK_WAIT_INTERVAL_SEC:-1}"
CLOCK_MAX_CORRECTION_SEC="${CLOCK_MAX_CORRECTION_SEC:-0.005}"
CLOCK_MAX_RMS_SEC="${CLOCK_MAX_RMS_SEC:-0.010}"
CLOCK_MAX_SKEW_PPM="${CLOCK_MAX_SKEW_PPM:-100}"
CLOCK_MAX_ERROR_SEC="${CLOCK_MAX_ERROR_SEC:-0.050}"

PHP_CORE_FILES=(war.php install.sh)
CONFIG_FILES=(waktu.txt user_server_wdp.txt lead.txt reload.txt target_srv.txt)

FORCE_OVERWRITE=0
APP_DIR_EXPLICIT=0
MODE="auto"
CLOCK_GATE_PASSED=0
PACKAGE_WAR_SHA256=""
TMP_DIR=""
EXTRACT_DIR=""
RUNTIME_TMP_DIR=""
CHRONY_RESTART_REQUIRED=0
CHRONY_PROVIDER_CONFIG_FILE="${CHRONY_PROVIDER_CONFIG_FILE:-/etc/chrony/conf.d/98-wdp-provider.conf}"

# ----------------------------------------------------------------------
# Util
# ----------------------------------------------------------------------
log()  { printf '\n==> %s\n' "$*"; }
ok()   { printf '    [OK] %s\n' "$*"; }
warn() { printf '    [!] %s\n' "$*" >&2; }
die()  { printf '\n[ERROR] %s\n' "$*" >&2; exit 1; }

die_clock_unhealthy() {
  printf '\n[ERROR] %s\n' "$*" >&2
  printf '%s\n' "__WDP_CLOCK_UNHEALTHY__" >&2
  exit 1
}

on_unexpected_error() {
  local exit_code="${1:-1}" line="${2:-unknown}"
  trap - ERR
  printf '\n[ERROR] Installer berhenti tak terduga di baris %s (exit %s).\n' \
    "$line" "$exit_code" >&2
  printf '%s\n' "__WDP_INSTALLER_ERROR__" >&2
  exit "$exit_code"
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 \
    || die "Command wajib tidak ada: $1"
}

is_sha256() {
  printf '%s\n' "$1" | grep -Eq '^[[:xdigit:]]{64}$'
}

require_positive_integer() {
  local name="$1" value="$2"
  case "$value" in
    ''|*[!0-9]*) die "$name wajib bilangan bulat positif (didapat: $value)" ;;
  esac
  [ "$value" -gt 0 ] || die "$name wajib > 0"
}

require_nonnegative_number() {
  local name="$1" value="$2"
  printf '%s\n' "$value" \
    | grep -Eq '^([0-9]+([.][0-9]*)?|[.][0-9]+)$' \
    || die "$name wajib angka non-negatif (didapat: $value)"
}

run_root() {
  if [ "$(id -u)" -eq 0 ]; then
    "$@"
  elif command -v sudo >/dev/null 2>&1; then
    sudo "$@"
  else
    die "Butuh root/sudo untuk: $*"
  fi
}

# ----------------------------------------------------------------------
# Platform dan argumen
# ----------------------------------------------------------------------
IS_TERMUX=0
IS_LINUX=0
IS_MACOS=0
PLATFORM="unknown"

detect_platform() {
  local kernel
  IS_TERMUX=0
  IS_LINUX=0
  IS_MACOS=0
  PLATFORM="unknown"
  kernel="$(uname -s 2>/dev/null || true)"

  if [ -n "${PREFIX:-}" ] && [ -d "/data/data/com.termux" ] 2>/dev/null; then
    IS_TERMUX=1
    PLATFORM="termux"
  elif [ -n "${TERMUX_VERSION:-}" ] || [ -n "${TERMUX_APK_RELEASE:-}" ]; then
    IS_TERMUX=1
    PLATFORM="termux"
  elif command -v termux-setup-storage >/dev/null 2>&1; then
    IS_TERMUX=1
    PLATFORM="termux"
  elif [ "$kernel" = "Linux" ]; then
    IS_LINUX=1
    PLATFORM="linux"
  elif [ "$kernel" = "Darwin" ]; then
    IS_MACOS=1
    PLATFORM="macos"
  else
    PLATFORM="unsupported:${kernel:-unknown}"
  fi
}

default_app_dir() {
  if [ "$IS_TERMUX" -eq 1 ]; then
    printf '%s' "/sdcard/wdp"
  else
    [ -n "${HOME:-}" ] && [ "${HOME:-}" != "/" ] \
      || die "HOME user tidak valid; gunakan --app-dir /path"
    printf '%s' "${HOME%/}"
  fi
}

parse_args() {
  while [ $# -gt 0 ]; do
    case "$1" in
      --menu|-m)
        MODE="menu"
        ;;
      --update|-u)
        MODE="update"
        ;;
      --clock-only)
        MODE="clock-only"
        ;;
      --verify-clock)
        MODE="verify-clock"
        ;;
      --github-ref)
        [ $# -ge 2 ] || die "--github-ref membutuhkan nilai"
        shift
        GITHUB_REF="$1"
        ;;
      --runtime-bundle-file)
        [ $# -ge 2 ] || die "--runtime-bundle-file membutuhkan FILE"
        shift
        RUNTIME_BUNDLE_FILE="$1"
        ;;
      --force|-f)
        FORCE_OVERWRITE=1
        ;;
      --app-dir)
        [ $# -ge 2 ] || die "--app-dir membutuhkan DIR"
        shift
        APP_DIR="$1"
        APP_DIR_EXPLICIT=1
        ;;
      --help|-h)
        cat <<'EOF'
Usage: bash install.sh [options]

  (default)             Install PHP
  --menu, -m            Tampilkan menu pilihan
  --update, -u          Sync war.php dan config dari GitHub
  --clock-only          Linux: install/start chrony lalu tunggu clock sehat
  --verify-clock        Linux: verifikasi clock tanpa mengubah paket
  --github-ref REF      Branch, tag, atau commit GitHub (default: main)
  --runtime-bundle-file FILE
                        Ubuntu 24 AMD64: gunakan bundle lokal, tanpa download
  --force, -f           Overwrite config lokal
  --app-dir DIR         Direktori utama (default: HOME; Termux:/sdcard/wdp)
  --help, -h            Bantuan

Jalankan sebagai user login biasa. Hak root hanya dipakai untuk dependency
sistem. Paket disalin ke APP_DIR dan APP_DIR/wdp1.

Env:
  GITHUB_REPO / GITHUB_REF
  RUNTIME_BUNDLE_REPO / RUNTIME_BUNDLE_URL / RUNTIME_BUNDLE_FILE
  RUNTIME_BUNDLE_DOWNLOAD_TIMEOUT_SEC / RUNTIME_BUNDLE_INSTALL_TIMEOUT_SEC
  CLOCK_WAIT_TRIES / CLOCK_WAIT_INTERVAL_SEC
  CLOCK_MAX_CORRECTION_SEC / CLOCK_MAX_RMS_SEC
  CLOCK_MAX_SKEW_PPM / CLOCK_MAX_ERROR_SEC
  APP_DIR
EOF
        exit 0
        ;;
      *)
        die "Argumen tidak dikenal: $1"
        ;;
    esac
    shift
  done
}

# ----------------------------------------------------------------------
# Paket GitHub
# ----------------------------------------------------------------------
validate_github_settings() {
  printf '%s\n' "$GITHUB_REPO" \
    | grep -Eq '^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$' \
    || die "GITHUB_REPO tidak valid: $GITHUB_REPO"

  printf '%s\n' "$GITHUB_REF" \
    | grep -Eq '^[A-Za-z0-9._/-]+$' \
    || die "GITHUB_REF tidak valid: $GITHUB_REF"
  case "$GITHUB_REF" in
    ''|/*|*/|*..*|*//*)
      die "GITHUB_REF tidak aman: $GITHUB_REF"
      ;;
  esac
}

github_archive_url() {
  validate_github_settings
  printf 'https://github.com/%s/archive/%s.tar.gz' \
    "$GITHUB_REPO" "$GITHUB_REF"
}

curl_retry_extra_flags() {
  local help_text flags=""
  help_text="$(curl --help all 2>/dev/null || curl --help 2>/dev/null || true)"
  printf '%s\n' "$help_text" | grep -Fq -- '--retry-connrefused' \
    && flags="$flags --retry-connrefused"
  printf '%s\n' "$help_text" | grep -Fq -- '--retry-all-errors' \
    && flags="$flags --retry-all-errors"
  printf '%s\n' "$flags"
}

curl_download() {
  local url="$1" dest="$2" retry_flags
  case "$url" in
    https://github.com/*|https://codeload.github.com/*) ;;
    *)
      warn "Tolak sumber paket di luar GitHub: $url"
      return 1
      ;;
  esac

  retry_flags="$(curl_retry_extra_flags)"
  # shellcheck disable=SC2086
  curl --fail --location --silent --show-error \
    --retry 2 --retry-delay 1 $retry_flags \
    --connect-timeout 5 --max-time 30 \
    --proto '=https' --tlsv1.2 \
    "$url" -o "$dest"
}

have_sha256_tool() {
  command -v sha256sum >/dev/null 2>&1 \
    || command -v shasum >/dev/null 2>&1
}

file_sha256() {
  local file="$1"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$file" | awk '{print $1}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$file" | awk '{print $1}'
  else
    return 127
  fi
}

need_sha256_tool() {
  have_sha256_tool \
    || die "Butuh sha256sum (Linux/Termux) atau shasum (macOS)"
}

archive_entry_is_unsafe() {
  local entry="$1"
  case "$entry" in
    /*|../*|*/../*|*/..)
      return 0
      ;;
  esac
  return 1
}

download_package() {
  local tmp_dir archive_file extract_dir archive_url entry required
  local archive_listing
  local -a required_files=(war.php install.sh)

  need_cmd curl
  need_cmd tar
  need_sha256_tool
  validate_github_settings

  tmp_dir="$(mktemp -d)"
  TMP_DIR="$tmp_dir"
  EXTRACT_DIR=""
  PACKAGE_WAR_SHA256=""
  trap cleanup_download EXIT

  archive_file="$tmp_dir/warwdpgo-github.tar.gz"
  extract_dir="$tmp_dir/extract"
  mkdir -p "$extract_dir"
  archive_url="$(github_archive_url)"

  log "Download paket PHP dari GitHub"
  printf '    Repository: %s\n' "$GITHUB_REPO"
  printf '    Ref       : %s\n' "$GITHUB_REF"
  printf '    URL       : %s\n' "$archive_url"
  curl_download "$archive_url" "$archive_file" \
    || die "Gagal download arsip GitHub"

  archive_listing="$(tar -tzf "$archive_file")" \
    || die "Arsip GitHub bukan tar.gz yang valid"
  [ -n "$archive_listing" ] || die "Arsip GitHub kosong"
  while IFS= read -r entry; do
    archive_entry_is_unsafe "$entry" \
      && die "Path tidak aman di arsip GitHub: $entry"
  done <<< "$archive_listing"

  tar -xzf "$archive_file" -C "$extract_dir" --strip-components=1 \
    || die "Gagal extract arsip GitHub"

  for required in "${required_files[@]}"; do
    [ -f "$extract_dir/$required" ] \
      || die "Arsip GitHub tidak lengkap; file wajib hilang: $required"
    [ ! -L "$extract_dir/$required" ] \
      || die "File wajib tidak boleh berupa symlink: $required"
  done

  PACKAGE_WAR_SHA256="$(file_sha256 "$extract_dir/war.php")" \
    || die "Tidak bisa menghitung checksum war.php dari GitHub"
  is_sha256 "$PACKAGE_WAR_SHA256" \
    || die "Checksum war.php dari GitHub tidak valid"

  EXTRACT_DIR="$extract_dir"
  ok "Paket GitHub siap (SHA-256 war.php: $PACKAGE_WAR_SHA256)"
}

cleanup_download() {
  trap - EXIT
  if [ -n "${TMP_DIR:-}" ] && [ -d "${TMP_DIR:-}" ]; then
    rm -rf -- "$TMP_DIR"
  fi
  TMP_DIR=""
  EXTRACT_DIR=""
}

copy_file_smart() {
  local src="$1" dest="$2" is_config="${3:-0}"
  local base
  base="$(basename "$dest")"

  [ ! -L "$dest" ] \
    || die "Menolak overwrite symlink tujuan: $dest"
  if [ ! -f "$src" ]; then
    warn "Skip (tidak ada di paket): $base"
    return 0
  fi

  if [ "$is_config" = "1" ] \
    && [ -f "$dest" ] \
    && [ "$FORCE_OVERWRITE" -eq 0 ] \
    && [ -s "$dest" ]; then
    ok "Pertahankan config lokal: $base"
    return 0
  fi

  cp -f "$src" "$dest"
  ok "Copy $base"
}

install_files_from_extract() {
  local extract_dir="$1"
  local target_dir="${2:-$APP_DIR}"
  local file

  [ ! -L "$target_dir" ] \
    || die "Folder instalasi tidak boleh berupa symlink: $target_dir"
  if [ -e "$target_dir" ] && [ ! -d "$target_dir" ]; then
    die "Target instalasi bukan folder: $target_dir"
  fi
  mkdir -p "$target_dir"

  log "Pasang paket PHP ke $target_dir"
  for file in "${PHP_CORE_FILES[@]}"; do
    copy_file_smart "$extract_dir/$file" "$target_dir/$file" 0
  done
  for file in "${CONFIG_FILES[@]}"; do
    copy_file_smart "$extract_dir/$file" "$target_dir/$file" 1
  done

  for file in "${CONFIG_FILES[@]}"; do
    [ ! -L "$target_dir/$file" ] \
      || die "Menolak symlink config tujuan: $target_dir/$file"
    if [ ! -f "$target_dir/$file" ]; then
      : > "$target_dir/$file"
      ok "Buat kosong: $file"
    fi
  done

  chmod +x "$target_dir/install.sh" 2>/dev/null || true
}

# ----------------------------------------------------------------------
# Runtime PHP
# ----------------------------------------------------------------------
php_runtime_ready() {
  command -v php >/dev/null 2>&1 || return 1
  php -r '
    if (PHP_SAPI !== "cli" || PHP_VERSION_ID < 70400) exit(1);
    if (!extension_loaded("curl") || !extension_loaded("json")) exit(1);
    foreach ([
      "curl_init", "curl_multi_init", "curl_share_init",
      "json_encode", "hrtime", "random_bytes", "array_key_last"
    ] as $function) {
      if (!function_exists($function)) exit(1);
    }
    $curl = curl_version();
    if (empty($curl["ssl_version"])) exit(1);
    if (!in_array("https", $curl["protocols"] ?? [], true)) exit(1);
  ' >/dev/null 2>&1
}

verify_php_runtime() {
  need_cmd php
  if ! php -r '
    if (PHP_SAPI !== "cli") {
      fwrite(STDERR, "PHP SAPI wajib cli\n");
      exit(1);
    }
    if (PHP_VERSION_ID < 70400) {
      fwrite(STDERR, "PHP minimal 7.4; didapat " . PHP_VERSION . "\n");
      exit(2);
    }
    if (!extension_loaded("curl") || !extension_loaded("json")) {
      fwrite(STDERR, "Ekstensi PHP curl/json wajib aktif\n");
      exit(3);
    }
    foreach ([
      "curl_init", "curl_multi_init", "curl_share_init",
      "json_encode", "hrtime", "random_bytes", "array_key_last"
    ] as $function) {
      if (!function_exists($function)) {
        fwrite(STDERR, "Fungsi PHP wajib tidak ada: {$function}\n");
        exit(4);
      }
    }
    $curl = curl_version();
    if (empty($curl["ssl_version"])
      || !in_array("https", $curl["protocols"] ?? [], true)
    ) {
      fwrite(STDERR, "libcurl PHP wajib mendukung HTTPS/TLS\n");
      exit(5);
    }
  '; then
    die "Runtime PHP belum memenuhi kebutuhan war.php"
  fi
  ok "Runtime PHP siap: $(php -r 'printf("PHP %s", PHP_VERSION);')"
}

verify_php_install_dir() {
  local verify_dir="$1" required installed_war_sha
  [ -d "$verify_dir" ] && [ -w "$verify_dir" ] \
    || die "Folder aplikasi tidak writable: $verify_dir"
  for required in "${PHP_CORE_FILES[@]}" "${CONFIG_FILES[@]}"; do
    [ ! -L "$verify_dir/$required" ] \
      || die "Verify paket PHP menolak symlink: $verify_dir/$required"
    [ -f "$verify_dir/$required" ] \
      || die "Verify paket PHP gagal: $verify_dir/$required tidak ada"
  done
  [ -s "$verify_dir/war.php" ] \
    || die "Verify PHP gagal: $verify_dir/war.php kosong"
  php -l "$verify_dir/war.php" >/dev/null \
    || die "Syntax war.php tidak valid: $verify_dir/war.php"
  is_sha256 "${PACKAGE_WAR_SHA256:-}" \
    || die "Checksum war.php paket tidak tersedia saat verifikasi"
  installed_war_sha="$(file_sha256 "$verify_dir/war.php")" \
    || die "Tidak bisa menghitung checksum: $verify_dir/war.php"
  [ "$installed_war_sha" = "$PACKAGE_WAR_SHA256" ] \
    || die "war.php berbeda dari paket GitHub di $verify_dir"
  ok "Paket GitHub terverifikasi: $verify_dir"
}

verify_php_setup() {
  verify_php_runtime
  log "Verifikasi dua salinan paket"
  verify_php_install_dir "$APP_DIR"
  verify_php_install_dir "$WDP1_DIR"
  printf '%s\n' "__WDP_PHP_SETUP_OK__"
  ok "Direktori utama dan folder wdp1 siap"
}

# ----------------------------------------------------------------------
# Termux
# ----------------------------------------------------------------------
setup_termux_storage() {
  if [ ! -d /sdcard ]; then
    log "Aktifkan izin storage Termux"
    if command -v termux-setup-storage >/dev/null 2>&1; then
      termux-setup-storage || true
      sleep 2
    else
      warn "termux-setup-storage tidak ada; pastikan /sdcard bisa diakses"
    fi
  fi
}

install_termux_php() {
  log "Termux: update paket dan install PHP"
  if command -v apt >/dev/null 2>&1; then
    apt update
    DEBIAN_FRONTEND=noninteractive apt -y \
      -o Dpkg::Options::="--force-confdef" \
      -o Dpkg::Options::="--force-confold" upgrade || true
    apt install -y php curl tar coreutils \
      || die "Gagal install PHP di Termux"
  else
    pkg update -y
    pkg install -y php curl tar coreutils \
      || die "Gagal install PHP di Termux"
  fi
  verify_php_runtime
}

do_install_termux() {
  setup_termux_storage
  install_termux_php
  download_package
  install_files_from_extract "$EXTRACT_DIR" "$APP_DIR"
  install_files_from_extract "$EXTRACT_DIR" "$WDP1_DIR"
  cleanup_download
  verify_php_setup

  cat <<EOF

============================================================
TERMUX/Android siap
  Paket utama : $APP_DIR
  Salinan wdp1: $WDP1_DIR
  Sumber       : GitHub $GITHUB_REPO@$GITHUB_REF

Jalankan:
  cd $WDP1_DIR
  php war.php

Update:
  bash $APP_DIR/install.sh --update
============================================================
EOF
  print_install_success
}

# ----------------------------------------------------------------------
# macOS
# ----------------------------------------------------------------------
macos_prepare_php() {
  local brew_bin="" php_prefix

  if php_runtime_ready; then
    ok "macOS: runtime PHP sudah tersedia"
  else
    if command -v brew >/dev/null 2>&1; then
      brew_bin="$(command -v brew)"
    elif [ -x /opt/homebrew/bin/brew ]; then
      brew_bin="/opt/homebrew/bin/brew"
    elif [ -x /usr/local/bin/brew ]; then
      brew_bin="/usr/local/bin/brew"
    else
      die "PHP CLI/cURL belum siap. Pasang Homebrew lalu ulangi installer."
    fi
    log "macOS: install/upgrade PHP melalui Homebrew"
    "$brew_bin" install php || die "Homebrew gagal memasang PHP"
    php_prefix="$("$brew_bin" --prefix php 2>/dev/null || true)"
    if [ -n "$php_prefix" ]; then
      export PATH="$php_prefix/bin:$php_prefix/sbin:$PATH"
      hash -r
    fi
  fi

  need_cmd curl
  need_cmd tar
  need_sha256_tool
  verify_php_runtime
  ok "macOS: dependency PHP siap"
}

do_install_macos() {
  macos_prepare_php
  download_package
  install_files_from_extract "$EXTRACT_DIR" "$APP_DIR"
  install_files_from_extract "$EXTRACT_DIR" "$WDP1_DIR"
  cleanup_download
  verify_php_setup

  cat <<EOF

============================================================
macOS siap
  Paket utama : $APP_DIR
  Salinan wdp1: $WDP1_DIR
  Sumber       : GitHub $GITHUB_REPO@$GITHUB_REF

Jalankan:
  cd $WDP1_DIR
  php war.php

Update:
  bash $APP_DIR/install.sh --update
============================================================
EOF
  print_install_success
}

# ----------------------------------------------------------------------
# Linux / VPS
# ----------------------------------------------------------------------
linux_set_timezone() {
  log "Linux: timezone Asia/Jakarta"
  if command -v timedatectl >/dev/null 2>&1 \
    && run_root timedatectl set-timezone Asia/Jakarta; then
    :
  elif [ -f /usr/share/zoneinfo/Asia/Jakarta ]; then
    run_root ln -sf /usr/share/zoneinfo/Asia/Jakarta /etc/localtime \
      || die "Gagal memasang /etc/localtime Asia/Jakarta"
    printf '%s\n' "Asia/Jakarta" | run_root tee /etc/timezone >/dev/null \
      || die "Gagal menulis /etc/timezone"
  else
    die "Timezone Asia/Jakarta tidak tersedia"
  fi

  [ "$(date +%z 2>/dev/null || true)" = "+0700" ] \
    || die "Timezone aktif bukan Asia/Jakarta (+0700)"
  ok "Timezone Asia/Jakarta aktif"
}

linux_is_ubuntu24() {
  local identity
  [ -r /etc/os-release ] || return 1
  identity="$(
    # shellcheck disable=SC1091
    . /etc/os-release
    printf '%s:%s' "${ID:-}" "${VERSION_ID:-}"
  )"
  [ "$identity" = "ubuntu:24.04" ]
}

linux_dpkg_architecture() {
  if command -v dpkg >/dev/null 2>&1; then
    dpkg --print-architecture
  else
    case "$(uname -m 2>/dev/null || true)" in
      x86_64) printf '%s\n' amd64 ;;
      aarch64|arm64) printf '%s\n' arm64 ;;
      *) printf '%s\n' unknown ;;
    esac
  fi
}

linux_is_oci() {
  local cloud_identity="" chassis_asset=""
  if command -v cloud-id >/dev/null 2>&1; then
    cloud_identity="$(cloud-id 2>/dev/null || true)"
  fi
  [ "$cloud_identity" = "oracle" ] && return 0

  if [ -r /sys/class/dmi/id/chassis_asset_tag ]; then
    chassis_asset="$(cat /sys/class/dmi/id/chassis_asset_tag 2>/dev/null || true)"
  fi
  [ "$chassis_asset" = "OracleCloud.com" ]
}

linux_configure_provider_ntp() {
  local expected_config marker_tmp
  CHRONY_RESTART_REQUIRED=0
  linux_is_oci || return 0

  expected_config='# OCI local NTP: managed, regional, and reachable inside the VCN.
server 169.254.169.254 iburst prefer'
  [ ! -L "$CHRONY_PROVIDER_CONFIG_FILE" ] \
    || die "Menolak symlink konfigurasi provider NTP"
  if [ -r "$CHRONY_PROVIDER_CONFIG_FILE" ] \
    && [ "$(cat "$CHRONY_PROVIDER_CONFIG_FILE")" = "$expected_config" ]; then
    ok "OCI local NTP sudah dikonfigurasi"
    return 0
  fi

  marker_tmp="$(mktemp)"
  printf '%s\n' "$expected_config" > "$marker_tmp"
  if ! run_root install -D -m 0644 "$marker_tmp" \
      "$CHRONY_PROVIDER_CONFIG_FILE"; then
    rm -f -- "$marker_tmp"
    die "Gagal menulis konfigurasi OCI local NTP"
  fi
  rm -f -- "$marker_tmp"
  CHRONY_RESTART_REQUIRED=1
  ok "OCI local NTP aktif: 169.254.169.254"
}

runtime_bundle_expected_marker() {
  printf 'runtime-ubuntu24-amd64-%s\n' "$RUNTIME_BUNDLE_VERSION"
}

runtime_bundle_health_ready() {
  php_runtime_ready \
    && command -v curl >/dev/null 2>&1 \
    && command -v tar >/dev/null 2>&1 \
    && command -v sha256sum >/dev/null 2>&1 \
    && command -v chronyc >/dev/null 2>&1
}

runtime_bundle_marker_ready() {
  [ -r "$RUNTIME_BUNDLE_READY_FILE" ] \
    && [ "$(cat "$RUNTIME_BUNDLE_READY_FILE" 2>/dev/null || true)" = \
      "$(runtime_bundle_expected_marker)" ] \
    && runtime_bundle_health_ready
}

write_runtime_bundle_marker() {
  local marker_tmp
  marker_tmp="$(mktemp)"
  printf '%s\n' "$(runtime_bundle_expected_marker)" > "$marker_tmp"
  if ! run_root install -D -m 0644 "$marker_tmp" \
      "$RUNTIME_BUNDLE_READY_FILE"; then
    rm -f -- "$marker_tmp"
    die "Gagal menulis marker runtime production"
  fi
  rm -f -- "$marker_tmp"
}

cleanup_runtime_download() {
  trap - EXIT
  if [ -n "${RUNTIME_TMP_DIR:-}" ] && [ -d "${RUNTIME_TMP_DIR:-}" ]; then
    rm -rf -- "$RUNTIME_TMP_DIR"
  fi
  RUNTIME_TMP_DIR=""
}

runtime_archive_entry_is_unsafe() {
  local entry="$1"
  case "$entry" in
    /*|../*|*/../*|*/..)
      return 0
      ;;
  esac
  return 1
}

download_runtime_bundle() {
  local dest="$1" timeout_sec="$RUNTIME_BUNDLE_DOWNLOAD_TIMEOUT_SEC"
  require_positive_integer "RUNTIME_BUNDLE_DOWNLOAD_TIMEOUT_SEC" "$timeout_sec"

  if [ -n "$RUNTIME_BUNDLE_FILE" ]; then
    [ -f "$RUNTIME_BUNDLE_FILE" ] \
      || die "Bundle lokal tidak ditemukan: $RUNTIME_BUNDLE_FILE"
    cp "$RUNTIME_BUNDLE_FILE" "$dest" \
      || die "Gagal menyalin bundle lokal"
    ok "Bundle runtime dibaca dari file lokal"
    return 0
  fi

  case "$RUNTIME_BUNDLE_URL" in
    https://*) ;;
    *) die "URL runtime bundle wajib HTTPS" ;;
  esac

  log "Download runtime bundle $RUNTIME_BUNDLE_VERSION"
  printf '    URL: %s\n' "$RUNTIME_BUNDLE_URL"
  need_cmd timeout
  if command -v curl >/dev/null 2>&1; then
    timeout "$timeout_sec" curl \
      --fail --location --silent --show-error \
      --retry 2 --retry-delay 1 --connect-timeout 5 \
      --max-time "$timeout_sec" --proto '=https' --tlsv1.2 \
      "$RUNTIME_BUNDLE_URL" -o "$dest" \
      || die "Gagal download runtime bundle dengan curl"
  elif command -v wget >/dev/null 2>&1; then
    timeout "$timeout_sec" wget --quiet --https-only \
      --timeout=15 --tries=2 -O "$dest" "$RUNTIME_BUNDLE_URL" \
      || die "Gagal download runtime bundle dengan wget"
  elif command -v python3 >/dev/null 2>&1; then
    if ! timeout "$timeout_sec" \
        python3 - "$RUNTIME_BUNDLE_URL" "$dest" <<'PY'
import shutil
import ssl
import sys
import urllib.request

request = urllib.request.Request(
    sys.argv[1], headers={"User-Agent": "warwdpgo-installer/1"}
)
with urllib.request.urlopen(
    request, timeout=15, context=ssl.create_default_context()
) as response, open(sys.argv[2], "wb") as output:
    shutil.copyfileobj(response, output)
PY
    then
      die "Gagal download runtime bundle dengan python3"
    fi
  else
    die "Butuh curl, wget, atau python3 untuk download runtime bundle"
  fi
}

linux_install_runtime_bundle() {
  local archive actual_sha actual_size archive_listing top_dir entry
  local expected_top install_root install_timeout

  if runtime_bundle_marker_ready; then
    log "Linux: runtime bundle $RUNTIME_BUNDLE_VERSION sudah sehat"
    ok "Fast path runtime aktif (skip download dan APT)"
    return 0
  fi

  if [ -r "$RUNTIME_BUNDLE_LEGACY_READY_FILE" ] \
    && [ "$(cat "$RUNTIME_BUNDLE_LEGACY_READY_FILE" 2>/dev/null || true)" = \
      "$RUNTIME_BUNDLE_VERSION" ] \
    && runtime_bundle_health_ready; then
    log "Adopsi runtime bundle $RUNTIME_BUNDLE_VERSION yang sudah terpasang"
    write_runtime_bundle_marker
    ok "Marker runtime production siap"
    return 0
  fi

  [ "$(linux_dpkg_architecture)" = "amd64" ] \
    || die "Runtime bundle Ubuntu 24 belum tersedia untuk arsitektur $(linux_dpkg_architecture)"
  need_cmd mktemp
  need_cmd tar
  need_cmd sha256sum
  need_cmd wc
  install_timeout="$RUNTIME_BUNDLE_INSTALL_TIMEOUT_SEC"
  require_positive_integer "RUNTIME_BUNDLE_INSTALL_TIMEOUT_SEC" "$install_timeout"

  RUNTIME_TMP_DIR="$(mktemp -d)"
  trap cleanup_runtime_download EXIT
  archive="$RUNTIME_TMP_DIR/$RUNTIME_BUNDLE_ARCHIVE"
  download_runtime_bundle "$archive"

  actual_size="$(wc -c < "$archive" | tr -d '[:space:]')"
  [ "$actual_size" = "$RUNTIME_BUNDLE_SIZE_BYTES" ] \
    || die "Ukuran runtime bundle berbeda: $actual_size byte"
  actual_sha="$(sha256sum "$archive" | awk '{print $1}')"
  [ "$actual_sha" = "$RUNTIME_BUNDLE_SHA256" ] \
    || die "Checksum runtime bundle berbeda"
  ok "Ukuran dan SHA-256 runtime bundle valid"

  archive_listing="$(tar -tzf "$archive")" \
    || die "Runtime bundle bukan tar.gz yang valid"
  [ -n "$archive_listing" ] || die "Runtime bundle kosong"
  top_dir="${archive_listing%%/*}"
  expected_top="runtime-ubuntu24-amd64-$RUNTIME_BUNDLE_VERSION"
  [ "$top_dir" = "$expected_top" ] \
    || die "Root directory runtime bundle tidak valid: $top_dir"
  while IFS= read -r entry; do
    runtime_archive_entry_is_unsafe "$entry" \
      && die "Path tidak aman di runtime bundle: $entry"
    case "$entry" in
      "$top_dir"|"$top_dir/"|"$top_dir/"*) ;;
      *) die "Runtime bundle memiliki lebih dari satu root directory" ;;
    esac
  done <<< "$archive_listing"

  install_root="$RUNTIME_BUNDLE_CACHE_ROOT/$top_dir"
  run_root install -d -m 0755 "$RUNTIME_BUNDLE_CACHE_ROOT"
  run_root rm -rf -- "$install_root"
  run_root tar -xzf "$archive" -C "$RUNTIME_BUNDLE_CACHE_ROOT"
  run_root test -x "$install_root/install-runtime-bundle.sh" \
    || die "Installer internal runtime tidak tersedia"

  log "Install runtime dari repository lokal bundle"
  run_root env \
    CLOCK_BURST_ROUNDS=3 \
    CLOCK_ROUND_TRIES=15 \
    CLOCK_WAIT_INTERVAL_SEC=1 \
    timeout "$install_timeout" \
    "$install_root/install-runtime-bundle.sh" \
    || die "Instalasi runtime bundle gagal/timeout"

  runtime_bundle_health_ready \
    || die "Runtime hasil bundle tidak memenuhi kebutuhan aplikasi"
  write_runtime_bundle_marker
  cleanup_runtime_download
  ok "Runtime production siap: $expected_top"
  printf '%s\n' "__WDP_RUNTIME_BUNDLE_READY__"
}

linux_apt_sources_https() {
  local source_file changed=0
  [ -d /etc/apt ] || return 0
  while IFS= read -r source_file; do
    if grep -Eq 'http://([^/]*\.)?archive\.ubuntu\.com/ubuntu|http://security\.ubuntu\.com/ubuntu|http://ports\.ubuntu\.com/ubuntu-ports' "$source_file"; then
      run_root sed -i -E \
        -e 's#http://(([^/]*\.)?archive\.ubuntu\.com/ubuntu)#https://\1#g' \
        -e 's#http://(security\.ubuntu\.com/ubuntu)#https://\1#g' \
        -e 's#http://(ports\.ubuntu\.com/ubuntu-ports)#https://\1#g' \
        "$source_file" || die "Gagal mengubah source Ubuntu ke HTTPS: $source_file"
      changed=1
    fi
  done < <(find /etc/apt -maxdepth 2 -type f \
    \( -name '*.list' -o -name '*.sources' \) -print 2>/dev/null)
  [ "$changed" -eq 0 ] || ok "Source resmi Ubuntu memakai HTTPS"
}

linux_have_ca_bundle() {
  [ -s /etc/ssl/certs/ca-certificates.crt ] \
    || [ -s /etc/pki/tls/certs/ca-bundle.crt ] \
    || [ -s /etc/ssl/cert.pem ]
}

linux_apt_candidate() {
  local package="$1" policy
  command -v apt-cache >/dev/null 2>&1 || return 0
  if ! policy="$(LC_ALL=C apt-cache policy "$package" 2>/dev/null)"; then
    return 0
  fi
  printf '%s\n' "$policy" | awk '/Candidate:/ { print $2; exit }' || true
}

linux_apt_base() {
  local include_php="${1:-0}"
  local package candidate need_update=0 install_ok=0
  local -a packages=()
  local -a apt_cmd=()
  local -a install_flags=(--no-install-recommends --no-upgrade)
  local -a apt_opts=(
    -o Acquire::ForceIPv4=true
    -o Acquire::Retries=2
    -o Acquire::http::Timeout=12
    -o Acquire::https::Timeout=20
    -o DPkg::Lock::Timeout=60
    -o Dpkg::Use-Pty=0
  )

  if ! linux_have_ca_bundle \
    && command -v update-ca-certificates >/dev/null 2>&1; then
    run_root update-ca-certificates --fresh >/dev/null 2>&1 || true
  fi
  linux_have_ca_bundle \
    || die "CA bundle HTTPS tidak tersedia"

  command -v curl >/dev/null 2>&1 || packages+=(curl)
  command -v tar >/dev/null 2>&1 || packages+=(tar)
  command -v chronyc >/dev/null 2>&1 || packages+=(chrony)
  if [ "$include_php" = "1" ] && ! php_runtime_ready; then
    packages+=(php-cli php-curl)
  fi
  if ! command -v sha256sum >/dev/null 2>&1 \
    || ! command -v timeout >/dev/null 2>&1; then
    packages+=(coreutils)
  fi

  if [ "${#packages[@]}" -eq 0 ]; then
    log "Linux: seluruh dependency sudah tersedia (skip APT)"
    ok "Fast path dependency aktif"
    return 0
  fi

  if command -v apt-get >/dev/null 2>&1; then
    apt_cmd=(apt-get)
  elif command -v apt >/dev/null 2>&1; then
    apt_cmd=(apt)
  else
    die "Sistem ini bukan Debian/Ubuntu apt"
  fi

  log "Linux: install dependency yang belum ada: ${packages[*]}"
  linux_apt_sources_https

  for package in "${packages[@]}"; do
    candidate="$(linux_apt_candidate "$package")"
    if [ -z "$candidate" ] || [ "$candidate" = "(none)" ]; then
      need_update=1
      break
    fi
  done

  if [ "$need_update" -eq 1 ]; then
    ok "Cache APT belum siap; refresh index satu kali"
    run_root "${apt_cmd[@]}" "${apt_opts[@]}" update \
      || warn "Sebagian repository APT gagal diperbarui"
    for package in "${packages[@]}"; do
      candidate="$(linux_apt_candidate "$package")"
      if [ -z "$candidate" ] || [ "$candidate" = "(none)" ]; then
        printf '%s\n' "__WDP_APT_TRANSIENT__" >&2
        die "Repository APT tidak menyediakan kandidat paket: $package"
      fi
    done
  fi

  if run_root env DEBIAN_FRONTEND=noninteractive \
    "${apt_cmd[@]}" "${apt_opts[@]}" install -y \
      "${install_flags[@]}" "${packages[@]}"; then
    install_ok=1
  elif [ "$need_update" -eq 0 ]; then
    warn "Cache APT lama gagal dipakai; refresh index satu kali"
    run_root "${apt_cmd[@]}" "${apt_opts[@]}" update \
      || warn "Sebagian repository APT gagal diperbarui"
    run_root env DEBIAN_FRONTEND=noninteractive \
      "${apt_cmd[@]}" "${apt_opts[@]}" install -y \
        "${install_flags[@]}" "${packages[@]}" \
      && install_ok=1
  fi

  if [ "$install_ok" -ne 1 ]; then
    printf '%s\n' "__WDP_APT_TRANSIENT__" >&2
    die "Gagal apt install dependency: ${packages[*]}"
  fi

  need_cmd chronyc
  need_cmd sha256sum
  if [ "$include_php" = "1" ]; then
    verify_php_runtime
  fi
  ok "Dependency Linux siap"
}

linux_start_chrony() {
  local unit started=0 started_unit=""
  log "Linux: enable dan start chrony"

  if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
    if systemctl cat systemd-timesyncd.service >/dev/null 2>&1; then
      run_root systemctl disable --now systemd-timesyncd.service \
        >/dev/null 2>&1 \
        || warn "systemd-timesyncd tidak dapat dinonaktifkan"
    fi
    for unit in chrony.service chronyd.service; do
      if systemctl cat "$unit" >/dev/null 2>&1; then
        run_root systemctl enable --now "$unit" \
          || die "Gagal enable/start $unit"
        started=1
        started_unit="$unit"
        break
      fi
    done
  elif command -v service >/dev/null 2>&1; then
    if run_root service chrony start >/dev/null 2>&1; then
      started=1
      started_unit="chrony"
    elif run_root service chronyd start >/dev/null 2>&1; then
      started=1
      started_unit="chronyd"
    fi
  fi

  [ "$started" -eq 1 ] \
    || die "Service chrony/chronyd tidak dapat dimulai"

  if [ "$CHRONY_RESTART_REQUIRED" -eq 1 ]; then
    if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
      run_root systemctl restart "$started_unit" \
        || die "Gagal restart $started_unit setelah config provider"
    else
      run_root service "$started_unit" restart \
        || die "Gagal restart $started_unit setelah config provider"
    fi
  fi
  ok "Daemon chrony aktif"

  run_root chronyc -a online >/dev/null 2>&1 \
    || warn "Chrony online awal tidak tersedia"
  run_root chronyc -a makestep 0.1 1 >/dev/null 2>&1 \
    || warn "Chrony makestep awal tidak tersedia"
  run_root chronyc -a burst 4/4 >/dev/null 2>&1 \
    || warn "Chrony burst awal tidak tersedia"
}

clock_tracking_is_healthy() {
  LC_ALL=C awk -F, \
    -v max_correction="$CLOCK_MAX_CORRECTION_SEC" \
    -v max_rms="$CLOCK_MAX_RMS_SEC" \
    -v max_skew="$CLOCK_MAX_SKEW_PPM" \
    -v max_error="$CLOCK_MAX_ERROR_SEC" '
    function abs(v) { return v < 0 ? -v : v }
    function numeric(v) {
      return v ~ /^[-+]?([0-9]+([.][0-9]*)?|[.][0-9]+)([eE][-+]?[0-9]+)?$/
    }
    function fail(message) {
      print "tracking tidak sehat: " message > "/dev/stderr"
      exit 1
    }
    {
      if (NF != 14) fail("jumlah field CSV " NF ", seharusnya 14")
      sub(/\r$/, "", $14)
      if (toupper($1) == "7F7F0101") fail("chrony memakai synthetic local reference")
      if (!numeric($3) || $3 < 1 || $3 > 15) fail("stratum invalid")
      if ($14 != "Normal") fail("leap status=" $14)
      if (!numeric($5) || !numeric($7) ||
          !numeric($10) || !numeric($11) || !numeric($12)) {
        fail("field numerik invalid")
      }
      if (abs($5) > max_correction) fail("system correction melebihi batas")
      if (abs($7) > max_rms) fail("RMS offset melebihi batas")
      if ($10 < 0 || $10 > max_skew) fail("skew melebihi batas")
      if ($12 < 0) fail("root dispersion negatif")
      error_bound = abs($5) + $12 + (0.5 * abs($11))
      if (error_bound > max_error) fail("clock error bound melebihi batas")
    }
  '
}

linux_wait_clock_health() {
  local wait_output tracking
  CLOCK_GATE_PASSED=0
  need_cmd chronyc
  need_cmd awk
  require_positive_integer "CLOCK_WAIT_TRIES" "$CLOCK_WAIT_TRIES"
  require_positive_integer "CLOCK_WAIT_INTERVAL_SEC" "$CLOCK_WAIT_INTERVAL_SEC"
  require_nonnegative_number \
    "CLOCK_MAX_CORRECTION_SEC" "$CLOCK_MAX_CORRECTION_SEC"
  require_nonnegative_number "CLOCK_MAX_RMS_SEC" "$CLOCK_MAX_RMS_SEC"
  require_nonnegative_number "CLOCK_MAX_SKEW_PPM" "$CLOCK_MAX_SKEW_PPM"
  require_nonnegative_number "CLOCK_MAX_ERROR_SEC" "$CLOCK_MAX_ERROR_SEC"

  log "Clock gate: tunggu chrony sinkron dan akurat"
  if ! wait_output="$(LC_ALL=C chronyc -n waitsync \
      "$CLOCK_WAIT_TRIES" \
      "$CLOCK_MAX_CORRECTION_SEC" \
      "$CLOCK_MAX_SKEW_PPM" \
      "$CLOCK_WAIT_INTERVAL_SEC" 2>&1)"; then
    printf '%s\n' "$wait_output" | sed 's/^/    /' >&2
    LC_ALL=C chronyc -n tracking 2>&1 | sed 's/^/    /' >&2 || true
    LC_ALL=C chronyc -n sources -v 2>&1 | sed 's/^/    /' >&2 || true
    die_clock_unhealthy "chronyc waitsync gagal/timeout"
  fi

  if ! tracking="$(LC_ALL=C chronyc -c tracking 2>&1)"; then
    printf '%s\n' "$tracking" | sed 's/^/    /' >&2
    die_clock_unhealthy "chronyc tracking gagal"
  fi
  if ! printf '%s\n' "$tracking" | clock_tracking_is_healthy; then
    LC_ALL=C chronyc -n tracking 2>&1 | sed 's/^/    /' >&2 || true
    die_clock_unhealthy "Metrik chrony di luar policy"
  fi

  CLOCK_GATE_PASSED=1
  ok "Chrony sinkron; correction/error/skew di dalam batas"
}

print_clock_success_marker() {
  [ "$IS_LINUX" -eq 1 ] || return 0
  [ "$CLOCK_GATE_PASSED" -eq 1 ] \
    || die "Marker sukses ditolak karena clock gate belum lulus"
  printf '%s\n' "__WDP_CLOCK_HEALTHY__"
}

print_install_success() {
  ok "Selesai; installer keluar normal"
  printf '%s\n' "__WDP_INSTALL_OK__"
  print_clock_success_marker
}

linux_prepare_clock() {
  if linux_is_ubuntu24; then
    linux_install_runtime_bundle
  else
    linux_apt_base "${1:-0}"
  fi
  linux_set_timezone
  linux_configure_provider_ntp
  linux_start_chrony
}

do_install_linux() {
  linux_prepare_clock 1
  download_package
  linux_wait_clock_health
  install_files_from_extract "$EXTRACT_DIR" "$APP_DIR"
  install_files_from_extract "$EXTRACT_DIR" "$WDP1_DIR"
  cleanup_download
  verify_php_setup

  cat <<EOF

============================================================
LINUX/VPS siap
  Paket utama : $APP_DIR
  Salinan wdp1: $WDP1_DIR
  Sumber       : GitHub $GITHUB_REPO@$GITHUB_REF

Yang sudah dikonfigurasi:
  - PHP CLI + ekstensi cURL/JSON
  - Ubuntu 24 AMD64: runtime bundle lokal $RUNTIME_BUNDLE_VERSION
  - timezone Asia/Jakarta + chrony fail-closed
  - paket tersedia di direktori utama dan wdp1

Jalankan:
  cd $WDP1_DIR
  php war.php

Update dari GitHub:
  bash $APP_DIR/install.sh --update
============================================================
EOF
  print_install_success
}

# ----------------------------------------------------------------------
# Update dan clock
# ----------------------------------------------------------------------
do_update_files() {
  log "Mode update PHP dari GitHub ke $APP_DIR dan $WDP1_DIR"
  if [ "$IS_LINUX" -eq 1 ]; then
    linux_prepare_clock 1
  elif [ "$IS_MACOS" -eq 1 ]; then
    macos_prepare_php
  elif [ "$IS_TERMUX" -eq 1 ] && ! php_runtime_ready; then
    install_termux_php
  fi

  download_package
  if [ "$IS_LINUX" -eq 1 ]; then
    linux_wait_clock_health
  fi
  install_files_from_extract "$EXTRACT_DIR" "$APP_DIR"
  install_files_from_extract "$EXTRACT_DIR" "$WDP1_DIR"
  cleanup_download
  verify_php_setup

  cat <<EOF

============================================================
Update PHP selesai
  Paket utama : $APP_DIR
  Salinan wdp1: $WDP1_DIR
  Sumber       : GitHub $GITHUB_REPO@$GITHUB_REF
  Config lokal dipertahankan; pakai --force untuk menggantinya.
============================================================
EOF
  print_install_success
}

do_clock_only() {
  [ "$IS_LINUX" -eq 1 ] || die "--clock-only hanya untuk Linux/VPS"
  linux_prepare_clock
  linux_wait_clock_health
  print_clock_success_marker
}

do_verify_clock() {
  [ "$IS_LINUX" -eq 1 ] || die "--verify-clock hanya untuk Linux/VPS"
  [ "$(date +%z 2>/dev/null || true)" = "+0700" ] \
    || die "Timezone aktif bukan Asia/Jakarta (+0700)"
  linux_wait_clock_health
  print_clock_success_marker
}

# ----------------------------------------------------------------------
# Menu dan main
# ----------------------------------------------------------------------
show_menu() {
  cat <<EOF

============================================================
  WARWDPGO installer PHP
  Platform : $PLATFORM
  Utama    : $APP_DIR
  Salinan  : $WDP1_DIR
  GitHub   : $GITHUB_REPO@$GITHUB_REF
============================================================
  1) Full install PHP
  2) Update war.php + config dari GitHub
  3) Force overwrite config + full install PHP
  4) Setup + verifikasi chrony (Linux/VPS)
  0) Keluar
============================================================
EOF
  printf 'Pilih [1]: '
  local choice
  if [ -r /dev/tty ] && [ -t 0 ] 2>/dev/null; then
    read -r choice < /dev/tty || choice="1"
  else
    read -r choice || choice="1"
  fi
  choice="${choice:-1}"

  case "$choice" in
    1) MODE="auto" ;;
    2) MODE="update" ;;
    3) FORCE_OVERWRITE=1; MODE="auto" ;;
    4) MODE="clock-only" ;;
    0) exit 0 ;;
    *) warn "Pilihan tidak dikenal, pakai full install"; MODE="auto" ;;
  esac
}

main() {
  trap 'on_unexpected_error "$?" "$LINENO"' ERR
  if [ -n "${APP_DIR:-}" ]; then
    APP_DIR_EXPLICIT=1
  fi
  parse_args "$@"
  detect_platform
  case "$PLATFORM" in
    unsupported:*)
      die "Platform tidak didukung: ${PLATFORM#unsupported:}"
      ;;
  esac

  APP_DIR="${APP_DIR:-$(default_app_dir)}"
  APP_DIR="${APP_DIR%/}"
  [ -n "$APP_DIR" ] && [ "$APP_DIR" != "/" ] \
    || die "APP_DIR tidak boleh kosong atau root filesystem"
  WDP1_DIR="$APP_DIR/wdp1"

  log "Platform=$PLATFORM | utama=$APP_DIR | salinan=$WDP1_DIR | mode=$MODE"

  if [ "$MODE" = "menu" ]; then
    show_menu
    if [ "$APP_DIR_EXPLICIT" -eq 0 ]; then
      APP_DIR="$(default_app_dir)"
      APP_DIR="${APP_DIR%/}"
      WDP1_DIR="$APP_DIR/wdp1"
    fi
  fi

  case "$MODE" in
    update)
      do_update_files
      ;;
    clock-only)
      do_clock_only
      ;;
    verify-clock)
      do_verify_clock
      ;;
    auto)
      if [ "$IS_TERMUX" -eq 1 ]; then
        do_install_termux
      elif [ "$IS_MACOS" -eq 1 ]; then
        do_install_macos
      else
        do_install_linux
      fi
      ;;
    *)
      die "Mode internal tidak dikenal: $MODE"
      ;;
  esac
}

if [ -z "${BASH_SOURCE[0]-}" ] || [ "${BASH_SOURCE[0]-}" = "$0" ]; then
  main "$@"
fi
