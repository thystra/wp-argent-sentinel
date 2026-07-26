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
