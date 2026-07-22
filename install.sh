#!/usr/bin/env bash
# ======================================================================
# WARWDPGO installer — auto detect Termux (Android) vs Linux/VPS
#
# Termux  : PHP only di /sdcard/wdp  (tanpa Golang — seperti install awal)
# Linux   : file di $HOME (root → /root), TANPA subfolder wdp
#           + timezone WIB + chrony sehat + binary GitHub Release terverifikasi
#           (PHP CLI sengaja TIDAK di-install di Linux untuk saat ini)
#
# Jalankan:
#   bash install.sh              # full auto sesuai platform
#   bash install.sh --menu       # pilih menu manual
#   bash install.sh --update     # sync paket + ganti binary secara atomik
#   bash install.sh --go-only    # Linux: build dari source secara eksplisit
#   bash install.sh --clock-only # Linux: pasang/start chrony + tunggu sehat
#   bash install.sh --verify-clock
#   APP_DIR=/path bash install.sh
# ======================================================================
set -Eeuo pipefail

# Default mengambil paket + binary dari GitHub Release yang sama. RELEASE_TAG=latest
# mengikuti release terbaru; pin tag (mis. v2026.07.21) untuk deployment reproducible.
RELEASE_REPO="${RELEASE_REPO:-asepmaries/warwdpgo}"
RELEASE_TAG="${RELEASE_TAG:-latest}"
RELEASE_PACKAGE_ASSET="${RELEASE_PACKAGE_ASSET:-warwdpgo-source.tar.gz}"
RELEASE_CHECKSUM_ASSET="${RELEASE_CHECKSUM_ASSET:-SHA256SUMS}"
RELEASE_BINARY_PREFIX="${RELEASE_BINARY_PREFIX:-war-linux}"

# Override arsip hanya boleh eksplisit dan wajib disertai SHA-256.
ARCHIVE_URL="${ARCHIVE_URL:-}"
ARCHIVE_SHA256="${ARCHIVE_SHA256:-}"

# Policy kesehatan clock. Unit correction/error adalah detik; skew adalah ppm.
CLOCK_WAIT_TRIES="${CLOCK_WAIT_TRIES:-120}"
CLOCK_WAIT_INTERVAL_SEC="${CLOCK_WAIT_INTERVAL_SEC:-1}"
CLOCK_MAX_CORRECTION_SEC="${CLOCK_MAX_CORRECTION_SEC:-0.005}"
CLOCK_MAX_RMS_SEC="${CLOCK_MAX_RMS_SEC:-0.010}"
CLOCK_MAX_SKEW_PPM="${CLOCK_MAX_SKEW_PPM:-100}"
# Selaraskan aggregate bound dengan runtime WAR (50 ms). Offset tetap wajib
# <=5 ms baik pada gate chrony maupun pemeriksaan binary.
CLOCK_MAX_ERROR_SEC="${CLOCK_MAX_ERROR_SEC:-0.050}"
# Binary memberi Chrony budget 30 detik; wrapper harus lebih panjang agar tidak
# membunuh pemeriksaan yang sebenarnya masih menunggu sinkronisasi sehat.
CLOCK_CHECK_TIMEOUT_SEC="${CLOCK_CHECK_TIMEOUT_SEC:-45}"

# File yang selalu di-sync dari paket
# - Termux memakai war.php; war.go/go.mod ikut di-copy tapi tidak dijalankan di Android
# - Linux memakai war.go (+ go.mod/go.sum) dan war.php cadangan
CORE_FILES=(war.php install.sh go.mod go.sum)

# Config: jangan di-overwrite kalau sudah ada isi (kecuali --force)
CONFIG_FILES=(waktu.txt user_server_wdp.txt lead.txt reload.txt target_srv.txt)

FORCE_OVERWRITE=0
MODE="auto" # auto | menu | update | go-only | clock-only | verify-clock
BINARY_MODE="${BINARY_MODE:-release}" # release | source
ALLOW_SOURCE_FALLBACK="${ALLOW_SOURCE_FALLBACK:-0}"
RELEASE_CHECKSUM_FILE=""
RELEASE_CHECKSUM_TAG=""

# ----------------------------------------------------------------------
# Util
# ----------------------------------------------------------------------
log()  { printf '\n==> %s\n' "$*"; }
ok()   { printf '    [OK] %s\n' "$*"; }
warn() { printf '    [!] %s\n' "$*" >&2; }
die()  { printf '\n[ERROR] %s\n' "$*" >&2; exit 1; }

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Command wajib tidak ada: $1"
}

cleanup_paths() {
  local path
  for path in "$@"; do
    [ -z "$path" ] || rm -f -- "$path"
  done
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
  # Jalankan command sebagai root (langsung atau via sudo)
  if [ "$(id -u)" -eq 0 ]; then
    "$@"
  elif command -v sudo >/dev/null 2>&1; then
    sudo "$@"
  else
    die "Butuh root/sudo untuk: $*"
  fi
}

# ----------------------------------------------------------------------
# Deteksi platform
# ----------------------------------------------------------------------
IS_TERMUX=0
IS_LINUX=0
PLATFORM="unknown"

detect_platform() {
  if [ -n "${PREFIX:-}" ] && [ -d "/data/data/com.termux" ] 2>/dev/null; then
    IS_TERMUX=1
    PLATFORM="termux"
  elif [ -n "${TERMUX_VERSION:-}" ] || [ -n "${TERMUX_APK_RELEASE:-}" ]; then
    IS_TERMUX=1
    PLATFORM="termux"
  elif command -v termux-setup-storage >/dev/null 2>&1; then
    IS_TERMUX=1
    PLATFORM="termux"
  elif [ "$(uname -s 2>/dev/null || true)" = "Linux" ]; then
    IS_LINUX=1
    PLATFORM="linux"
  else
    # fallback: anggap Linux-like
    IS_LINUX=1
    PLATFORM="linux"
  fi
}

default_app_dir() {
  if [ "$IS_TERMUX" -eq 1 ]; then
    # Termux/Android: PHP di storage (seperti install awal)
    printf '%s' "/sdcard/wdp"
  else
    # Linux/VPS: langsung di home user (root → /root), tanpa subfolder wdp
    printf '%s' "${HOME:-/root}"
  fi
}

