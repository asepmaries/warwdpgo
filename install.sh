#!/usr/bin/env bash
# ======================================================================
# WARWDPGO installer — auto detect Termux (Android) vs Linux/VPS
#
# Termux  : PHP only di /sdcard/wdp  (tanpa Golang — seperti install awal)
# Linux   : file di $HOME (root → /root), TANPA subfolder wdp
#           + timezone WIB + NTP + Golang (snap) + go mod
#           (PHP CLI sengaja TIDAK di-install di Linux untuk saat ini)
#
# Jalankan:
#   bash install.sh              # full auto sesuai platform
#   bash install.sh --menu       # pilih menu manual
#   bash install.sh --update     # update file saja (tanpa reinstall Go/PHP)
#   bash install.sh --go-only    # Linux: setup Golang + go mod saja
#   APP_DIR=/path bash install.sh
# ======================================================================
set -Eeuo pipefail

# Default: Cloudflare R2 public URL (bukan GitHub). Override: ARCHIVE_URL=... bash install.sh
ARCHIVE_URL="${ARCHIVE_URL:-https://pub-453249fbfe80408a8bb5bf8cce54f391.r2.dev/warwdpgo/warwdpgo.tar.gz}"
GO_MOD_NAME="${GO_MOD_NAME:-wdp-war}"
FASTHTTP_PKG="github.com/valyala/fasthttp"

# File yang selalu di-sync dari paket
# - Termux memakai war.php; war.go/go.mod ikut di-copy tapi tidak dijalankan di Android
# - Linux memakai war.go (+ go.mod/go.sum) dan war.php cadangan
CORE_FILES=(war.go war.php install.sh go.mod go.sum)

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
      --go-only)     MODE="go-only" ;;
      --force|-f)    FORCE_OVERWRITE=1 ;;
      --app-dir)     shift; APP_DIR="${1:-}" ;;
      --help|-h)
        cat <<'EOF'
Usage: bash install.sh [options]

  (default)     Full install otomatis sesuai platform
  --menu, -m    Tampilkan menu pilihan
  --update, -u  Update file dari ARCHIVE_URL saja
  --go-only     Linux only: timezone + NTP + Golang + go mod
  --force, -f   Overwrite config (waktu.txt, user_server_wdp.txt, dll.)
  --app-dir DIR Folder instalasi (default Termux:/sdcard/wdp Linux:\$HOME)
  --help, -h    Bantuan

Env:
  ARCHIVE_URL   URL tarball (default: Cloudflare R2 warwdpgo/warwdpgo.tar.gz)
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

  log "Download paket dari Cloudflare R2 / ARCHIVE_URL"
  printf '    URL: %s\n' "$ARCHIVE_URL"
  curl -fL --retry 3 --retry-delay 2 "$ARCHIVE_URL" -o "$archive_file" \
    || die "Gagal download: $ARCHIVE_URL"

  # R2 archive: warwdpgo/... ; GitHub archive: warwdpgo-main/...
  # Coba strip 1 level dulu; fallback extract flat.
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
  if [ ! -f "$extract_dir/war.php" ] && [ ! -f "$extract_dir/war.go" ] && [ ! -f "$extract_dir/install.sh" ]; then
    die "Tarball tidak berisi file war (war.php / war.go / install.sh)"
  fi

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

# Go resmi ke /usr/local/go — terlihat semua user (root + macbook/ubuntu).
# Lebih andal daripada snap (idcloud sering: snap list kosong / /snap/bin hilang).
GO_OFFICIAL_VERSION="${GO_OFFICIAL_VERSION:-1.22.12}"

