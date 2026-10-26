# Argent Sentinel WordPress Connector v0.2.1 overlay

Apply this overlay to an existing v0.2.0 plugin repository:

```bash
./apply-v0.2.1.sh /path/to/wp-argent-sentinel
```

The installer writes a backup outside the repository, refuses a dirty Git tree
unless `ALLOW_DIRTY=1`, copies the new classes, updates release metadata, runs
PHP syntax checks and all dependency-free tests, and finishes with `git diff
--check`.

Normal setup remains in WordPress options. Existing constants still override
options, and the HMAC secret is preserved. No network connection to a central
Sentinel service is made by WordPress.

## Version 0.2.2 setup and status

Version 0.2.2 promotes the previously staged onboarding implementation into
the active plugin source tree. It registers `wp argent-sentinel setup`,
`status`, `export`, `prune`, and `onboarding-command`.

Administrators can open **Settings → Argent Sentinel** for:

- an overall ready/needs-attention result;
- queued-event, last-export, and schedule summaries;
- option-backed Site ID, node ID, and protected drop-directory setup;
- owner, group, mode, PHP-user, schema, HMAC, and request-ID diagnostics;
- a copyable privileged host provisioning command;
- a nonce-protected manual export test.

The plugin also provides direct WordPress Site Health tests and a non-secret
debug-information section. WordPress remains an unprivileged event producer:
the host agent manages the protected spool, central collection, CrowdSec, and
abuse reporting.

## Version 0.2.3 onboarding hardening

Version 0.2.3 uses the package-owned
`/usr/sbin/argent-sentinel-onboard-wordpress` helper and includes
`--open-basedir-mode prompt` in generated commands. The administrator page and
Site Health distinguish a missing spool from PHP-FPM `open_basedir` blocking
access, show the effective and suggested values, and direct the operator back
through the privileged helper.

## Version 0.2.4 helper visibility

Version 0.2.4 treats host-helper availability as tri-state. A helper can be
available, verifiably missing, or unverifiable because PHP-FPM `open_basedir`
intentionally excludes `/usr/sbin`. The latter is expected for a least-
privilege web process: the copied command runs the helper as root, so the
plugin no longer reports it as missing or recommends adding `/usr/sbin` to
PHP's filesystem access.

## Version 0.2.5 unrestricted PHP-FPM pools

Version 0.2.5 reports a disabled effective `open_basedir` as a security-
hardening issue for the expected per-user PHP-FPM pool model. The connector
continues to show filesystem and export health separately, but its overall
status becomes Needs attention and Site Health returns a recommended action.
The page labels an empty value as `disabled`, not `unknown`.

## Version 0.2.6 Access Guard handoff

Version 0.2.6 implements the ArgentWolf Access Guard connector handoff. The
connector registers `argentwolf_access_guard_sentinel_external_event_v1`
(`$result`, `$event`, `$producer`; first wins) and queues the normalized
`registration_abuse` event in its normal durable queue, returning
`accepted` with reference `queue:<event_uuid>`, or `rejected`/`error`. It also
answers `argentwolf_access_guard_sentinel_context_v1` with its effective
`site_id`, `source_host`, and `drop_directory`, so Access Guard does not
duplicate Sentinel configuration. Bridge enablement stays per site in Access
Guard; the connector has no site allowlist. See
`docs/access-guard-handoff.md`.

## CI and releases (Forgejo)

Forgejo (`forgejo.argentwolf.org/alan/wp-plugin-argent-sentinel`) is the
canonical repository and release host.

- `.forgejo/workflows/ci.yml` runs on pushes to `main`, pull requests, and
  manual dispatch. On PHP 7.4 and 8.5 it runs `scripts/ci-check.sh`: `php -l`
  on every plugin PHP file, all five dependency-free test runners
  (`tests/run.php`, `export-run.php`, `onboarding-run.php`, `admin-run.php`,
  and `access-guard-handoff-run.php`), and `git diff --check`. A build-only job
  then produces the installable ZIP with `scripts/build-release.sh`.
- `.forgejo/workflows/release.yml` publishes a release when a `vX.Y.Z` tag is
  pushed to Forgejo. The tag must match the plugin header `Version`,
  `readme.txt` `Stable tag`, and `Plugin::VERSION`. The workflow reruns the
  test matrix, builds `argent-sentinel-wordpress-X.Y.Z.zip` with `git archive`
  (development files are excluded via `.gitattributes` and a leak check), and
  creates a Forgejo release with the ZIP and its `.sha256`. Manual dispatch is
  a dry run that only uploads the ZIP as a workflow artifact.
- To cut a release after the version bump is merged:
  `git tag -s vX.Y.Z && git push forgejo vX.Y.Z`.
- `.github/workflows/release.yml` is a legacy mirror kept in step with the
  same tests; it is not the release path.

Run the same checks locally with `bash scripts/ci-check.sh` and
`bash scripts/build-release.sh`.