# ----------------------------------------------------------------------
# Parse args
# ----------------------------------------------------------------------
parse_args() {
  while [ $# -gt 0 ]; do
    case "$1" in
      --menu|-m)     MODE="menu" ;;
      --update|-u)   MODE="update" ;;
      --go-only)     MODE="go-only"; BINARY_MODE="source" ;;
      --clock-only)  MODE="clock-only" ;;
      --verify-clock) MODE="verify-clock" ;;
      --build-from-source) BINARY_MODE="source" ;;
      --allow-source-fallback) ALLOW_SOURCE_FALLBACK=1 ;;
      --release-tag)
        [ $# -ge 2 ] || die "--release-tag membutuhkan nilai"
        shift
        RELEASE_TAG="$1"
        ;;
      --force|-f)    FORCE_OVERWRITE=1 ;;
      --app-dir)
        [ $# -ge 2 ] || die "--app-dir membutuhkan DIR"
        shift
        APP_DIR="$1"
        ;;
      --help|-h)
        cat <<'EOF'
Usage: bash install.sh [options]

  (default)               Full install; Linux memakai binary Release terverifikasi
  --menu, -m              Tampilkan menu pilihan
  --update, -u            Sync paket + ganti binary secara atomik
  --go-only               Linux: install Go + build source secara eksplisit
  --clock-only            Linux: install/start chrony lalu tunggu clock sehat
  --verify-clock          Linux: read-only, gagal bila clock tidak sehat
  --build-from-source     Jangan ambil prebuilt; compile war.go
  --allow-source-fallback Jika prebuilt gagal, izinkan compile source
  --release-tag TAG       Pin GitHub Release (default: latest)
  --force, -f             Overwrite config lokal
  --app-dir DIR           Folder instalasi (Termux:/sdcard/wdp Linux:$HOME)
  --help, -h              Bantuan

Env:
  RELEASE_REPO / RELEASE_TAG
  ARCHIVE_URL + ARCHIVE_SHA256  Override paket; keduanya wajib bersama
                               Linux: wajib --build-from-source
  CLOCK_WAIT_TRIES / CLOCK_WAIT_INTERVAL_SEC
  CLOCK_MAX_CORRECTION_SEC / CLOCK_MAX_RMS_SEC / CLOCK_MAX_SKEW_PPM / CLOCK_MAX_ERROR_SEC
  CLOCK_CHECK_TIMEOUT_SEC
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
# Download & extract paket
# ----------------------------------------------------------------------
validate_release_settings() {
  printf '%s\n' "$RELEASE_REPO" \
    | grep -Eq '^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$' \
    || die "RELEASE_REPO tidak valid: $RELEASE_REPO"
  if [ "$RELEASE_TAG" != "latest" ]; then
    printf '%s\n' "$RELEASE_TAG" \
      | grep -Eq '^[A-Za-z0-9._-]+$' \
      || die "RELEASE_TAG tidak valid: $RELEASE_TAG"
  fi
  printf '%s\n' "$RELEASE_PACKAGE_ASSET" \
    | grep -Eq '^[A-Za-z0-9._-]+$' \
    || die "RELEASE_PACKAGE_ASSET tidak valid"
  printf '%s\n' "$RELEASE_CHECKSUM_ASSET" \
    | grep -Eq '^[A-Za-z0-9._-]+$' \
    || die "RELEASE_CHECKSUM_ASSET tidak valid"
}

release_download_base() {
  if [ "$RELEASE_TAG" = "latest" ]; then
    printf 'https://github.com/%s/releases/latest/download' "$RELEASE_REPO"
  else
    printf 'https://github.com/%s/releases/download/%s' "$RELEASE_REPO" "$RELEASE_TAG"
  fi
}

resolve_latest_release_tag() {
  local release_url effective tag
  [ "$RELEASE_TAG" = "latest" ] || return 0
  release_url="https://github.com/$RELEASE_REPO/releases/latest"
  effective="$(curl --fail --location --silent --show-error \
    --retry 3 --retry-delay 1 --connect-timeout 15 --max-time 180 \
    --proto '=https' --tlsv1.2 \
    --output /dev/null --write-out '%{url_effective}' "$release_url")" \
    || die "Gagal resolve GitHub Release latest"
  effective="${effective%/}"
  case "$effective" in
    "https://github.com/$RELEASE_REPO/releases/tag/"*) ;;
    *) die "Redirect Release latest tidak valid: $effective" ;;
  esac
  tag="${effective##*/}"
  printf '%s\n' "$tag" | grep -Eq '^[A-Za-z0-9._-]+$' \
    || die "Tag hasil resolve tidak valid: $tag"
  RELEASE_TAG="$tag"
  ok "Release latest dipin untuk proses ini: $RELEASE_TAG"
}

curl_download() {
  local url="$1" dest="$2"
  case "$url" in
    https://*) ;;
    *) warn "Tolak URL non-HTTPS: $url"; return 1 ;;
  esac
  curl --fail --location --silent --show-error \
    --retry 3 --retry-delay 1 --connect-timeout 15 --max-time 180 \
    --proto '=https' --tlsv1.2 \
    "$url" -o "$dest"
}

ensure_release_checksums() {
  local base sums
  if [ -n "$RELEASE_CHECKSUM_FILE" ] \
    && [ "$RELEASE_CHECKSUM_TAG" = "$RELEASE_TAG" ] \
    && [ -s "$RELEASE_CHECKSUM_FILE" ]; then
    return 0
  fi

  base="$(release_download_base)"
  sums="${TMP_DIR:-${TMPDIR:-/tmp}}/SHA256SUMS.$$"
  rm -f "$sums"
  if ! curl_download "$base/$RELEASE_CHECKSUM_ASSET" "$sums"; then
    warn "Gagal download checksum Release: $base/$RELEASE_CHECKSUM_ASSET"
    rm -f "$sums"
    return 1
  fi
  RELEASE_CHECKSUM_FILE="$sums"
  RELEASE_CHECKSUM_TAG="$RELEASE_TAG"
}

verify_file_sha256() {
  local file="$1" expected="$2" actual
  is_sha256 "$expected" || return 1
  actual="$(sha256sum "$file" | awk '{print $1}')" || return 1
  [ "$(printf '%s' "$actual" | tr '[:upper:]' '[:lower:]')" = \
    "$(printf '%s' "$expected" | tr '[:upper:]' '[:lower:]')" ]
}