linux_install_golang_official() {
  local ver="$GO_OFFICIAL_VERSION"
  local arch tarball url
  case "$(uname -m 2>/dev/null || echo x86_64)" in
    x86_64|amd64) arch=amd64 ;;
    aarch64|arm64) arch=arm64 ;;
    *) arch=amd64 ;;
  esac
  tarball="go${ver}.linux-${arch}.tar.gz"
  url="https://go.dev/dl/${tarball}"

  log "Linux: install Go ${ver} resmi → /usr/local/go"
  need_cmd curl
  curl -fsSL --retry 3 "$url" -o "/tmp/${tarball}" || die "Gagal download $url"
  run_root rm -rf /usr/local/go
  run_root tar -C /usr/local -xzf "/tmp/${tarball}" || die "Gagal extract Go tarball"
  rm -f "/tmp/${tarball}" 2>/dev/null || true
  export PATH="/usr/local/go/bin:/snap/bin:$PATH"
  hash -r 2>/dev/null || true
  command -v go >/dev/null 2>&1 || [ -x /usr/local/go/bin/go ] || die "go tidak ada setelah install resmi"
  # pastikan `go` di PATH
  if ! command -v go >/dev/null 2>&1 && [ -x /usr/local/go/bin/go ]; then
    export PATH="/usr/local/go/bin:$PATH"
  fi
  ok "Go resmi: $(/usr/local/go/bin/go version 2>/dev/null || go version)"
}

linux_install_golang_snap() {
  log "Linux: coba Golang via snap (go --classic)"
  ensure_path_snap
  if command -v systemctl >/dev/null 2>&1; then
    run_root systemctl enable --now snapd.socket 2>/dev/null || true
    run_root systemctl start snapd 2>/dev/null || true
    if [ ! -e /snap ] && [ -d /var/lib/snapd/snap ]; then
      run_root ln -sfn /var/lib/snapd/snap /snap 2>/dev/null || true
    fi
    sleep 2
  fi
  command -v snap >/dev/null 2>&1 || return 1
  local attempt
  for attempt in 1 2 3; do
    if run_root snap install go --classic; then
      ensure_path_snap
      hash -r 2>/dev/null || true
      if command -v go >/dev/null 2>&1 || [ -x /snap/bin/go ]; then
        export PATH="/snap/bin:$PATH"
        ok "Go snap: $(go version 2>/dev/null || /snap/bin/go version)"
        return 0
      fi
    fi
    sleep 3
  done
  return 1
}

