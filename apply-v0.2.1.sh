#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo="${1:-$PWD}"
repo="$(cd -- "$repo" && pwd)"

required=(
  argent-sentinel-wordpress.php composer.json readme.txt README.md
  src/Plugin.php src/Settings/Settings.php src/Http/RequestContext.php
  src/Http/RequestContextFactory.php src/Events/EventRecorder.php
  src/Export/BatchExporter.php src/CLI/Command.php src/Diagnostics/Diagnostics.php
  tests/run.php tests/export-run.php
)
for path in "${required[@]}"; do
  [[ -f "$repo/$path" ]] || { echo "Not an Argent Sentinel v0.2.0 repository; missing $path" >&2; exit 1; }
done

current_version="$(sed -nE "s/^[[:space:]]*public const VERSION[[:space:]]*=[[:space:]]*'([^']+)';/\1/p" "$repo/src/Plugin.php")"
case "$current_version" in
  0.2.0|0.2.1) ;;
  *) echo "Expected plugin runtime version 0.2.0 or 0.2.1; found '${current_version:-missing}'." >&2; exit 1 ;;
esac

if [[ -d "$repo/.git" && "${ALLOW_DIRTY:-0}" != "1" && -n "$(git -C "$repo" status --porcelain)" ]]; then
  echo "Repository has uncommitted changes. Commit/stash them or rerun with ALLOW_DIRTY=1." >&2
  exit 1
fi

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_root="$(dirname "$repo")/wp-argent-sentinel-backups/v0.2.1-$stamp"
mkdir -p "$backup_root"
backup_paths=(
  argent-sentinel-wordpress.php composer.json readme.txt README.md
  src/Plugin.php src/CLI/Command.php src/Diagnostics/Diagnostics.php
  src/Http/RequestContext.php src/Http/RequestContextFactory.php
  src/Events/EventRecorder.php src/Export/BatchExporter.php
  .github/workflows/release.yml
)
for path in "${backup_paths[@]}"; do
  if [[ -e "$repo/$path" ]]; then
    mkdir -p "$backup_root/$(dirname "$path")"
    cp -a "$repo/$path" "$backup_root/$path"
  fi
done

echo "Backup written outside the repository: $backup_root"
install -d "$repo/src/Admin" "$repo/src/Onboarding" "$repo/src/Http" "$repo/src/Events" \
  "$repo/src/Export" "$repo/src/CLI" "$repo/src/Diagnostics" "$repo/tests" "$repo/docs"
cp -a "$script_dir/overlay/src/." "$repo/src/"
cp -a "$script_dir/overlay/tests/onboarding-run.php" "$repo/tests/onboarding-run.php"
cp -a "$script_dir/docs/onboarding-and-request-correlation.md" "$repo/docs/onboarding-and-request-correlation.md"

REPO="$repo" python3 <<'PY'
from __future__ import annotations
import json
import os
import re
from pathlib import Path

repo = Path(os.environ["REPO"])
version = "0.2.1"

path = repo / "argent-sentinel-wordpress.php"
text = path.read_text(encoding="utf-8")
text, count = re.subn(r"(?m)^(\s*\*\s*Version:\s*)\S+", rf"\g<1>{version}", text, count=1)
if count != 1:
    raise SystemExit("Could not update main plugin Version header")
path.write_text(text, encoding="utf-8")

path = repo / "readme.txt"
text = path.read_text(encoding="utf-8")
text, count = re.subn(r"(?m)^Stable tag:\s*\S+", f"Stable tag: {version}", text, count=1)
if count != 1:
    raise SystemExit("Could not update readme stable tag")
if "= 0.2.1 =" not in text:
    marker = "== Changelog =="
    entry = """== Changelog ==

= 0.2.1 =

* Add option-backed setup and diagnostics page without editing wp-config.php.
* Add persistent admin and Site Health warnings for missing or unwritable drop directories.
* Add WP-CLI setup and prebuilt host onboarding commands.
* Capture a trusted Nginx/FastCGI request ID and export it for network-tuple correlation.
* Keep central Sentinel delivery outside WordPress and preserve existing HMAC secrets.
"""
    if marker not in text:
        raise SystemExit("Could not locate readme changelog")
    text = text.replace(marker, entry, 1)
path.write_text(text, encoding="utf-8")

path = repo / "composer.json"
data = json.loads(path.read_text(encoding="utf-8"))
scripts = data.setdefault("scripts", {})
scripts["test"] = "php tests/run.php && php tests/export-run.php && php tests/onboarding-run.php"
path.write_text(json.dumps(data, indent=4, ensure_ascii=False) + "\n", encoding="utf-8")

path = repo / "README.md"
text = path.read_text(encoding="utf-8")
if "## Version 0.2.1 onboarding" not in text:
    section = """
## Version 0.2.1 onboarding

The connector now has an option-backed setup and diagnostics screen, Site Health tests, persistent export-path warnings, and WP-CLI onboarding. It prints the privileged host command needed to create the protected spool; WordPress does not edit `wp-config.php` or contact the central Sentinel service. Nginx can inject `ARGENT_SENTINEL_REQUEST_ID` over FastCGI so exported application events can be correlated with the host's `abuse_context` network tuples.

"""
    marker = "## Security-system boundary"
    text = text.replace(marker, section + marker, 1) if marker in text else text + section
path.write_text(text, encoding="utf-8")

path = repo / ".github/workflows/release.yml"
if path.exists():
    text = path.read_text(encoding="utf-8")
    if "tests/onboarding-run.php" not in text:
        anchor = "php -d error_reporting=E_ALL -d display_errors=1 tests/export-run.php"
        if anchor in text:
            text = text.replace(anchor, anchor + "\n          php -d error_reporting=E_ALL -d display_errors=1 tests/onboarding-run.php", 1)
            path.write_text(text, encoding="utf-8")
PY

find "$repo" -type f -name '*.php' -not -path "$repo/build/*" -print0 | xargs -0 -n1 php -l >/dev/null
php "$repo/tests/run.php"
php "$repo/tests/export-run.php"
php "$repo/tests/onboarding-run.php"
[[ ! -d "$repo/.git" ]] || git -C "$repo" diff --check

echo
echo "Argent Sentinel WordPress Connector v0.2.1 onboarding overlay applied successfully."
echo "Review with: git -C '$repo' diff --stat && git -C '$repo' diff"
echo "Suggested commit: Add guided onboarding and request correlation"
