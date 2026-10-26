#!/usr/bin/env bash
# scripts/build-release.sh
# Build and verify the installable plugin ZIP from a committed tree.
#
#   scripts/build-release.sh [--tag vX.Y.Z] [--out DIR] [REF]
#
# Validates that the plugin header Version, readme.txt Stable tag, and
# Plugin::VERSION agree (and match --tag when given), then writes
# DIR/argent-sentinel-wordpress-<version>.zip plus a .sha256 file using
# `git archive` (honouring .gitattributes export-ignore) and fails if any
# development-only path leaks into the archive.
set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN_SLUG='argent-sentinel-wordpress'
tag=''
out='build'
ref='HEAD'

while (($#)); do
  case "$1" in
    --tag) tag="$2"; shift 2 ;;
    --out) out="$2"; shift 2 ;;
    -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
    *) ref="$1"; shift ;;
  esac
done

read_versions() {
  git show "${ref}:$1" | sed -nE "$2"
}

mapfile -t plugin_versions < <(read_versions argent-sentinel-wordpress.php \
  's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*$/\1/p')
mapfile -t readme_versions < <(read_versions readme.txt \
  's/^Stable tag:[[:space:]]*([^[:space:]]+).*$/\1/p')
mapfile -t runtime_versions < <(read_versions src/Plugin.php \
  "s/^[[:space:]]*public const VERSION[[:space:]]*=[[:space:]]*'([^']+)';.*$/\1/p")

if ((${#plugin_versions[@]} != 1 || ${#readme_versions[@]} != 1 || ${#runtime_versions[@]} != 1)); then
  echo 'Expected exactly one version declaration in each release metadata file.' >&2
  exit 1
fi

version="${plugin_versions[0]}"
if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Plugin version '${version}' is not X.Y.Z." >&2
  exit 1
fi
if [[ "${readme_versions[0]}" != "${version}" || "${runtime_versions[0]}" != "${version}" ]]; then
  echo "Version mismatch: header=${version} readme=${readme_versions[0]} Plugin::VERSION=${runtime_versions[0]}" >&2
  exit 1
fi

if [[ -n "${tag}" ]]; then
  if [[ ! "${tag}" =~ ^v([0-9]+\.[0-9]+\.[0-9]+)$ ]]; then
    echo 'Release tags must use v<major>.<minor>.<patch> syntax.' >&2
    exit 1
  fi
  if [[ "${BASH_REMATCH[1]}" != "${version}" ]]; then
    echo "Tag ${tag} does not match plugin version ${version}." >&2
    exit 1
  fi
fi

archive_name="${PLUGIN_SLUG}-${version}.zip"
mkdir -p "${out}"
archive="${out}/${archive_name}"
rm -f "${archive}" "${archive}.sha256"

git archive --format=zip --prefix="${PLUGIN_SLUG}/" --output="${archive}" "${ref}"

unzip -tq "${archive}"
manifest="$(mktemp)"
trap 'rm -f -- "${manifest}"' EXIT
unzip -Z1 "${archive}" > "${manifest}"

grep -qx "${PLUGIN_SLUG}/argent-sentinel-wordpress.php" "${manifest}"
grep -qx "${PLUGIN_SLUG}/src/Plugin.php" "${manifest}"
grep -qx "${PLUGIN_SLUG}/readme.txt" "${manifest}"

if [[ "$(awk -F/ 'NF {print $1}' "${manifest}" | sort -u)" != "${PLUGIN_SLUG}" ]]; then
  echo "Archive must contain a single ${PLUGIN_SLUG}/ top-level directory." >&2
  exit 1
fi

if grep -Eq "^${PLUGIN_SLUG}/(\.github/|\.forgejo/|build/|scripts/|tests/|vendor/|overlay/|composer\.json$|composer\.lock$|phpcs\.xml\.dist$|tests-onboarding-run\.php$|TODO\.md$|\.git)" "${manifest}"; then
  echo "Development-only files leaked into ${archive}:" >&2
  grep -E "^${PLUGIN_SLUG}/(\.github/|\.forgejo/|build/|scripts/|tests/|vendor/|overlay/|composer\.|phpcs|tests-onboarding|TODO|\.git)" "${manifest}" >&2
  exit 1
fi

(cd "${out}" && sha256sum "${archive_name}" > "${archive_name}.sha256" && sha256sum --check "${archive_name}.sha256")

echo "version=${version}"
echo "archive=${archive}"
echo "sha256=$(cut -d' ' -f1 "${archive}.sha256")"
# EOF scripts/build-release.sh
