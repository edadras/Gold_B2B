#!/usr/bin/env bash
#
# Fails if `double` appears in a financial path.
#
# Money is `int` rial, weight is `int` milligrams, purity is `int`
# ten-thousandths. A single accidental `double` in a repository, a DTO or a
# value object silently reintroduces binary floating point into the middle of
# a gold ledger, and the resulting error is small enough that nobody notices
# until reconciliation fails.
#
# Run from mobile/:  ./tool/no_double_in_money.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."

# Directories where `double` is categorically forbidden.
#
# presentation/ is deliberately NOT guarded: fl_chart takes double pixel
# coordinates, and chart widgets convert from int at that boundary. Everything
# that decides a number — models, DTOs, repositories, controllers — is guarded.
GUARDED=(
  "lib/shared/models"
  "lib/core/formatting"
)
while IFS= read -r d; do GUARDED+=("$d"); done < <(
  find lib/features -mindepth 2 -maxdepth 2 -type d \
       \( -name data -o -name application -o -name domain \) 2>/dev/null | sort
)

# The one sanctioned exception, annotated at its definition.
ALLOWLIST_RE='ALLOW_DOUBLE_DISPLAY_ONLY'

status=0

for dir in "${GUARDED[@]}"; do
  [ -d "$dir" ] || continue

  # Only match the bare type token, not e.g. `doubleTap` or `// double check`.
  hits=$(grep -rnE '(^|[^A-Za-z0-9_])double([^A-Za-z0-9_]|$)' "$dir" --include='*.dart' \
         | grep -v "$ALLOWLIST_RE" \
         | grep -vE '^\s*[^:]+:[0-9]+:\s*//' || true)

  if [ -n "$hits" ]; then
    echo "FAIL: 'double' found in a financial path under $dir:" >&2
    echo "$hits" >&2
    status=1
  fi
done

if [ "$status" -eq 0 ]; then
  echo "OK: no double in any financial path."
fi

exit "$status"
