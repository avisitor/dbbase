#!/bin/bash
#
# Universal test runner wrapper.
# Detects PHPUnit (vendor/bin/phpunit + phpunit.xml[.dist]) or Vitest
# (node_modules/.bin/vitest + vitest.config.*) and runs the existing suite,
# producing a JSON report in the same shape as retree-hawaii / outings.
#
# Usage:
#   ./run-tests.sh                 # quiet run, JSON report only
#   ./run-tests.sh -v              # real-time verbose output
#   ./run-tests.sh --diagnose      # if failures/skipped, evaluate report via opencode
#   ./run-tests.sh --summary-only  # suppress console summary (JSON only)
#   --cleanup[=N]       Remove report files older than N days (default 7) on completion

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_DIR" || exit 1

VERBOSE=0
DIAGNOSE=0
SUMMARY_ONLY=0
FRAMEWORK=""
CLEANUP_DAYS=""

# --- Parse options ---
while [[ $# -gt 0 ]]; do
  case "$1" in
    -v|--verbose) VERBOSE=1; shift;;
    --diagnose)  DIAGNOSE=1; shift;;
    --summary-only) SUMMARY_ONLY=1; shift;;
    --cleanup)
      CLEANUP_DAYS=7
      if [[ $# -gt 1 && "$2" =~ ^[0-9]+$ ]]; then
        CLEANUP_DAYS="$2"
        shift 2
      else
        shift
      fi
      ;;
    --cleanup=*)
      CLEANUP_DAYS="${1#*=}"
      if [[ ! "$CLEANUP_DAYS" =~ ^[0-9]+$ ]]; then
        echo "Error: --cleanup requires a non-negative integer (got '$CLEANUP_DAYS')" >&2
        exit 2
      fi
      shift
      ;;
    -h|--help)
      grep '^#' "$0" | sed 's/^# \{0,1\}//'
      exit 0;;
    *) shift;;
  esac
done

# ─── Prune old report files (--cleanup) ────────────────────────────────────
# If --cleanup was supplied, delete report files (xml/json/txt/html) in the
# reports directory older than CLEANUP_DAYS (default 7) once the run finishes.
# Gated on CLEANUP_DAYS being set; never fails the script.
cleanup_old_reports() {
  [[ -z "${CLEANUP_DAYS:-}" ]] && return 0
  [[ "$CLEANUP_DAYS" =~ ^[0-9]+$ ]] || return 0
  [[ -d "$REPORTS_DIR" ]] || return 0
  echo "🧹 Removing report files older than $CLEANUP_DAYS day(s) from $REPORTS_DIR"
  find "$REPORTS_DIR" -maxdepth 1 -type f \
    \( -name '*.xml' -o -name '*.json' -o -name '*.txt' -o -name '*.html' \) \
    ! -name 'latest.json' \
    -mtime +"$CLEANUP_DAYS" -delete 2>/dev/null || true
}
trap cleanup_old_reports EXIT

# --- Detect framework + config ---
PHPUNIT_BIN="$PROJECT_DIR/vendor/bin/phpunit"
VITEST_BIN="$PROJECT_DIR/node_modules/.bin/vitest"
PHPUNIT_CFG=""
if [ -f "$PROJECT_DIR/phpunit.xml" ]; then PHPUNIT_CFG="$PROJECT_DIR/phpunit.xml"; fi
if [ -z "$PHPUNIT_CFG" ] && [ -f "$PROJECT_DIR/phpunit.xml.dist" ]; then PHPUNIT_CFG="$PROJECT_DIR/phpunit.xml.dist"; fi
if [ -z "$PHPUNIT_CFG" ] && [ -f "$PROJECT_DIR/tests/phpunit.xml" ]; then PHPUNIT_CFG="$PROJECT_DIR/tests/phpunit.xml"; fi

if [ -x "$PHPUNIT_BIN" ] && [ -n "$PHPUNIT_CFG" ]; then
  FRAMEWORK="phpunit"
elif [ -x "$VITEST_BIN" ] && { [ -f "$PROJECT_DIR/vitest.config.ts" ] || [ -f "$PROJECT_DIR/vitest.config.js" ]; }; then
  FRAMEWORK="vitest"
fi

if [ -z "$FRAMEWORK" ]; then
  echo "❌ No supported test framework detected (need vendor/bin/phpunit + phpunit.xml[.dist], or node_modules/.bin/vitest + vitest.config.*)" >&2
  exit 2
