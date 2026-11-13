#!/bin/sh
# Runs every PHP suite in tests/Unit and tests/Integration, one process each,
# and fails if any suite fails. Used by CI (.github/workflows/tests.yml); run
# it the same way inside a container whose database configuration points at
# a disposable PostgreSQL server, never at the shop.
set -u
cd "$(dirname "$0")/.."

passed=0
failed=""
for suite in tests/Unit/*.php tests/Integration/*.php; do
    if output=$(php "$suite" 2>&1); then
        passed=$((passed + 1))
        echo "ok    $suite"
    else
        failed="$failed $suite"
        echo "FAIL  $suite"
        echo "$output" | tail -n 15 | sed 's/^/      /'
    fi
done

echo "$passed suites passed."
if [ -n "$failed" ]; then
    echo "Failed:$failed"
    exit 1
fi
