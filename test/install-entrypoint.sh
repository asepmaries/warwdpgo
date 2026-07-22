#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
installer="$repo_root/install.sh"

bash -n "$installer"
bash "$installer" --help >/dev/null
cat "$installer" | bash -s -- --help >/dev/null
bash <(cat "$installer") --help >/dev/null
source_output="$(bash -c 'installer_path="$1"; set -- --help; source "$installer_path"; printf SOURCE_ONLY_OK' _ "$installer")"
[ "$source_output" = "SOURCE_ONLY_OK" ]

printf 'install entrypoint modes: ok\n'
