#!/usr/bin/env bash
set -Eeuo pipefail

ARCHIVE_URL="https://github.com/asepmaries/warwdpgo/archive/refs/heads/main.tar.gz"
APP_DIR="/sdcard/wdp"

log() {
  printf '\n==> %s\n' "$*"
}

ensure_termux_packages() {
  if command -v pkg >/dev/null 2>&1; then
    log "Install paket Termux"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get \
      -o Dpkg::Options::="--force-confold" \
      -o Dpkg::Options::="--force-confdef" \
      install -y openssl libssh2 ca-certificates || true
    apt-get \
      -o Dpkg::Options::="--force-confold" \
      -o Dpkg::Options::="--force-confdef" \
      -y -f install
    apt-get \
      -o Dpkg::Options::="--force-confold" \
      -o Dpkg::Options::="--force-confdef" \
      install -y --reinstall openssl libssh2 curl ca-certificates
    apt-get \
      -o Dpkg::Options::="--force-confold" \
      -o Dpkg::Options::="--force-confdef" \
      install -y tar golang nano python
  else
    log "pkg tidak ditemukan; lewati install paket otomatis"
  fi
}

ensure_sdcard_access() {
  if [ ! -d /sdcard ]; then
    log "/sdcard tidak ditemukan, fallback ke ${HOME}/wdp"
    APP_DIR="${HOME}/wdp"
    return
  fi

  if ! mkdir -p "$APP_DIR" 2>/dev/null; then
    log "Tidak bisa menulis ke /sdcard/wdp"
    if command -v termux-setup-storage >/dev/null 2>&1; then
      printf '%s\n' 'Jika muncul izin storage Android, pilih Allow.'
      termux-setup-storage || true
      sleep 2
    fi
  fi

  if ! mkdir -p "$APP_DIR" 2>/dev/null; then
    log "Storage belum diizinkan, fallback ke ${HOME}/wdp"
    APP_DIR="${HOME}/wdp"
    mkdir -p "$APP_DIR"
  fi
}

sync_repo() {
  log "Download repo ke $APP_DIR"

  tmp_dir="$(mktemp -d)"
  archive_file="${tmp_dir}/warwdpgo.tar.gz"
  extract_dir="${tmp_dir}/extract"
  mkdir -p "$extract_dir"

  download_archive "$ARCHIVE_URL" "$archive_file"
  tar -xzf "$archive_file" -C "$extract_dir" --strip-components=1

  mkdir -p "$APP_DIR"
  cp -f "$extract_dir/war.go" "$APP_DIR/"
  cp -f "$extract_dir/go.mod" "$APP_DIR/"
  cp -f "$extract_dir/go.sum" "$APP_DIR/"
  cp -f "$extract_dir/install.sh" "$APP_DIR/"

  [ -f "$APP_DIR/user_server_wdp.txt" ] || cp -f "$extract_dir/user_server_wdp.txt" "$APP_DIR/"
  [ -f "$APP_DIR/waktu.txt" ] || cp -f "$extract_dir/waktu.txt" "$APP_DIR/"
  [ -f "$APP_DIR/lead.txt" ] || cp -f "$extract_dir/lead.txt" "$APP_DIR/"
  [ -f "$APP_DIR/target_srv.txt" ] || cp -f "$extract_dir/target_srv.txt" "$APP_DIR/"
  [ -f "$APP_DIR/reload.txt" ] || cp -f "$extract_dir/reload.txt" "$APP_DIR/"

  rm -rf "$tmp_dir"
}

download_archive() {
  url="$1"
  output="$2"

  if command -v curl >/dev/null 2>&1 && curl --version >/dev/null 2>&1; then
    curl -fL "$url" -o "$output"
    return
  fi

  if command -v wget >/dev/null 2>&1 && wget --version >/dev/null 2>&1; then
    wget -O "$output" "$url"
    return
  fi

  if command -v python >/dev/null 2>&1; then
    python - "$url" "$output" <<'PY'
import sys
import urllib.request

url, output = sys.argv[1], sys.argv[2]
with urllib.request.urlopen(url) as response, open(output, "wb") as f:
    f.write(response.read())
PY
    return
  fi

  printf '%s\n' "Tidak ada downloader yang bisa dipakai: curl/wget/python gagal." >&2
  exit 1
}

prepare_local_files() {
  cd "$APP_DIR"

  [ -f user_server_wdp.txt ] || : > user_server_wdp.txt
  [ -f waktu.txt ] || : > waktu.txt
  [ -f lead.txt ] || : > lead.txt
  [ -f target_srv.txt ] || : > target_srv.txt
  [ -f reload.txt ] || : > reload.txt

  log "Download dependency Go"
  go mod tidy
}

ensure_termux_packages
ensure_sdcard_access
sync_repo
prepare_local_files

cat <<EOF

============================================================
WAR WDP GO siap.

Masuk folder:
  cd $APP_DIR

Isi waktu dan user:
  nano waktu.txt
  nano user_server_wdp.txt

Jalankan:
  go run war.go

Catatan:
  File input sudah tersedia di folder repo.
  Kalau installer fallback karena izin storage, baca path yang tertulis di log.
============================================================
EOF
