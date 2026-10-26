#!/usr/bin/env bash
# scripts/ci-check.sh
# Dependency-free validation shared by Forgejo CI and the release workflow:
# PHP syntax, all five test runners (including the Access Guard handoff), and
# the repository whitespace gate. Needs only php and git; no composer install.
set -euo pipefail

cd "$(dirname "$0")/.."

php -v | head -n 1

echo '== PHP syntax =='
find . -type f -name '*.php' \
  -not -path './.git/*' \
  -not -path './build/*' \
  -not -path './vendor/*' \
  -print0 | sort -z | xargs -0 -n 1 php -l

echo '== Dependency-free tests =='
# Keep in sync with the composer.json "test" script.
tests=(
  tests/run.php
  tests/export-run.php
  tests/onboarding-run.php
  tests/admin-run.php
  tests/access-guard-handoff-run.php
)
for t in "${tests[@]}"; do
  echo "-- ${t}"
  php -d error_reporting=E_ALL -d display_errors=1 "${t}"
done

echo '== Whitespace gate =='
git diff --check
if git rev-parse --verify --quiet HEAD^ >/dev/null; then
  git diff --check HEAD^ HEAD
fi

echo 'CI_CHECK=PASS'
# EOF scripts/ci-check.sh
