#!/usr/bin/env bash
# ======================================================================
# WARWDPGO installer — auto detect Termux (Android) vs Linux/VPS
#
# Termux  : PHP + paket war di /sdcard/wdp  (seperti install lama)
# Linux   : timezone WIB + NTP + PHP CLI + Golang (snap) + go mod + paket war
#           (setara vpswar.php menu 25: Setup Golang + Upload Paket)
#
# Jalankan:
#   bash install.sh              # full auto sesuai platform
#   bash install.sh --menu       # pilih menu manual
#   bash install.sh --update     # update file saja (tanpa reinstall Go/PHP)
#   bash install.sh --go-only    # Linux: setup Golang saja
#   APP_DIR=/path bash install.sh
# ======================================================================
set -Eeuo pipefail

ARCHIVE_URL="${ARCHIVE_URL:-https://github.com/asepmaries/warwdpgo/archive/refs/heads/main.tar.gz}"
GO_MOD_NAME="${GO_MOD_NAME:-wdp-war}"
FASTHTTP_PKG="github.com/valyala/fasthttp"

# File yang selalu di-sync dari repo (script + installer)
CORE_FILES=(war.go war.php install.sh)

# Config: jangan di-overwrite kalau sudah ada isi (kecuali --force)
CONFIG_FILES=(waktu.txt user_server_wdp.txt lead.txt reload.txt target_srv.txt)

FORCE_OVERWRITE=0
MODE="auto" # auto | menu | update | go-only

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

ensure_path_snap() {
  if [ -d /snap/bin ]; then
    case ":$PATH:" in
      *:/snap/bin:*) ;;
      *) export PATH="/snap/bin:$PATH" ;;
    esac
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
    printf '%s' "/sdcard/wdp"
  else
    printf '%s' "${HOME:-/root}/wdp"
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
      --go-only)     MODE="go-only" ;;
      --force|-f)    FORCE_OVERWRITE=1 ;;
      --app-dir)     shift; APP_DIR="${1:-}" ;;
      --help|-h)
        cat <<'EOF'
Usage: bash install.sh [options]

  (default)     Full install otomatis sesuai platform
  --menu, -m    Tampilkan menu pilihan
  --update, -u  Update file dari GitHub saja
  --go-only     Linux only: timezone + NTP + Golang + go mod
  --force, -f   Overwrite config (waktu.txt, user_server_wdp.txt, dll.)
  --app-dir DIR Folder instalasi (default Termux:/sdcard/wdp Linux:~/wdp)
  --help, -h    Bantuan

Env:
  ARCHIVE_URL   URL tarball GitHub (default: warwdpgo main)
  APP_DIR       Sama seperti --app-dir
EOF
        exit 0
        ;;
      *)
        warn "Argumen diabaikan: $1"
        ;;
    esac
    shift
  done
}

# ----------------------------------------------------------------------
# Download & extract paket
# ----------------------------------------------------------------------
download_package() {
  local tmp_dir archive_file extract_dir
  need_cmd curl
  need_cmd tar

  tmp_dir="$(mktemp -d)"
  archive_file="${tmp_dir}/warwdpgo.tar.gz"
  extract_dir="${tmp_dir}/extract"
  mkdir -p "$extract_dir"

  log "Download paket dari GitHub"
  printf '    URL: %s\n' "$ARCHIVE_URL"
  curl -fL --retry 3 --retry-delay 2 "$ARCHIVE_URL" -o "$archive_file" \
    || die "Gagal download: $ARCHIVE_URL"

  tar -xzf "$archive_file" -C "$extract_dir" --strip-components=1 \
    || die "Gagal extract tarball"

  # Ekspor path extract untuk caller (via global)
  EXTRACT_DIR="$extract_dir"
  TMP_DIR="$tmp_dir"
  ok "Paket terunduh & di-extract"
}

