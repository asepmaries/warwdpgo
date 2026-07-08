#!/usr/bin/env bash
set -Eeuo pipefail

ARCHIVE_URL="https://github.com/asepmaries/warwdpgo/archive/refs/heads/main.tar.gz"
APP_DIR="/sdcard/wdp"

log() {
  printf '\n==> %s\n' "$*"
}

log "Update dan upgrade Termux"
apt update -y && apt upgrade -y

log "Install PHP"
pkg install php -y

if [ ! -d /sdcard ]; then
  log "Aktifkan izin storage Termux"
  termux-setup-storage || true
  sleep 2
fi

log "Siapkan folder $APP_DIR"
mkdir -p "$APP_DIR"

tmp_dir="$(mktemp -d)"
archive_file="${tmp_dir}/warwdpgo.tar.gz"
extract_dir="${tmp_dir}/extract"
mkdir -p "$extract_dir"

log "Download file pendukung"
curl -fL "$ARCHIVE_URL" -o "$archive_file"
tar -xzf "$archive_file" -C "$extract_dir" --strip-components=1

cp -f "$extract_dir/war.php" "$APP_DIR/"
cp -f "$extract_dir/install.sh" "$APP_DIR/"
cp -f "$extract_dir/reload.txt" "$APP_DIR/"
cp -f "$extract_dir/lead.txt" "$APP_DIR/"
cp -f "$extract_dir/target_srv.txt" "$APP_DIR/"
cp -f "$extract_dir/waktu.txt" "$APP_DIR/"
cp -f "$extract_dir/user_server_wdp.txt" "$APP_DIR/"

rm -rf "$tmp_dir"

cd "$APP_DIR"

cat <<EOF

============================================================
WAR PHP siap di $APP_DIR

Edit:
  nano waktu.txt
  nano user_server_wdp.txt

Jalankan:
  php war.php
============================================================
EOF

if [ -r /dev/tty ]; then
  exec bash -i < /dev/tty
fi