fi

echo "🌱 $(basename "$PROJECT_DIR") Test Runner ($FRAMEWORK)"
echo "=========================================="

REPORTS_DIR="$PROJECT_DIR/tests/reports"
mkdir -p "$REPORTS_DIR"
TS=$(date +"%Y%m%d-%H%M%S")
JSON_REPORT="tests/reports/test-report-$TS.json"
RAW="tests/reports/raw-$TS"

# --- Run suite ---
if [ "$FRAMEWORK" = "phpunit" ]; then
  if [ "$VERBOSE" = "1" ]; then
    php -d output_buffering=4096 "$PHPUNIT_BIN" --configuration "$PHPUNIT_CFG" --log-junit "$RAW.xml" --testdox 2>&1 | tee tests/reports/console-$TS.log
    RC=${PIPESTATUS[0]}
  else
    php -d output_buffering=4096 "$PHPUNIT_BIN" --configuration "$PHPUNIT_CFG" --log-junit "$RAW.xml" > tests/reports/console-$TS.log 2>&1
    RC=$?
  fi

  # Convert JUnit XML -> canonical JSON report
  python3 - "$RAW.xml" "$JSON_REPORT" <<'PY'
import sys, json, xml.etree.ElementTree as ET
from datetime import datetime
xmlp, jsonp = sys.argv[1], sys.argv[2]
tree = ET.parse(xmlp); root = tree.getroot()
suites = root.findall('testsuite') if root.tag == 'testsuites' else [root]
def walk(s):
    out = []
    for tc in s.findall('testcase'): out.append(tc)
    for sub in s.findall('testsuite'): out.extend(walk(sub))
    return out
total_a = total_f = total_e = total_s = 0
cases = []; snames = []; skipped_tests = []
for s in suites:
    sname = s.get('name', 'Unknown'); snames.append(sname)
    total_f += int(s.get('failures', 0)); total_e += int(s.get('errors', 0)); total_s += int(s.get('skipped', 0))
    total_a += int(s.get('assertions', 0))
    for tc in walk(s):
        ci = {'name': tc.get('name'), 'class': tc.get('class'), 'suite': sname,
              'assertions': int(tc.get('assertions', 0)), 'time': float(tc.get('time', 0.0)), 'status': 'passed'}
        f = tc.find('failure'); er = tc.find('error'); sk = tc.find('skipped')
        if f is not None:
            ci['status'] = 'failed'; ci['failure_message'] = f.get('message', '')
        elif er is not None:
            ci['status'] = 'error'; ci['error_message'] = er.get('message', '')
        elif sk is not None:
            ci['status'] = 'skipped'
            msg = sk.get('message', '') or (sk.text or '').strip() or 'Skipped'
            ci['skip_message'] = msg
            skipped_tests.append({'name': ci['name'], 'class': ci['class'], 'suite': sname, 'skip_message': msg})
        cases.append(ci)
passed = sum(1 for c in cases if c['status'] == 'passed')
summary = {
    'timestamp': datetime.now().isoformat(),
    'suite_names': snames,
    'total_suites': len(suites),
    'tests': len(cases),
    'assertions': total_a,
    'failures': total_f,
    'errors': total_e,
    'skipped': total_s,
    'time': round(sum(float(s.get('time', 0.0)) for s in suites), 6),
    'success_rate': round(passed / len(cases) * 100, 2) if cases else 0.0,
    'skipped_tests': skipped_tests,
    'test_cases': cases,
}
json.dump(summary, open(jsonp, 'w'), indent=2)
print(f"✅ JSON summary: suites={len(suites)}, tests={len(cases)}, failures={total_f}, errors={total_e}, skipped={total_s}, assertions={total_a}")
PY
  CONV_RC=$?

elif [ "$FRAMEWORK" = "vitest" ]; then
  # Run vitest exactly ONCE. In verbose mode combine the verbose console
  # reporter with the json reporter (writing to a file) so a single execution
  # both streams to the terminal and produces the report — avoids the
  # side-effect bleed you get from running the suite twice.
  if [ "$VERBOSE" = "1" ]; then
    "$VITEST_BIN" run --reporter=verbose --reporter=json --outputFile="$RAW.json" 2>&1 | tee tests/reports/console-$TS.log
    RC=${PIPESTATUS[0]}
  else
    "$VITEST_BIN" run --reporter=json --outputFile="$RAW.json" > tests/reports/console-$TS.log 2>&1
    RC=$?
  fi

  # Convert Vitest JSON -> canonical JSON report
  python3 - "$RAW.json" "$JSON_REPORT" <<'PY'