download_verified_release_asset() {
  local asset="$1" dest="$2"
  local base sums expected matches count download_status
  VERIFIED_ASSET_SHA256=""
  base="$(release_download_base)"
  rm -f "$dest"

  ensure_release_checksums || return 1
  sums="$RELEASE_CHECKSUM_FILE"

  matches="$(awk -v asset="$asset" '
    $2 == asset || $2 == "*" asset { print $1 }
  ' "$sums")"
  count="$(printf '%s\n' "$matches" | sed '/^$/d' | wc -l | tr -d ' ')"
  if [ "$count" = "0" ]; then
    warn "Checksum asset tidak ditemukan: $asset"
    return 1
  fi
  if [ "$count" != "1" ]; then
    warn "Checksum asset duplikat: $asset"
    return 2
  fi
  expected="$(printf '%s\n' "$matches" | sed -n '1p')"
  if ! is_sha256 "$expected"; then
    warn "Format checksum invalid untuk asset: $asset"
    return 2
  fi

  if curl_download "$base/$asset" "$dest"; then
    download_status=0
  else
    download_status=$?
  fi
  if [ "$download_status" -ne 0 ]; then
    warn "Gagal download asset Release: $asset"
    rm -f "$dest"
    return 1
  fi
  if ! verify_file_sha256 "$dest" "$expected"; then
    warn "SHA-256 TIDAK COCOK untuk asset: $asset"
    rm -f "$dest"
    return 2
  fi

  VERIFIED_ASSET_SHA256="$(printf '%s' "$expected" | tr '[:upper:]' '[:lower:]')"
  return 0
}

