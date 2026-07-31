#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
installer="$repo_root/install.sh"

source "$installer"

fake_bin="$(mktemp -d)"
trap 'rm -rf "$fake_bin"' EXIT

cat > "$fake_bin/chronyc" <<'EOF'
#!/usr/bin/env bash
case "${CLOCK_TEST_CASE:-}" in
  waitsync)
    exit 1
    ;;
  tracking)
    case " $* " in
      *" waitsync "*) exit 0 ;;
      *) exit 1 ;;
    esac
    ;;
  policy)
    case " $* " in
      *" waitsync "*) exit 0 ;;
      *" -c tracking "*) printf 'invalid\n'; exit 0 ;;
      *) exit 0 ;;
    esac
    ;;
  *)
    exit 2
    ;;
esac
EOF
chmod +x "$fake_bin/chronyc"

assert_unhealthy_is_final() {
  local test_case="$1" expected_error="$2" output last_line
  if output="$(
    PATH="$fake_bin:$PATH" \
      CLOCK_TEST_CASE="$test_case" \
      CLOCK_WAIT_TRIES=1 \
      CLOCK_WAIT_INTERVAL_SEC=1 \
      linux_wait_clock_health 2>&1
  )"; then
    printf 'clock case %s unexpectedly succeeded\n' "$test_case" >&2
    exit 1
  fi

  printf '%s\n' "$output" | grep -Fq "[ERROR] $expected_error"
  last_line="$(printf '%s\n' "$output" | awk 'NF { line=$0 } END { print line }')"
  if [ "$last_line" != "__WDP_CLOCK_UNHEALTHY__" ]; then
    printf 'clock case %s final marker invalid: %s\n' "$test_case" "$last_line" >&2
    exit 1
  fi
}

assert_unhealthy_is_final waitsync "chronyc waitsync gagal/timeout"
assert_unhealthy_is_final tracking "chronyc tracking gagal"
assert_unhealthy_is_final policy "Metrik chrony di luar policy"

healthy_tracking='05DF32CF,ntp.example,3,0,0.000001,0.000002,0.000003,1.0,0.1,20.0,0.004,0.002,1.0,Normal'
printf '%s\n' "$healthy_tracking" | clock_tracking_is_healthy \
  || { printf 'healthy chrony CSV rejected\n' >&2; exit 1; }

printf 'install clock failure marker ordering: ok\n'