linux_install_golang() {
  export PATH="/usr/local/go/bin:/snap/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"

  if command -v go >/dev/null 2>&1 || [ -x /usr/local/go/bin/go ] || [ -x /snap/bin/go ]; then
    export PATH="/usr/local/go/bin:/snap/bin:$PATH"
    ok "Go sudah ada: $(go version 2>/dev/null || /usr/local/go/bin/go version 2>/dev/null || /snap/bin/go version)"
    return 0
  fi

  # Utama: tarball resmi (semua user bisa pakai /usr/local/go/bin/go)
  if linux_install_golang_official; then
    return 0
  fi

  # Cadangan: snap
  if linux_install_golang_snap; then
    return 0
  fi

  die "Gagal install Go (official tarball + snap)"
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

prebuild_war_binary() {
  log "Prebuild war (go mod download + go build) — biar tidak download saat jalan"
  export PATH="/usr/local/go/bin:/snap/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"
  local gobin
  gobin="$(resolve_go_bin)" || die "perintah go tidak ditemukan untuk prebuild"
  ensure_go_workspace
  (
    cd "$APP_DIR"
    "$gobin" mod download || die "go mod download gagal"
    "$gobin" mod tidy || warn "go mod tidy warning (lanjut build)"
    # Binary siap pakai: ./war  (tanpa go run / tanpa jaringan)
    "$gobin" build -o war war.go || die "go build war.go gagal"
    chmod +x war 2>/dev/null || true
  ) || die "Prebuild war gagal"
  if [ -x "$APP_DIR/war" ] || [ -f "$APP_DIR/war" ]; then
    ok "Binary siap: $APP_DIR/war ($(du -h "$APP_DIR/war" 2>/dev/null | awk '{print $1}' || echo '?'))"
    ok "Jalankan: cd $APP_DIR && ./war"
  else
    die "Binary $APP_DIR/war tidak terbentuk"
  fi
}

setup_go_mod() {
  # Linux/VPS only — pastikan go.mod/go.sum + fasthttp siap untuk war.go
  log "Setup Go module (go.mod + fasthttp) di $APP_DIR"
  export PATH="/usr/local/go/bin:/snap/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"
  local gobin
  gobin="$(resolve_go_bin)" || die "perintah go tidak ditemukan"
  mkdir -p "$APP_DIR"
  ensure_go_workspace
  ok "GOPATH=$GOPATH (di luar APP_DIR)"
  ok "go bin: $gobin"
  (
    cd "$APP_DIR"
    if [ ! -f go.mod ]; then
      "$gobin" mod init "$GO_MOD_NAME" 2>/dev/null || "$gobin" mod init "$GO_MOD_NAME"
      ok "go mod init $GO_MOD_NAME"
    else
      ok "go.mod sudah ada (dari paket)"
    fi
    "$gobin" get "$FASTHTTP_PKG" || die "go get $FASTHTTP_PKG gagal (cek jaringan)"
    if [ -f war.go ]; then
      "$gobin" mod tidy || warn "go mod tidy gagal (boleh diulang manual)"
    fi
    "$gobin" list -m all 2>/dev/null | head -n 5 | sed 's/^/    /' || true
  ) || die "Gagal setup go.mod / go get"
  ok "Modul Go siap ($GO_MOD_NAME + $FASTHTTP_PKG)"

  install_go_env_file
  prebuild_war_binary
}

# linux_install_php() — DINONAKTIFKAN sementara (Linux fokus Golang saja).
# Aktifkan lagi di do_install_linux() jika war.php dibutuhkan di VPS.

verify_golang_setup() {
  export PATH="/usr/local/go/bin:/snap/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"
  local gobin
  gobin="$(resolve_go_bin)" || die "Verify Go gagal: binary go tidak ada"
  ok "Verify: $($gobin version) → __WDP_GO_SETUP_OK__"
  if [ -f "$APP_DIR/war" ]; then
    ok "Verify binary: $APP_DIR/war → __WDP_WAR_BIN_OK__"
  else
    warn "Binary ./war belum ada (prebuild gagal?)"
  fi
}

do_setup_golang_only() {
  [ "$IS_LINUX" -eq 1 ] || die "--go-only hanya untuk Linux/VPS (Termux Android pakai PHP saja)"
  linux_apt_base
  linux_set_timezone_ntp
  linux_install_golang
  if [ ! -f "$APP_DIR/war.go" ]; then
    download_package
    install_files_from_extract "$EXTRACT_DIR"
    cleanup_download
  fi
  setup_go_mod
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
  linux_apt_base
  linux_set_timezone_ntp
  # PHP CLI sengaja di-skip (fokus Golang di VPS)
  linux_install_golang

  download_package
  install_files_from_extract "$EXTRACT_DIR"
  cleanup_download

  setup_go_mod
  verify_golang_setup

  cat <<EOF

============================================================
✓ LINUX/VPS siap — WAR Golang di $APP_DIR

Yang sudah dikonfigurasi:
  • apt update + paket dasar (curl, tar, snapd)
  • timezone Asia/Jakarta + NTP
  • snap install go --classic
  • go mod download + go build → binary ./war
  • env permanen: $APP_DIR/wdp-env.sh (+ ~/.bashrc)

Edit config:
  nano $APP_DIR/waktu.txt
  nano $APP_DIR/user_server_wdp.txt

Jalankan (disarankan — TANPA download GitHub):
  cd $APP_DIR
  ./war

Atau (butuh source env dulu):
  . $APP_DIR/wdp-env.sh
  go run war.go

Update file dari Cloudflare R2:
  bash $APP_DIR/install.sh --update
============================================================
EOF
}

# ----------------------------------------------------------------------
# Update-only
# ----------------------------------------------------------------------
do_update_files() {
  log "Mode update: sync file dari ARCHIVE_URL ke $APP_DIR"
  download_package
  install_files_from_extract "$EXTRACT_DIR"
  cleanup_download

  # Linux: refresh go mod bila go tersedia (Termux skip — PHP only)
  if [ "$IS_LINUX" -eq 1 ] && command -v go >/dev/null 2>&1; then
    ensure_path_snap
    (
      cd "$APP_DIR"
      if [ ! -f go.mod ]; then
        go mod init "$GO_MOD_NAME" 2>/dev/null || true
      fi
      if [ -f war.go ] || [ -f go.mod ]; then
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
  2) Update file dari Cloudflare R2 saja
  3) Setup Golang saja (Linux/VPS only)
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