download_package() {
  local tmp_dir archive_file extract_dir required
  need_cmd curl
  need_cmd tar
  need_cmd sha256sum

  if [ -z "$ARCHIVE_URL" ] && [ -n "$ARCHIVE_SHA256" ]; then
    die "ARCHIVE_SHA256 tanpa ARCHIVE_URL tidak boleh diabaikan"
  fi
  if [ -n "$ARCHIVE_URL" ] && [ "$IS_LINUX" -eq 1 ] \
    && [ "$BINARY_MODE" != "source" ]; then
    die "ARCHIVE_URL kustom di Linux wajib dipakai bersama --build-from-source"
  fi

  tmp_dir="$(mktemp -d)"
  TMP_DIR="$tmp_dir"
  RELEASE_CHECKSUM_FILE=""
  RELEASE_CHECKSUM_TAG=""
  trap cleanup_download EXIT
  archive_file="${tmp_dir}/warwdpgo.tar.gz"
  extract_dir="${tmp_dir}/extract"
  mkdir -p "$extract_dir"

  if [ -n "$ARCHIVE_URL" ]; then
    log "Download paket dari ARCHIVE_URL eksplisit"
    is_sha256 "$ARCHIVE_SHA256" \
      || die "ARCHIVE_URL wajib disertai ARCHIVE_SHA256 64-digit"
    printf '    URL: %s\n' "$ARCHIVE_URL"
    curl_download "$ARCHIVE_URL" "$archive_file" \
      || die "Gagal download ARCHIVE_URL"
    verify_file_sha256 "$archive_file" "$ARCHIVE_SHA256" \
      || die "SHA-256 ARCHIVE_URL tidak cocok"
  else
    validate_release_settings
    resolve_latest_release_tag
    log "Download paket GitHub Release + verifikasi SHA-256"
    printf '    Release: %s @ %s\n' "$RELEASE_REPO" "$RELEASE_TAG"
    download_verified_release_asset "$RELEASE_PACKAGE_ASSET" "$archive_file" \
      || die "Gagal download/verifikasi $RELEASE_PACKAGE_ASSET"
  fi

  # Release bundle punya satu top-level directory. Override kustom boleh flat.
  if ! tar -xzf "$archive_file" -C "$extract_dir" --strip-components=1 2>/dev/null; then
    tar -xzf "$archive_file" -C "$extract_dir" || die "Gagal extract tarball"
  fi
  # Kalau strip menghasilkan folder kosong tapi ada subdir, ambil isinya
  if [ ! -f "$extract_dir/war.php" ] && [ ! -f "$extract_dir/war.go" ]; then
    sub="$(find "$extract_dir" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | head -n1 || true)"
    if [ -n "$sub" ] && [ -d "$sub" ]; then
      # pindahkan isi subdir ke extract_dir
      shopt -s dotglob 2>/dev/null || true
      mv "$sub"/* "$extract_dir"/ 2>/dev/null || true
      rmdir "$sub" 2>/dev/null || true
    fi
  fi
  for required in war.go war.php install.sh go.mod go.sum; do
    [ -f "$extract_dir/$required" ] \
      || die "Tarball tidak lengkap; file wajib hilang: $required"
  done

  # Ekspor path extract untuk caller (via global)
  EXTRACT_DIR="$extract_dir"
  TMP_DIR="$tmp_dir"
  ok "Paket terunduh & di-extract"
}

cleanup_download() {
  trap - EXIT
  if [ -n "${TMP_DIR:-}" ] && [ -d "${TMP_DIR:-}" ]; then
    rm -rf "$TMP_DIR"
  fi
  TMP_DIR=""
  EXTRACT_DIR=""
  RELEASE_CHECKSUM_FILE=""
  RELEASE_CHECKSUM_TAG=""
}

copy_file_smart() {
  # copy_file_smart SRC DEST is_config
  local src="$1" dest="$2" is_config="${3:-0}"
  local base
  base="$(basename "$dest")"

  if [ ! -f "$src" ]; then
    warn "Skip (tidak ada di paket): $base"
    return 0
  fi

  if [ "$is_config" = "1" ] && [ -f "$dest" ] && [ "$FORCE_OVERWRITE" -eq 0 ]; then
    # Pertahankan config lokal yang sudah ada isinya
    if [ -s "$dest" ]; then
      ok "Pertahankan config lokal: $base"
      return 0
    fi
  fi

  cp -f "$src" "$dest"
  ok "Copy $base"
}

install_files_from_extract() {
  local extract_dir="$1"
  local f src

  mkdir -p "$APP_DIR"

  log "Pasang file ke $APP_DIR"
  # Semua file Go ikut agar build package "." konsisten dengan binary Release.
  for src in "$extract_dir"/*.go; do
    [ -f "$src" ] || continue
    f="$(basename "$src")"
    copy_file_smart "$src" "$APP_DIR/$f" 0
  done
  for f in "${CORE_FILES[@]}"; do
    copy_file_smart "$extract_dir/$f" "$APP_DIR/$f" 0
  done
  for f in "${CONFIG_FILES[@]}"; do
    copy_file_smart "$extract_dir/$f" "$APP_DIR/$f" 1
  done

  # Pastikan config kosong tetap dibuat kalau belum ada
  for f in "${CONFIG_FILES[@]}"; do
    if [ ! -f "$APP_DIR/$f" ]; then
      : > "$APP_DIR/$f"
      ok "Buat kosong: $f"
    fi
  done

  chmod +x "$APP_DIR/install.sh" 2>/dev/null || true
}

# ----------------------------------------------------------------------
# TERMUX
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
  log "Termux: update paket + install PHP (tanpa Golang)"
  if command -v apt >/dev/null 2>&1; then
    apt update
    DEBIAN_FRONTEND=noninteractive apt -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" upgrade || true
    apt install -y php curl tar || die "Gagal install php di Termux"
  else
    pkg update -y
    pkg install -y php curl tar || die "Gagal install php (pkg)"
  fi
  ok "PHP: $(php -v 2>/dev/null | head -n1 || echo unknown)"
}

do_install_termux() {
  setup_termux_storage
  install_termux_php
  download_package
  install_files_from_extract "$EXTRACT_DIR"
  cleanup_download

  cat <<EOF

============================================================
✓ TERMUX/Android siap — WAR PHP di $APP_DIR

Yang terpasang:
  • PHP + curl + tar
  • war.php + config (Golang tidak dipakai di Android)

Edit config:
  nano $APP_DIR/waktu.txt
  nano $APP_DIR/user_server_wdp.txt

Jalankan:
  cd $APP_DIR
  php war.php

Update file nanti:
  bash $APP_DIR/install.sh --update
============================================================
EOF
}

# ----------------------------------------------------------------------
# LINUX / VPS (setara vpswar.php menu 25)
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
  done < <(find /etc/apt -maxdepth 2 -type f \( -name '*.list' -o -name '*.sources' \) -print 2>/dev/null)
  [ "$changed" -eq 0 ] || ok "Source resmi Ubuntu memakai HTTPS"
}

linux_have_ca_bundle() {
  [ -s /etc/ssl/certs/ca-certificates.crt ] \
    || [ -s /etc/pki/tls/certs/ca-bundle.crt ] \
    || [ -s /etc/ssl/cert.pem ]
}

linux_apt_base() {
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
    || die "CA bundle HTTPS tidak tersedia; bootstrap aman tidak dapat dilanjutkan"

  command -v curl >/dev/null 2>&1 || packages+=(curl)
  command -v tar >/dev/null 2>&1 || packages+=(tar)
  command -v chronyc >/dev/null 2>&1 || packages+=(chrony)
  if ! command -v sha256sum >/dev/null 2>&1 \
    || ! command -v timeout >/dev/null 2>&1; then
    packages+=(coreutils)
  fi
  if [ "${#packages[@]}" -eq 0 ]; then
    log "Linux: paket dasar + chrony sudah tersedia (skip APT)"
    ok "Fast path dependency aktif"
    return 0
  fi

  if command -v apt-get >/dev/null 2>&1; then
    apt_cmd=(apt-get)
  elif command -v apt >/dev/null 2>&1; then
    apt_cmd=(apt)
  else
    die "Sistem ini bukan Debian/Ubuntu apt. Installer tidak menebak package manager/provider."
  fi

  log "Linux: install dependency yang belum ada: ${packages[*]}"
  linux_apt_sources_https

  if command -v apt-cache >/dev/null 2>&1; then
    for package in "${packages[@]}"; do
      candidate="$(LC_ALL=C apt-cache policy "$package" 2>/dev/null | awk '/Candidate:/ { print $2; exit }')"
      if [ -z "$candidate" ] || [ "$candidate" = "(none)" ]; then
        need_update=1
        break
      fi
    done
  else
    need_update=1
  fi

  if [ "$need_update" -eq 1 ]; then
    run_root "${apt_cmd[@]}" "${apt_opts[@]}" update \
      || warn "Sebagian repository APT gagal diperbarui; validasi kandidat paket wajib tetap dijalankan"
    for package in "${packages[@]}"; do
      candidate="$(LC_ALL=C apt-cache policy "$package" 2>/dev/null | awk '/Candidate:/ { print $2; exit }')"
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
      || warn "Sebagian repository APT gagal diperbarui; lanjut hanya bila paket wajib tersedia"
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
  ok "Paket dasar + chrony terpasang"
}

linux_start_chrony() {
  local unit started=0
  log "Linux: enable + start chrony"

  if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
    if systemctl cat systemd-timesyncd.service >/dev/null 2>&1; then
      run_root systemctl disable --now systemd-timesyncd.service >/dev/null 2>&1 \
        || warn "systemd-timesyncd tidak dapat dinonaktifkan; cek konflik NTP bila gate gagal"
    fi
    for unit in chrony.service chronyd.service; do
      if systemctl cat "$unit" >/dev/null 2>&1; then
        run_root systemctl enable --now "$unit" \
          || die "Gagal enable/start $unit"
        started=1
        break
      fi
    done
  elif command -v service >/dev/null 2>&1; then
    if run_root service chrony start >/dev/null 2>&1; then
      started=1
    elif run_root service chronyd start >/dev/null 2>&1; then
      started=1
    fi
  fi

  [ "$started" -eq 1 ] \
    || die "Service chrony/chronyd tidak dapat dimulai; init system tidak didukung"
  ok "Daemon chrony aktif"

  # VPS baru sering baru memiliki satu sampel NTP sehingga skew/dispersion
  # sementara sangat besar. Pastikan source online, izinkan step hanya pada
  # bootstrap, lalu ambil burst sampel. Gate ketat di bawah tetap penentu akhir.
  run_root chronyc -a online >/dev/null 2>&1 \
    || warn "Chrony online awal tidak tersedia; lanjut menunggu sinkronisasi normal"
  run_root chronyc -a makestep 0.1 1 >/dev/null 2>&1 \
    || warn "Chrony makestep bootstrap tidak tersedia; lanjut menunggu sinkronisasi normal"
  run_root chronyc -a burst 4/4 >/dev/null 2>&1 \
    || warn "Chrony burst awal tidak tersedia; lanjut menunggu sinkronisasi normal"
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
      if (!numeric($5) || !numeric($7) || !numeric($10) || !numeric($11) || !numeric($12)) {
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
  need_cmd chronyc
  need_cmd awk
  require_positive_integer "CLOCK_WAIT_TRIES" "$CLOCK_WAIT_TRIES"
  require_positive_integer "CLOCK_WAIT_INTERVAL_SEC" "$CLOCK_WAIT_INTERVAL_SEC"
  require_nonnegative_number "CLOCK_MAX_CORRECTION_SEC" "$CLOCK_MAX_CORRECTION_SEC"
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
    printf '%s\n' "__WDP_CLOCK_UNHEALTHY__" >&2
    die "chronyc waitsync gagal/timeout"
  fi

  if ! tracking="$(LC_ALL=C chronyc -c tracking 2>&1)"; then
    printf '%s\n' "$tracking" | sed 's/^/    /' >&2
    printf '%s\n' "__WDP_CLOCK_UNHEALTHY__" >&2
    die "chronyc tracking gagal"
  fi
  if ! printf '%s\n' "$tracking" | clock_tracking_is_healthy; then
    LC_ALL=C chronyc -n tracking 2>&1 | sed 's/^/    /' >&2 || true
    printf '%s\n' "__WDP_CLOCK_UNHEALTHY__" >&2
    die "Metrik chrony di luar policy"
  fi

  printf '%s\n' "__WDP_CLOCK_HEALTHY__"
  ok "Chrony sinkron; correction/error/skew di dalam batas"
}

linux_prepare_clock() {
  linux_apt_base
  linux_set_timezone
  linux_start_chrony
}

# Go resmi ke /usr/local/go — terlihat semua user (root + macbook/ubuntu).
# Lebih andal daripada snap (idcloud sering: snap list kosong / /snap/bin hilang).
GO_OFFICIAL_VERSION="${GO_OFFICIAL_VERSION:-1.26.5}"
GO_OFFICIAL_SHA256="${GO_OFFICIAL_SHA256:-}"

official_go_checksum() {
  local ver="$1" arch="$2"
  if [ -n "$GO_OFFICIAL_SHA256" ]; then
    is_sha256 "$GO_OFFICIAL_SHA256" || die "GO_OFFICIAL_SHA256 wajib 64-digit hex"
    printf '%s' "$GO_OFFICIAL_SHA256"
    return 0
  fi
  case "$ver/$arch" in
    1.26.5/amd64)
      printf '%s' "5c2c3b16caefa1d968a94c1daca04a7ca301a496d9b086e17ad77bb81393f053"
      ;;
    1.26.5/arm64)
      printf '%s' "fe4789e92b1f33358680864bbe8704289e7bb5fc207d80623c308935bd696d49"
      ;;
    *)
      die "GO_OFFICIAL_VERSION=$ver tidak punya checksum bawaan; set GO_OFFICIAL_SHA256"
      ;;
  esac
}

linux_install_golang_official() {
  local ver="$GO_OFFICIAL_VERSION"
  local arch tarball url expected downloaded stage backup
  case "$(uname -m 2>/dev/null || true)" in
    x86_64|amd64) arch=amd64 ;;
    aarch64|arm64) arch=arm64 ;;
    *) die "Arsitektur Go tidak didukung: $(uname -m 2>/dev/null || echo unknown)" ;;
  esac
  tarball="go${ver}.linux-${arch}.tar.gz"
  url="https://go.dev/dl/${tarball}"
  expected="$(official_go_checksum "$ver" "$arch")"
  downloaded="$(mktemp "${TMPDIR:-/tmp}/wdp-go.XXXXXX.tar.gz")" \
    || die "Tidak bisa membuat file sementara Go"
  stage="/usr/local/.wdp-go-stage.$$"
  backup="/usr/local/.wdp-go-backup.$$"

  log "Linux: install Go ${ver} resmi terverifikasi → /usr/local/go"
  need_cmd curl
  need_cmd sha256sum
  if ! curl_download "$url" "$downloaded"; then
    rm -f "$downloaded"
    die "Gagal download $url"
  fi
  if ! verify_file_sha256 "$downloaded" "$expected"; then
    rm -f "$downloaded"
    die "SHA-256 tarball Go tidak cocok"
  fi
  tar -tzf "$downloaded" go/bin/go >/dev/null 2>&1 \
    || { rm -f "$downloaded"; die "Isi tarball Go tidak valid"; }

  run_root rm -rf "$stage" "$backup"
  run_root mkdir -p "$stage"
  if ! run_root tar -C "$stage" -xzf "$downloaded"; then
    rm -f "$downloaded"
    run_root rm -rf "$stage"
    die "Gagal extract Go tarball"
  fi
  rm -f "$downloaded"
  [ -x "$stage/go/bin/go" ] || {
    run_root rm -rf "$stage"
    die "Binary go tidak ada di hasil extract"
  }

  if [ -e /usr/local/go ]; then
    run_root mv /usr/local/go "$backup" || {
      run_root rm -rf "$stage"
      die "Gagal menyiapkan upgrade /usr/local/go"
    }
  fi
  if ! run_root mv "$stage/go" /usr/local/go; then
    [ ! -e "$backup" ] || run_root mv "$backup" /usr/local/go || true
    run_root rm -rf "$stage"
    die "Gagal memasang /usr/local/go"
  fi
  run_root rm -rf "$stage" "$backup"

  export PATH="/usr/local/go/bin:$PATH"
  hash -r 2>/dev/null || true
  [ -x /usr/local/go/bin/go ] || die "go tidak ada setelah install resmi"
  ok "Go resmi: $(/usr/local/go/bin/go version 2>/dev/null || go version)"
}

required_go_version() {
  awk '$1 == "go" { print $2; exit }' "$APP_DIR/go.mod"
}

go_version_at_least() {
  local actual="$1" required="$2" first
  actual="${actual#go}"
  first="$(printf '%s\n%s\n' "$required" "$actual" | sort -V | head -n1)"
  [ "$first" = "$required" ]
}

linux_install_golang() {
  export PATH="/usr/local/go/bin:/snap/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"
  local gobin actual required
  required="$(required_go_version)"
  [ -n "$required" ] || die "Versi Go tidak ditemukan di $APP_DIR/go.mod"

  if gobin="$(resolve_go_bin 2>/dev/null)"; then
    actual="$("$gobin" env GOVERSION 2>/dev/null || true)"
    if [ -n "$actual" ] && go_version_at_least "$actual" "$required"; then
      ok "Go sudah memenuhi go.mod: $actual (minimum $required)"
      return 0
    fi
    warn "Go ${actual:-unknown} lebih lama dari kebutuhan $required; upgrade resmi"
  fi

  linux_install_golang_official
}

# GOPATH/GOMODCACHE HARUS di luar APP_DIR (folder yang berisi go.mod).
# Kalau APP_DIR=/root dan GOPATH default=/root/go, go mod tidy mengira
# cache adalah package modul → error "import path should not have @version".
# Sama untuk APP_DIR=/home/ubuntu → jangan pakai /home/ubuntu/go.
ensure_go_workspace() {
  local uid
  uid="$(id -u 2>/dev/null || echo 0)"
  export GOPATH="${WDP_GOPATH:-/var/tmp/wdp-gopath-${uid}}"
  export GOMODCACHE="${WDP_GOMODCACHE:-$GOPATH/pkg/mod}"
  export GOCACHE="${WDP_GOCACHE:-$GOPATH/cache}"
  mkdir -p "$GOMODCACHE" "$GOCACHE" 2>/dev/null || true
  # Bersihkan cache salah tempat di dalam APP_DIR (dari install lama)
  if [ -n "${APP_DIR:-}" ] && [ -d "$APP_DIR/go/pkg/mod" ]; then
    warn "Menghapus GOPATH salah di $APP_DIR/go (di dalam modul)"
    rm -rf "$APP_DIR/go" 2>/dev/null || true
  fi
}

# Simpan env Go permanen supaya `go run` user tidak download ulang ke ~/go kosong
install_go_env_file() {
  ensure_go_workspace
  local env_file="$APP_DIR/wdp-env.sh"
  cat > "$env_file" <<EOF
# Auto-generated by warwdpgo install.sh
# source ini ATAU cukup jalankan: ./war
export PATH="/usr/local/go/bin:/snap/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:\${PATH}"
export GOPATH="${GOPATH}"
export GOMODCACHE="${GOMODCACHE}"
export GOCACHE="${GOCACHE}"
export GO111MODULE=on
EOF
  chmod 644 "$env_file" 2>/dev/null || true
  ok "Env Go: $env_file"

  local rc line
  line="[ -f \"$env_file\" ] && . \"$env_file\""
  for rc in "${HOME}/.bashrc" "${HOME}/.profile"; do
    if [ -w "$(dirname "$rc")" ] 2>/dev/null || [ -w "$HOME" ] 2>/dev/null; then
      if [ -f "$rc" ] && grep -qF 'wdp-env.sh' "$rc" 2>/dev/null; then
        continue
      fi
      printf '\n# WDP war Go workspace\n%s\n' "$line" >> "$rc" 2>/dev/null || true
    fi
  done
}

# Download SEMUA dependency + compile binary → jalan tanpa unduh GitHub lagi
resolve_go_bin() {
  if command -v go >/dev/null 2>&1; then
    command -v go
  elif [ -x /usr/local/go/bin/go ]; then
    echo /usr/local/go/bin/go
  elif [ -x /snap/bin/go ]; then
    echo /snap/bin/go
  else
    return 1
  fi
}

linux_release_arch() {
  case "$(uname -m 2>/dev/null || true)" in
    x86_64|amd64) printf '%s' "amd64" ;;
    aarch64|arm64) printf '%s' "arm64" ;;
    *) return 1 ;;
  esac
}

try_install_prebuilt_war_binary() {
  # Return: 1=asset/arch unavailable, 2=integrity failure, 3=local commit failure.
  local arch asset app_dir_abs staged expected manifest_tmp download_status
  local binary_backup="" manifest_backup="" had_binary=0 had_manifest=0
  arch="$(linux_release_arch)" || {
    warn "Arsitektur prebuilt tidak didukung: $(uname -m 2>/dev/null || echo unknown)"
    return 1
  }
  validate_release_settings
  resolve_latest_release_tag
  printf '%s\n' "$RELEASE_BINARY_PREFIX" \
    | grep -Eq '^[A-Za-z0-9._-]+$' \
    || die "RELEASE_BINARY_PREFIX tidak valid"

  mkdir -p "$APP_DIR" || return 3
  app_dir_abs="$(cd "$APP_DIR" && pwd -P)" || return 3
  asset="${RELEASE_BINARY_PREFIX}-${arch}"
  staged="$(mktemp "$app_dir_abs/.war.release.XXXXXX")" || {
    warn "Tidak bisa membuat staging binary di $app_dir_abs"
    return 3
  }

  log "Install prebuilt $asset dari GitHub Release"
  if download_verified_release_asset "$asset" "$staged"; then
    download_status=0
  else
    download_status=$?
  fi
  if [ "$download_status" -ne 0 ]; then
    rm -f "$staged"
    return "$download_status"
  fi
  expected="$VERIFIED_ASSET_SHA256"
  if [ ! -s "$staged" ] || ! verify_file_sha256 "$staged" "$expected"; then
    warn "Binary staging kosong atau berubah setelah verifikasi"
    rm -f "$staged"
    return 2
  fi
  chmod 0755 "$staged" || {
    rm -f "$staged"
    return 3
  }

  manifest_tmp="$(mktemp "$app_dir_abs/.wdp-war-release.XXXXXX")" \
    || { rm -f "$staged"; return 3; }
  if ! {
    printf 'repository=%s\n' "$RELEASE_REPO"
    printf 'release_tag=%s\n' "$RELEASE_TAG"
    printf 'asset=%s\n' "$asset"
    printf 'sha256=%s\n' "$expected"
  } > "$manifest_tmp"; then
    rm -f "$staged" "$manifest_tmp"
    return 3
  fi
  chmod 0644 "$manifest_tmp" || {
    rm -f "$staged" "$manifest_tmp"
    return 3
  }

  # Siapkan rollback sebelum commit. Staging dan backup tetap di filesystem APP_DIR.
  if [ -e "$app_dir_abs/war" ]; then
    binary_backup="$(mktemp "$app_dir_abs/.war.backup.XXXXXX")" || {
      rm -f "$staged" "$manifest_tmp"
      return 3
    }
    cp -p -- "$app_dir_abs/war" "$binary_backup" || {
      cleanup_paths "$staged" "$manifest_tmp" "$binary_backup"
      return 3
    }
    had_binary=1
  fi
  if [ -e "$app_dir_abs/.wdp-war-release" ]; then
    manifest_backup="$(mktemp "$app_dir_abs/.wdp-war-release.backup.XXXXXX")" || {
      cleanup_paths "$staged" "$manifest_tmp" "$binary_backup"
      return 3
    }
    cp -p -- "$app_dir_abs/.wdp-war-release" "$manifest_backup" || {
      cleanup_paths "$staged" "$manifest_tmp" "$binary_backup" "$manifest_backup"
      return 3
    }
    had_manifest=1
  fi

  # Binary replacement adalah satu rename atomik pada filesystem yang sama.
  if ! mv -f -- "$staged" "$app_dir_abs/war"; then
    cleanup_paths "$staged" "$manifest_tmp" "$binary_backup" "$manifest_backup"
    return 3
  fi
  if ! mv -f -- "$manifest_tmp" "$app_dir_abs/.wdp-war-release" \
    || ! verify_file_sha256 "$app_dir_abs/war" "$expected"; then
    if [ "$had_binary" -eq 1 ]; then
      mv -f -- "$binary_backup" "$app_dir_abs/war" || true
    else
      rm -f "$app_dir_abs/war"
    fi
    if [ "$had_manifest" -eq 1 ]; then
      mv -f -- "$manifest_backup" "$app_dir_abs/.wdp-war-release" || true
    else
      rm -f "$app_dir_abs/.wdp-war-release"
    fi
    cleanup_paths "$staged" "$manifest_tmp" "$binary_backup" "$manifest_backup"
    return 3
  fi
  cleanup_paths "$binary_backup" "$manifest_backup"

  printf '%s\n' "__WDP_WAR_BIN_OK__"
  ok "Binary Release terverifikasi dan terpasang atomik: $app_dir_abs/war"
}

prebuild_war_binary() {
  local source_dir="$1"
  log "Prebuild war dari source terverifikasi"
  export PATH="/usr/local/go/bin:/snap/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"
  local gobin app_dir_abs source_dir_abs staged
  gobin="$(resolve_go_bin)" || die "perintah go tidak ditemukan untuk prebuild"
  ensure_go_workspace
  app_dir_abs="$(cd "$APP_DIR" && pwd -P)" \
    || die "APP_DIR tidak bisa diakses: $APP_DIR"
  source_dir_abs="$(cd "$source_dir" && pwd -P)" \
    || die "Source staging tidak bisa diakses: $source_dir"
  staged="$(mktemp "$app_dir_abs/.war.build.XXXXXX")" \
    || die "Tidak bisa membuat staging build"
  rm -f "$staged"

  if ! (
    cd "$source_dir_abs" || exit 1
    GOFLAGS="-mod=readonly" GOTOOLCHAIN=local "$gobin" mod download || exit 1
    GOFLAGS="-mod=readonly" GOTOOLCHAIN=local "$gobin" mod verify || exit 1
    CGO_ENABLED=0 GOFLAGS="-mod=readonly" GOTOOLCHAIN=local \
      "$gobin" build -trimpath -o "$staged" . || exit 1
  ); then
    rm -f "$staged"
    die "Build source gagal; binary lama tidak diubah"
  fi
  [ -s "$staged" ] || {
    rm -f "$staged"
    die "Build source menghasilkan binary kosong"
  }
  chmod 0755 "$staged"
  mv -f -- "$staged" "$app_dir_abs/war" \
    || { rm -f "$staged"; die "Gagal mengganti binary secara atomik"; }
  rm -f "$app_dir_abs/.wdp-war-release"

  printf '%s\n' "__WDP_WAR_BIN_OK__"
  ok "Binary source siap dan terpasang atomik: $app_dir_abs/war"
}

setup_go_mod() {
  local source_dir="$1"
  # Source build sengaja fail-closed: release wajib membawa lockfile utuh.
  log "Verifikasi Go module terkunci di source staging"
  export PATH="/usr/local/go/bin:/snap/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"
  local gobin
  gobin="$(resolve_go_bin)" || die "perintah go tidak ditemukan"
  mkdir -p "$APP_DIR"
  ensure_go_workspace
  [ -f "$source_dir/go.mod" ] || die "Paket source tidak berisi go.mod"
  [ -f "$source_dir/go.sum" ] || die "Paket source tidak berisi go.sum"
  [ -f "$source_dir/war.go" ] || die "Paket source tidak berisi war.go"
  ok "GOPATH=$GOPATH (di luar APP_DIR)"
  ok "go bin: $gobin"

  install_go_env_file
  prebuild_war_binary "$source_dir"
  ok "go.mod + go.sum terverifikasi tanpa go get/tidy"
}

# linux_install_php() — DINONAKTIFKAN sementara (Linux fokus Golang saja).
# Aktifkan lagi di do_install_linux() jika war.php dibutuhkan di VPS.

install_linux_war_binary() {
  local source_dir="$1" prebuilt_status
  case "$BINARY_MODE" in
    release)
      if try_install_prebuilt_war_binary; then
        INSTALLED_BINARY_KIND="release"
        return 0
      else
        prebuilt_status=$?
      fi
      case "$prebuilt_status" in
        1)
          case "$ALLOW_SOURCE_FALLBACK" in
            1)
              warn "Prebuilt tidak tersedia; source fallback diizinkan eksplisit"
              ;;
            0)
              die "Prebuilt tidak tersedia. Ulangi dengan --allow-source-fallback atau --build-from-source"
              ;;
            *)
              die "ALLOW_SOURCE_FALLBACK hanya boleh 0 atau 1"
              ;;
          esac
          ;;
        2)
          die "Verifikasi integritas prebuilt gagal; source fallback sengaja diblokir"
          ;;
        *)
          die "Commit prebuilt gagal secara lokal; binary lama dipertahankan bila ada"
          ;;
      esac
      ;;
    source)
      ;;
    *)
      die "BINARY_MODE tidak valid: $BINARY_MODE (pilih release atau source)"
      ;;
  esac

  linux_install_golang
  setup_go_mod "$source_dir"
  INSTALLED_BINARY_KIND="source"
}

verify_linux_setup() {
  local manifest="$APP_DIR/.wdp-war-release" expected installed_sha clock_output
  local clock_offset_ms clock_rms_ms clock_bound_ms
  local runtime_clock_offset_ms runtime_clock_rms_ms runtime_clock_bound_ms
  [ -s "$APP_DIR/war" ] || die "Verify binary gagal: $APP_DIR/war kosong/tidak ada"
  [ -x "$APP_DIR/war" ] || die "Verify binary gagal: $APP_DIR/war tidak executable"
  installed_sha="$(sha256sum "$APP_DIR/war" | awk '{print $1}')" \
    || die "Tidak bisa menghitung checksum binary terpasang"
  is_sha256 "$installed_sha" || die "Checksum binary terpasang invalid"

  if [ "${INSTALLED_BINARY_KIND:-}" = "release" ] && [ ! -f "$manifest" ]; then
    die "Manifest wajib untuk binary Release"
  fi
  if [ -f "$manifest" ]; then
    expected="$(awk -F= '$1 == "sha256" { print $2 }' "$manifest")"
    is_sha256 "$expected" || die "Manifest Release punya checksum invalid"
    verify_file_sha256 "$APP_DIR/war" "$expected" \
      || die "Checksum binary terpasang tidak cocok dengan manifest"
  fi

  printf '%s\n' "__WDP_WAR_BIN_OK__"
  ok "Verify binary hash: $installed_sha"
  need_cmd timeout
  require_positive_integer "CLOCK_CHECK_TIMEOUT_SEC" "$CLOCK_CHECK_TIMEOUT_SEC"
  clock_offset_ms="$(awk -v seconds="$CLOCK_MAX_CORRECTION_SEC" 'BEGIN { printf "%.6f", seconds * 1000 }')"
  clock_rms_ms="$(awk -v seconds="$CLOCK_MAX_RMS_SEC" 'BEGIN { printf "%.6f", seconds * 1000 }')"
  clock_bound_ms="$(awk -v seconds="$CLOCK_MAX_ERROR_SEC" 'BEGIN { printf "%.6f", seconds * 1000 }')"
  runtime_clock_offset_ms="${WDP_MAX_CLOCK_OFFSET_MS:-$clock_offset_ms}"
  runtime_clock_rms_ms="${WDP_MAX_CLOCK_RMS_MS:-$clock_rms_ms}"
  runtime_clock_bound_ms="${WDP_MAX_CLOCK_BOUND_MS:-${WDP_MAX_CLOCK_ERROR_MS:-$clock_bound_ms}}"
  if ! clock_output="$(
    cd "$APP_DIR" \
      && env WDP_MAX_CLOCK_OFFSET_MS="$runtime_clock_offset_ms" \
        WDP_MAX_CLOCK_RMS_MS="$runtime_clock_rms_ms" \
        WDP_MAX_CLOCK_BOUND_MS="$runtime_clock_bound_ms" \
        timeout --signal=TERM --kill-after=5s "${CLOCK_CHECK_TIMEOUT_SEC}s" \
        ./war --check-clock 2>&1
  )"; then
    printf '%s\n' "$clock_output" | sed 's/^/    /' >&2
    die "Runtime clock check gagal: war --check-clock"
  fi
  [ -z "$clock_output" ] || printf '%s\n' "$clock_output" | sed 's/^/    /'

  # Marker agregat hanya muncul setelah hash/manifest dan runtime clock gate lulus.
  printf '%s\n' "__WDP_INSTALL_OK__"
}

verify_golang_setup() {
  export PATH="/usr/local/go/bin:/snap/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"
  local gobin
  gobin="$(resolve_go_bin)" || die "Verify Go gagal: binary go tidak ada"
  ok "Verify: $($gobin version) → __WDP_GO_SETUP_OK__"
  verify_linux_setup
}

do_setup_golang_only() {
  [ "$IS_LINUX" -eq 1 ] || die "--go-only hanya untuk Linux/VPS (Termux Android pakai PHP saja)"
  BINARY_MODE="source"
  linux_prepare_clock
  download_package
  linux_wait_clock_health
  install_files_from_extract "$EXTRACT_DIR"
  linux_install_golang
  setup_go_mod "$EXTRACT_DIR"
  cleanup_download
  verify_golang_setup
  cat <<EOF

============================================================
✓ Golang setup selesai di $APP_DIR

Jalankan war (langsung, tanpa download):
  cd $APP_DIR
  ./war
============================================================
EOF
}

do_install_linux() {
  linux_prepare_clock
  download_package
  linux_wait_clock_health
  install_files_from_extract "$EXTRACT_DIR"

  install_linux_war_binary "$EXTRACT_DIR"
  cleanup_download
  verify_linux_setup

  cat <<EOF

============================================================
✓ LINUX/VPS siap — WAR Golang di $APP_DIR

Yang sudah dikonfigurasi:
  • timezone Asia/Jakarta + chrony fail-closed
  • binary ${INSTALLED_BINARY_KIND:-unknown} terverifikasi dan diganti atomik
  • source build hanya bila diminta eksplisit

Edit config:
  nano $APP_DIR/waktu.txt
  nano $APP_DIR/user_server_wdp.txt

Jalankan (disarankan — TANPA download GitHub):
  cd $APP_DIR
  ./war

Update dari GitHub Release:
  bash $APP_DIR/install.sh --update
============================================================
EOF
}

# ----------------------------------------------------------------------
# Update-only
# ----------------------------------------------------------------------
do_update_files() {
  log "Mode update: sync paket Release ke $APP_DIR"
  if [ "$IS_LINUX" -eq 1 ]; then
    linux_prepare_clock
  fi
  download_package

  if [ "$IS_LINUX" -eq 1 ]; then
    linux_wait_clock_health
  fi
  install_files_from_extract "$EXTRACT_DIR"

  if [ "$IS_LINUX" -eq 1 ]; then
    install_linux_war_binary "$EXTRACT_DIR"
  fi
  cleanup_download

  if [ "$IS_LINUX" -eq 1 ]; then
    verify_linux_setup
  fi

  cat <<EOF

============================================================
✓ Update selesai → $APP_DIR
  Binary Linux: ${INSTALLED_BINARY_KIND:-tidak berlaku}
  (config berisi data lokal tidak di-overwrite; pakai --force untuk ganti)
============================================================
EOF
}

do_clock_only() {
  [ "$IS_LINUX" -eq 1 ] || die "--clock-only hanya untuk Linux/VPS"
  linux_prepare_clock
  linux_wait_clock_health
}

do_verify_clock() {
  [ "$IS_LINUX" -eq 1 ] || die "--verify-clock hanya untuk Linux/VPS"
  [ "$(date +%z 2>/dev/null || true)" = "+0700" ] \
    || die "Timezone aktif bukan Asia/Jakarta (+0700)"
  linux_wait_clock_health
}

# ----------------------------------------------------------------------
# Menu interaktif
# ----------------------------------------------------------------------
show_menu() {
  cat <<EOF

============================================================
  WARWDPGO installer
  Platform : $PLATFORM
  APP_DIR  : $APP_DIR
============================================================
  1) Full install otomatis (disarankan)
  2) Update file dari GitHub saja
  3) Setup Golang saja (Linux/VPS only)
  4) Force overwrite config + full install
  5) Setup + verifikasi chrony saja (Linux/VPS only)
  0) Keluar
============================================================
EOF
  printf 'Pilih [1]: '
  local choice
  if [ -r /dev/tty ]; then
    read -r choice < /dev/tty || choice="1"
  else
    read -r choice || choice="1"
  fi
  choice="${choice:-1}"

  case "$choice" in
    1) MODE="auto" ;;
    2) MODE="update" ;;
    3) MODE="go-only"; BINARY_MODE="source" ;;
    4) FORCE_OVERWRITE=1; MODE="auto" ;;
    5) MODE="clock-only" ;;
    0) exit 0 ;;
    *) warn "Pilihan tidak dikenal, pakai full install"; MODE="auto" ;;
  esac
}

# ----------------------------------------------------------------------
# Main
# ----------------------------------------------------------------------
main() {
  parse_args "$@"
  detect_platform

  APP_DIR="${APP_DIR:-$(default_app_dir)}"
  # Normalisasi trailing slash
  APP_DIR="${APP_DIR%/}"

  log "Deteksi platform: $PLATFORM | APP_DIR=$APP_DIR | mode=$MODE"

  if [ "$MODE" = "menu" ]; then
    show_menu
  fi

  case "$MODE" in
    update)
      do_update_files
      ;;
    go-only)
      do_setup_golang_only
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
      else
        do_install_linux
      fi
      ;;
    *)
      die "Mode internal tidak dikenal: $MODE"
      ;;
  esac

  # Termux: drop ke shell interaktif seperti install lama
  if [ "$IS_TERMUX" -eq 1 ] && [ -r /dev/tty ] && [ -t 0 ] 2>/dev/null; then
    cd "$APP_DIR" 2>/dev/null || true
    exec bash -i < /dev/tty
  fi
}

if [ -z "${BASH_SOURCE[0]-}" ] || [ "${BASH_SOURCE[0]-}" = "$0" ]; then
  main "$@"
fi