import sys, json
from datetime import datetime
rawp, jsonp = sys.argv[1], sys.argv[2]
data = json.load(open(rawp))
cases = []; skipped_tests = []; snames = set(); total_dur = 0.0
for suite in data.get('testResults', []):
    sf = suite.get('name', suite.get('file', 'Unknown'))
    snames.add(sf)
    for a in suite.get('assertionResults', []):
        st = a.get('status')
        if st == 'failed': status = 'failed'
        elif st in ('skipped', 'pending', 'todo'): status = 'skipped'
        elif st == 'passed': status = 'passed'
        else: status = st
        dur = (a.get('duration') or 0) / 1000.0
        total_dur += dur
        ci = {'name': a.get('title'), 'class': ' > '.join(a.get('ancestorTitles', [])) or sf,
              'suite': sf, 'assertions': 1, 'time': round(dur, 6), 'status': status}
        if status == 'failed':
            ci['failure_message'] = (a.get('failureMessages') or [''])[0]
        if status == 'skipped':
            msg = (a.get('failureMessages') or ['Skipped'])[0] or 'Skipped'
            ci['skip_message'] = msg
            skipped_tests.append({'name': ci['name'], 'class': ci['class'], 'suite': sf, 'skip_message': msg})
        cases.append(ci)
passed = sum(1 for c in cases if c['status'] == 'passed')
failed = sum(1 for c in cases if c['status'] == 'failed')
errors = sum(1 for c in cases if c['status'] == 'error')
skipped = len(skipped_tests)
summary = {
    'timestamp': datetime.now().isoformat(),
    'suite_names': sorted(snames),
    'total_suites': len(snames),
    'tests': len(cases),
    'assertions': len(cases),
    'failures': failed,
    'errors': errors,
    'skipped': skipped,
    'time': round(total_dur, 6),
    'success_rate': round(passed / len(cases) * 100, 2) if cases else 0.0,
    'skipped_tests': skipped_tests,
    'test_cases': cases,
}
json.dump(summary, open(jsonp, 'w'), indent=2)
print(f"✅ JSON summary: suites={len(snames)}, tests={len(cases)}, failures={failed}, errors={errors}, skipped={skipped}, assertions={len(cases)}")
PY
  CONV_RC=$?
fi

# --- Summary ---
if [ "$SUMMARY_ONLY" != "1" ]; then
  python3 - "$JSON_REPORT" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
passed = d['tests'] - d['failures'] - d['errors']
print("")
print("📊 Test Summary:")
print(f"  Total:    {d['tests']}")
print(f"  Passed:   {passed}")
print(f"  Failed:   {d['failures']}")
print(f"  Errors:   {d['errors']}")
print(f"  Skipped:  {d['skipped']}")
print(f"  Time:     {d['time']:.2f}s")
print(f"  Success:  {d['success_rate']}%")
PY
fi

# --- Latest symlink ---
rm -f tests/reports/latest.json && ln -s "$(basename "$JSON_REPORT")" tests/reports/latest.json
echo "📄 JSON report: $JSON_REPORT"

# --- Diagnose (optional) ---
if [ "$DIAGNOSE" = "1" ]; then
  if command -v jq >/dev/null 2>&1 && jq -e '(.failures//0)>0 or (.errors//0)>0 or (.skipped//0)>0' "$JSON_REPORT" >/dev/null; then
    DIAGNOSE_SH="$(cd "$SCRIPT_DIR" && while [ "$PWD" != "/" ]; do [ -f "diagnose.sh" ] && { echo "$PWD/diagnose.sh"; break; }; cd ..; done)"
    if [ -n "$DIAGNOSE_SH" ] && [ -f "$DIAGNOSE_SH" ]; then
      bash "$DIAGNOSE_SH" "$JSON_REPORT"
    else
      echo "⚠️  Shared diagnose.sh not found (searched up from $SCRIPT_DIR)"
    fi
  else
    echo "✅ All tests passed with no skips — nothing to diagnose."
  fi
fi

# Exit non-zero only if the suite actually failed
if [ "$RC" -ne 0 ] || [ "${CONV_RC:-0}" -ne 0 ]; then
  exit 1
fi
exit 0
