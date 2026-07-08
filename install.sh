#!/usr/bin/env bash
set -Eeuo pipefail

REPO_URL="https://github.com/asepmaries/warwdpgo.git"
APP_DIR="${HOME}/warwdpgo"

log() {
  printf '\n==> %s\n' "$*"
}

ensure_termux_packages() {
  if command -v pkg >/dev/null 2>&1; then
    log "Install paket Termux"
    pkg update -y
    pkg install -y golang git nano ca-certificates
  else
    log "pkg tidak ditemukan; lewati install paket otomatis"
  fi
}

sync_repo() {
  if [ -d "$APP_DIR/.git" ]; then
    log "Update repo di $APP_DIR"
    git -C "$APP_DIR" pull --ff-only
  else
    log "Clone repo ke $APP_DIR"
    rm -rf "$APP_DIR"
    git clone "$REPO_URL" "$APP_DIR"
  fi
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
sync_repo
prepare_local_files

cat <<'EOF'

============================================================
WAR WDP GO siap.

Masuk folder:
  cd ~/warwdpgo

Isi waktu dan user:
  nano waktu.txt
  nano user_server_wdp.txt

Jalankan:
  go run war.go

Catatan:
  File input sudah tersedia di folder repo.
============================================================
EOF
