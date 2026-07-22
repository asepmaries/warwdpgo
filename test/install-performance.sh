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
run_root() { root_calls=$((root_calls + 1)); return 0; }
linux_have_ca_bundle() { return 0; }
PATH="$fake_bin:$PATH" linux_apt_base >/dev/null
[ "$root_calls" -eq 0 ]

rm -f "$fake_bin/chronyc"
printf '#!/usr/bin/env bash\nexit 0\n' > "$fake_bin/apt-get"
printf '#!/usr/bin/env bash\nprintf "  Candidate: 1.0\\n"\n' > "$fake_bin/apt-cache"
chmod +x "$fake_bin/apt-get" "$fake_bin/apt-cache"
root_log=""
run_root() { root_log="$root_log $*"; return 0; }
need_cmd() { return 0; }
linux_apt_sources_https() { return 0; }
PATH="$fake_bin:$PATH" linux_apt_base >/dev/null
case "$root_log" in
  *" install "*" chrony"*) ;;
  *) printf 'minimal chrony install command missing: %s\n' "$root_log" >&2; exit 1 ;;
esac
case "$root_log" in
  *" update "*) printf 'APT update must not run with a usable cached candidate\n' >&2; exit 1 ;;
esac
case "$root_log" in
  *" curl"*|*" tar"*|*" ca-certificates"*)
    printf 'already-present packages must not be requested: %s\n' "$root_log" >&2
    exit 1
    ;;
esac

install_calls=0
update_calls=0
root_log=""
run_root() {
  root_log="$root_log $*"
  case " $* " in
    *" install "*)
      install_calls=$((install_calls + 1))
      [ "$install_calls" -gt 1 ]
      ;;
    *" update "*)
      update_calls=$((update_calls + 1))
      return 0
      ;;
    *) return 0 ;;
  esac
}
PATH="$fake_bin:$PATH" linux_apt_base >/dev/null
[ "$install_calls" -eq 2 ]
[ "$update_calls" -eq 1 ]
case "$root_log" in
  *"DPkg::Lock::Timeout=60"*) ;;
  *) printf 'bounded dpkg lock wait missing: %s\n' "$root_log" >&2; exit 1 ;;
esac

TMP_DIR="$fake_bin/release"
mkdir -p "$TMP_DIR"
RELEASE_REPO="example/project"
RELEASE_TAG="v-test"
RELEASE_CHECKSUM_FILE=""
RELEASE_CHECKSUM_TAG=""
checksum_downloads=0
curl_download() {
  checksum_downloads=$((checksum_downloads + 1))
  printf '%064d  asset\n' 0 > "$2"
}
ensure_release_checksums
ensure_release_checksums
[ "$checksum_downloads" -eq 1 ]

grep -Fq 'Acquire::ForceIPv4=true' "$installer"
grep -Fq '__WDP_APT_TRANSIENT__' "$installer"
grep -Fq 'trap cleanup_download EXIT' "$installer"
grep -Fq 'CLOCK_WAIT_TRIES="${CLOCK_WAIT_TRIES:-120}"' "$installer"
grep -Fq 'CLOCK_WAIT_INTERVAL_SEC="${CLOCK_WAIT_INTERVAL_SEC:-1}"' "$installer"

printf 'install performance guards: ok\n'
