#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
installer="$repo_root/install.sh"

source "$installer"

fake_bin="$(mktemp -d)"
trap 'rm -rf "$fake_bin"' EXIT

printf '#!/usr/bin/env bash\nexit 0\n' > "$fake_bin/chronyc"
chmod +x "$fake_bin/chronyc"

root_calls=0
run_root() {
  root_calls=$((root_calls + 1))
  return 0
}
linux_have_ca_bundle() { return 0; }
php_runtime_ready() { return 0; }
PATH="$fake_bin:$PATH" linux_apt_base 1 >/dev/null
[ "$root_calls" -eq 0 ]

rm -f "$fake_bin/chronyc"
printf '#!/usr/bin/env bash\nexit 0\n' > "$fake_bin/apt-get"
printf '#!/usr/bin/env bash\nprintf "  Candidate: 1.0\\n"\n' \
  > "$fake_bin/apt-cache"
chmod +x "$fake_bin/apt-get" "$fake_bin/apt-cache"

php_runtime_ready() { return 1; }
need_cmd() { return 0; }
linux_apt_sources_https() { return 0; }
root_log=""
run_root() {
  root_log="$root_log $*"
  return 0
}
PATH="$fake_bin:$PATH" linux_apt_base 1 >/dev/null
case "$root_log" in
  *" install "*" php-cli"*" php-curl"*) ;;
  *)
    printf 'PHP dependency install command missing: %s\n' "$root_log" >&2
    exit 1
    ;;
esac
case "$root_log" in
  *"DPkg::Lock::Timeout=60"*) ;;
  *)
    printf 'bounded dpkg lock wait missing: %s\n' "$root_log" >&2
    exit 1
    ;;
esac

GITHUB_REPO="example/project"
GITHUB_REF="feature/php"
[ "$(github_archive_url)" = \
  "https://github.com/example/project/archive/feature/php.tar.gz" ]

grep -Fq 'Acquire::ForceIPv4=true' "$installer"
grep -Fq '__WDP_APT_TRANSIENT__' "$installer"
grep -Fq 'trap cleanup_download EXIT' "$installer"
grep -Fq 'CLOCK_WAIT_TRIES="${CLOCK_WAIT_TRIES:-120}"' "$installer"
grep -Fq 'CLOCK_WAIT_INTERVAL_SEC="${CLOCK_WAIT_INTERVAL_SEC:-1}"' "$installer"

printf 'install performance guards: ok\n'