cleanup_download() {
  if [ -n "${TMP_DIR:-}" ] && [ -d "${TMP_DIR:-}" ]; then
    rm -rf "$TMP_DIR"
  fi
  TMP_DIR=""
  EXTRACT_DIR=""
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
  local f

  mkdir -p "$APP_DIR"

  log "Pasang file ke $APP_DIR"
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
  log "Termux: update paket + install PHP"
  # Termux pakai apt; noninteractive bila tersedia
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
✓ TERMUX siap — WAR PHP di $APP_DIR

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
linux_set_timezone_ntp() {
  log "Linux: timezone Asia/Jakarta + NTP"
  if command -v timedatectl >/dev/null 2>&1; then
    run_root timedatectl set-timezone Asia/Jakarta || warn "Gagal set timezone"
    run_root timedatectl set-ntp true || warn "Gagal enable NTP"
    timedatectl status 2>/dev/null | sed 's/^/    /' || true
    ok "Timezone + NTP"
  else
    # fallback tanpa systemd
    if [ -f /usr/share/zoneinfo/Asia/Jakarta ]; then
      run_root ln -sf /usr/share/zoneinfo/Asia/Jakarta /etc/localtime || true
      if [ -w /etc/timezone ] || [ "$(id -u)" -eq 0 ]; then
        echo "Asia/Jakarta" | run_root tee /etc/timezone >/dev/null || true
      fi
      ok "Timezone di-set via /etc/localtime (tanpa timedatectl)"
    else
      warn "timedatectl & zoneinfo tidak tersedia — set timezone manual"
    fi
  fi
}

linux_apt_base() {
  log "Linux: apt update + paket dasar"
  if command -v apt-get >/dev/null 2>&1; then
    run_root apt-get update -y
    DEBIAN_FRONTEND=noninteractive run_root apt-get install -y \
      curl tar ca-certificates snapd \
      || die "Gagal apt install paket dasar"
  elif command -v apt >/dev/null 2>&1; then
    run_root apt update -y
    DEBIAN_FRONTEND=noninteractive run_root apt install -y \
      curl tar ca-certificates snapd \
      || die "Gagal apt install paket dasar"
  else
    die "Sistem ini bukan Debian/Ubuntu (butuh apt). Install curl/tar/snapd manual."
  fi
  ok "Paket dasar terpasang"
}

linux_install_golang() {
  log "Linux: install Golang via snap (go --classic)"
  ensure_path_snap

  if command -v go >/dev/null 2>&1; then
    ok "Go sudah ada: $(go version 2>/dev/null || true)"
    return 0
  fi

  # Pastikan snapd jalan
  if command -v systemctl >/dev/null 2>&1; then
    run_root systemctl enable --now snapd.socket 2>/dev/null || true
    run_root systemctl start snapd 2>/dev/null || true
    # symlink klasik /snap pada beberapa distro
    if [ ! -e /snap ] && [ -d /var/lib/snapd/snap ]; then
      run_root ln -sfn /var/lib/snapd/snap /snap 2>/dev/null || true
    fi
    sleep 2
  fi

  if ! command -v snap >/dev/null 2>&1; then
    die "snap tidak tersedia. Install: apt install snapd, lalu jalankan ulang."
  fi

  # Retry snap (kadang butuh waktu setelah enable snapd)
  local attempt
  for attempt in 1 2 3 4 5; do
    if run_root snap install go --classic; then
      break
    fi
    if [ "$attempt" -eq 5 ]; then
      die "Gagal: snap install go --classic"
    fi
    warn "snap install gagal (attempt $attempt/5), tunggu 5s..."
    sleep 5
  done

  ensure_path_snap
  hash -r 2>/dev/null || true

  if ! command -v go >/dev/null 2>&1; then
    # path penuh
    if [ -x /snap/bin/go ]; then
      export PATH="/snap/bin:$PATH"
    else
      die "go terpasang tapi tidak ada di PATH. Tambahkan /snap/bin ke PATH."
    fi
  fi

  go version >/dev/null 2>&1 || die "go version gagal setelah install"
  ok "Go: $(go version)"
}

linux_go_mod_setup() {
  log "Linux: go mod init + get fasthttp di $APP_DIR"
  need_cmd go
  mkdir -p "$APP_DIR"
  (
    cd "$APP_DIR"
    if [ ! -f go.mod ]; then
      go mod init "$GO_MOD_NAME" 2>/dev/null || go mod init "$GO_MOD_NAME"
    fi
    go get "$FASTHTTP_PKG"
    # Kalau war.go sudah ada, tidy biar go.sum rapi
    if [ -f war.go ]; then
      go mod tidy 2>/dev/null || true
    fi
  ) || die "Gagal go mod / go get"
  ok "Modul Go siap ($GO_MOD_NAME + fasthttp)"
}

linux_install_php() {
  # PHP CLI wajib di Linux agar war.php bisa dipakai (selain Go)
  if command -v php >/dev/null 2>&1; then
    ok "PHP sudah ada: $(php -v 2>/dev/null | head -n1)"
    # Pastikan ekstensi curl ada (war.php butuh curl_multi)
    if php -m 2>/dev/null | grep -qi '^curl$'; then
      ok "PHP ext curl: aktif"
      return 0
    fi
    warn "PHP ext curl belum aktif — pasang ulang paket curl"
  fi

  log "Linux: install PHP CLI + curl"
  if command -v apt-get >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive run_root apt-get install -y php-cli php-curl \
      || DEBIAN_FRONTEND=noninteractive run_root apt-get install -y php php-curl \
      || die "Gagal install PHP CLI (php-cli / php-curl)"
  elif command -v apt >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive run_root apt install -y php-cli php-curl \
      || DEBIAN_FRONTEND=noninteractive run_root apt install -y php php-curl \
      || die "Gagal install PHP CLI (php-cli / php-curl)"
  else
    die "Tidak bisa install PHP: apt tidak tersedia"
  fi

  command -v php >/dev/null 2>&1 || die "php tidak ada di PATH setelah install"
  ok "PHP: $(php -v 2>/dev/null | head -n1)"
  if php -m 2>/dev/null | grep -qi '^curl$'; then
    ok "PHP ext curl: aktif"
  else
    warn "PHP ext curl tidak terdeteksi — war.php mungkin gagal (butuh curl)"
  fi
}

verify_golang_setup() {
  ensure_path_snap
  if go version >/dev/null 2>&1; then
    ok "Verify: $(go version) → __WDP_GO_SETUP_OK__"
    return 0
  fi
  die "Verify Go gagal"
}

do_setup_golang_only() {
  [ "$IS_LINUX" -eq 1 ] || die "--go-only hanya untuk Linux/VPS"
  linux_apt_base
  linux_set_timezone_ntp
  linux_install_golang
  linux_go_mod_setup
  verify_golang_setup
  cat <<EOF

============================================================
✓ Golang setup selesai di $APP_DIR

Jalankan war:
  cd $APP_DIR
  go run war.go
============================================================
EOF
}

do_install_linux() {
  linux_apt_base
  linux_set_timezone_ntp
  linux_install_php
  linux_install_golang

  download_package
  install_files_from_extract "$EXTRACT_DIR"
  cleanup_download

  linux_go_mod_setup
  verify_golang_setup

  cat <<EOF

============================================================
✓ LINUX/VPS siap — WAR di $APP_DIR

Yang sudah dikonfigurasi:
  • apt update + paket dasar (curl, tar, snapd)
  • timezone Asia/Jakarta + NTP
  • PHP CLI + php-curl (untuk war.php)
  • snap install go --classic
  • go mod init $GO_MOD_NAME + go get $FASTHTTP_PKG
  • file: war.go, war.php, config txt

Edit config:
  nano $APP_DIR/waktu.txt
  nano $APP_DIR/user_server_wdp.txt
  nano $APP_DIR/lead.txt          # fallback lead (ms), opsional
  nano $APP_DIR/target_srv.txt    # target srv ms, opsional

Jalankan (utama — Golang):
  cd $APP_DIR
  go run war.go

Jalankan (PHP):
  cd $APP_DIR
  php war.php

Update file dari GitHub:
  bash $APP_DIR/install.sh --update

PATH Go (kalau 'go' tidak ketemu):
  export PATH="/snap/bin:\$PATH"
============================================================
EOF
}

# ----------------------------------------------------------------------
# Update-only
# ----------------------------------------------------------------------
do_update_files() {
  log "Mode update: sync file dari GitHub ke $APP_DIR"
  download_package
  install_files_from_extract "$EXTRACT_DIR"
  cleanup_download

  if [ "$IS_LINUX" -eq 1 ] && command -v go >/dev/null 2>&1; then
    ensure_path_snap
    (
      cd "$APP_DIR"
      if [ -f war.go ]; then
        go get "$FASTHTTP_PKG" 2>/dev/null || true
        go mod tidy 2>/dev/null || true
      fi
    ) || true
  fi

  cat <<EOF

============================================================
✓ Update selesai → $APP_DIR
  (config berisi data lokal tidak di-overwrite; pakai --force untuk ganti)
============================================================
EOF
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
  3) Setup Golang saja (Linux/VPS — timezone + NTP + snap go + mod)
  4) Force overwrite config + full install
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
    3) MODE="go-only" ;;
    4) FORCE_OVERWRITE=1; MODE="auto" ;;
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
    auto|*)
      if [ "$IS_TERMUX" -eq 1 ]; then
        do_install_termux
      else
        do_install_linux
      fi
      ;;
  esac

  # Termux: drop ke shell interaktif seperti install lama
  if [ "$IS_TERMUX" -eq 1 ] && [ -r /dev/tty ] && [ -t 0 ] 2>/dev/null; then
    cd "$APP_DIR" 2>/dev/null || true
    exec bash -i < /dev/tty
  fi
}

main "$@"
