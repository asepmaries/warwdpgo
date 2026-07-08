#!/usr/bin/env bash
set -Eeuo pipefail

cd "$(dirname "$0")"

printf 'Waktu target sekarang: '
cat waktu.txt 2>/dev/null || true
printf '\n'

read -r -p 'Masukkan waktu target (HH:MM atau HH:MM:SS): ' target_time
if [ -n "${target_time}" ]; then
  printf '%s\n' "$target_time" > waktu.txt
fi

printf '\nFormat user_server_wdp.txt: satu baris satu user, format USER_ID|SERVER_ID\n'
printf 'Editor akan dibuka. Simpan dengan CTRL+O ENTER, keluar CTRL+X.\n'
sleep 1
nano user_server_wdp.txt

if [ ! -s lead.txt ]; then
  printf -- '-80\n' > lead.txt
fi

if [ ! -s target_srv.txt ]; then
  printf '5\n' > target_srv.txt
fi

printf '\nSelesai. Jalankan:\n'
printf '  go run war.go\n'
