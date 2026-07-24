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
