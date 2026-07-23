# Argent Sentinel WordPress Connector

Argent Sentinel WordPress Connector is an unprivileged event producer for a
self-hosted security system. WordPress records immutable, UUID-identified
events in a local database queue. A later exporter will create atomic JSON
batches for a separate host-level collector.

## Current status

Version `0.2.0` adds the export and host-collector bridge while retaining the Phase 1 event capture. It currently:

- creates the append-only `{$wpdb->prefix}argent_sentinel_events` queue;
- generates UUIDv4 event identifiers and UTC timestamps;
- captures comments entering WordPress's `spam` state;
- captures failed logins for known accounts;
- classifies attempts for nonexistent accounts as `login_unknown_account`
  (the “ghost login” signal);
- records direct client IPs or safely resolves `X-Forwarded-For` through
  explicitly trusted proxy CIDRs;
- HMACs email addresses and unknown submitted usernames with a site-specific
  secret;
- removes sensitive metadata keys and strips request query strings; and
- exposes a non-secret diagnostics service for a future admin screen and CLI.

This increment does **not** yet export queue rows, prune the queue, verify email
addresses, suppress mail, clean stale users, add cron jobs, expose settings in
wp-admin, or provide WP-CLI commands. It is development code, not a complete
production deployment. Do not leave it collecting high-volume events
indefinitely until the export and retention phases are implemented.

## Version 0.2.0 batch export

Queued rows are exported in deterministic ID order to a size-bounded, versioned JSON envelope. The file is written under a hidden temporary name in the configured drop directory, flushed, synchronized when supported, and atomically renamed before the queue is marked exported. A crash between publication and the database update can safely produce a duplicate because the host collector deduplicates both `batch_uuid` and `event_uuid`.

The plugin schedules one export batch per minute and exported-row retention hourly. The commands are:

    wp argent-sentinel status --format=json
    wp argent-sentinel export --format=json
    wp argent-sentinel prune --limit=1000 --format=json

See `docs/export-batches.md` for the batch schema and filesystem permissions.

## Security-system boundary

```text
Fail2ban / Nginx / WordPress / sshd
                 |
                 v
       Local immutable event queues
                 |
          host-level collector
                 |
        HTTPS over LAN or VPN
                 |
                 v
       Nidhoggur collector API
                 |
             PostgreSQL
        +---------+----------+
        |         |          |
        v         v          v
   Dashboard   Reports   Ban policy
                             |
                        signed feed
                             |
             +---------------+---------------+
             |               |               |
             v               v               v
         Nidhoggur       Heimdall         Hermod
         nftables        nftables         nftables
```

The connector never runs `cscli`, edits nftables, invokes Fail2ban, changes
Nginx/PHP-FPM, or creates CrowdSec decisions. CrowdSec retains responsibility
for its own detection, community intelligence, decisions, and firewall
remediation. Argent Sentinel retains application evidence, correlates it
across hosts, and may later submit policy decisions through a separate
privileged service.

## Captured events

### `comment_marked_spam`

Recorded when a comment, pingback, or trackback is inserted in the `spam`
state, or later transitions into that state. The source is the IP stored with
the original comment. This is deliberate: moderation may happen from wp-admin
long after submission, and the current request IP would belong to the
moderator.

Metadata contains only the numeric comment ID, bounded comment type, previous
status, and whether the stored IP was valid. Stored comment content, author
name, email, URL, and comment-agent fields are not copied. Request user agent,
method, and path are omitted for both initial and later classifications because
programmatic imports and moderation make their attribution ambiguous.

### `login_failed`

Recorded when authentication fails for an account that WordPress can resolve.
It may contain the numeric WordPress user ID and canonical username. An
email-shaped canonical login is never put in the username column. The reason
is reduced to a category such as `invalid_credentials`; WordPress error
messages are never recorded.

### `login_unknown_account`

Recorded when a submitted login identifier does not resolve to an account.
Unknown email addresses are represented by their domain and HMAC identifier.
Unknown username-style values are represented by an HMAC in metadata. The
submitted value itself is not persisted.

The future verification-specific error code is explicitly excluded from these
generic login events so Phase 2 can emit one `unverified_login_blocked` event
without duplication.

## Installation for development

1. Place this repository at
   `wp-content/plugins/argent-sentinel-wordpress`.
2. Activate **Argent Sentinel WordPress Connector**.
3. Confirm that the table
   `{$wpdb->prefix}argent_sentinel_events` exists.
4. Exercise test accounts and moderation on a non-production site first.

Activation is idempotent and does not lock, message, modify, or delete users.
Phase 2 will establish the verified baseline when verification is explicitly
enabled, so merely installing this Phase 1 connector cannot make an existing
account subject to a future cutoff.

If a schema check fails during an ordinary request, the connector remains
disabled and fail-open for that request. It waits at least five minutes before
retrying the migration instead of running `dbDelta()` on every page load.
An older plugin build also refuses to run against a newer recorded schema, so
downgrading cannot silently apply an obsolete migration.

The plugin is currently scoped to an ordinary single-site installation.
Network-wide multisite activation has not yet been implemented.

## Configuration

Phase 1 stores defaults in the `argent_sentinel_settings` option. Until the
settings screen is implemented, deploy-time constants may override the
security-sensitive values in `wp-config.php`:

```php
define( 'ARGENT_SENTINEL_SITE_ID', 'wolfandraven-blog' );
define( 'ARGENT_SENTINEL_SOURCE_HOST', 'nidhoggur' );
define(
	'ARGENT_SENTINEL_DROP_DIRECTORY',
	'/var/lib/argent-sentinel/drop/wordpress/wolfandraven-blog/incoming'
);
define( 'ARGENT_SENTINEL_TRUSTED_PROXY_CIDRS', '10.20.0.0/16, 2001:db8:20::/48' );
define( 'ARGENT_SENTINEL_HMAC_SECRET', 'replace-with-a-random-secret-of-at-least-32-bytes' );
```

Generate a secret locally, for example:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Do not reuse a public WordPress salt as the only HMAC secret. Keep this value
stable and backed up like other application secrets. Rotation prevents new
events from correlating to historical email/login identifiers.

When no constant is configured, activation generates a random 32-byte secret
and stores its hex representation in a non-autoloaded option. If secure random
generation fails, the plugin omits identifiers rather than using a weak
fallback. Configured or stored secrets shorter than 32 bytes are likewise
treated as unavailable and are not silently replaced or rotated.

### Trusted proxies

`REMOTE_ADDR` is authoritative by default. `X-Forwarded-For` is considered
only when the immediate peer belongs to a configured trusted CIDR. The trusted
suffix is walked from right to left until the first valid untrusted hop is
found; values farther left are then ignored as attacker-controlled. Malformed
data inside the trusted suffix falls back to the immediate peer. Header bytes
and trusted hops are bounded without allowing a long attacker-controlled
prefix to erase the first untrusted hop.

Configure only proxies that overwrite or safely append forwarding headers.
Do not add broad client or public-network ranges. If Nginx already rewrites
`REMOTE_ADDR` with a correctly configured Real IP module, no connector-level
trusted CIDRs may be necessary.

Connector-level proxy resolution applies to request-derived events such as
failed logins. Comment events deliberately use WordPress's stored
`comment_author_IP`; make sure Nginx/CDN Real IP handling is correct before
WordPress accepts comments, or that field may contain a proxy address.

## Future Sentinel drop directory

The Phase 4 exporter will write completed batches to:

```text
/var/lib/argent-sentinel/drop/wordpress/<site-id>/incoming/
```

An administrator—not PHP—should prepare it:

```sh
sudo install -d \
  -o <wordpress-fpm-user> \
  -g sentinel \
  -m 2770 \
  /var/lib/argent-sentinel/drop/wordpress/wolfandraven-blog/incoming
```

Completed files will use mode `0640`. Export will write a temporary file in
the same directory, flush and close it, then atomically rename it to its final
`.json` name. The path must remain outside the web root. A separate restricted
host collector will move/import completed files; the WordPress plugin will
not write into Sentinel's protected ingestion or outbox directories.

The configured drop directory is diagnostics-only in Phase 1 and is not
created or written yet.

## Data and privacy

The queue is designed for security evidence and may contain IP addresses,
canonical usernames for known accounts, WordPress user IDs, email domains,
HMAC identifiers, bounded user agents, and request paths.

It never intentionally records passwords, password hashes, authentication
cookies, sessions, nonces, verification tokens, complete request bodies,
arbitrary headers, SMTP credentials, API keys, full email addresses, or
WordPress error messages. Query strings are removed before request paths are
stored. Metadata is size/depth bounded and keys associated with common secret
types are discarded recursively.

Request path segments are not generically redacted. Do not place credentials
or tokens in custom route paths; future verification links will carry their
tokens in query parameters so this connector's path capture strips them.

Comment events use the stored comment-author IP and omit the current request's
user agent, method, and path for both initial and later spam classification.

The queue table is not deleted during ordinary deactivation or uninstall.
Destructive uninstall is honored only if
`delete_data_on_uninstall` was explicitly set truthy in the plugin option.

See [the event schema](docs/event-schema.md) and
[the threat model](docs/threat-model.md) for details.

## Development and verification

Run the dependency-free unit suite and PHP syntax checks:

```sh
php tests/run.php
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

Composer development dependencies declare PHPUnit, WordPress Coding
Standards, and PHPCompatibility:

```sh
composer install
composer test
composer lint:php
```

Do not claim WordPress integration or database-engine compatibility from the
unit runner alone. Activation, `dbDelta()`, hooks, and database behavior still
need integration tests against supported WordPress/MySQL or MariaDB versions.

## Release packaging

Push an annotated `v<major>.<minor>.<patch>` tag whose version matches both the
main plugin header and the `Stable tag` in `readme.txt`:

```sh
git tag -a v0.2.0 -m "Argent Sentinel WordPress Connector v0.2.0"
git push origin v0.2.0
```

The tag workflow runs the dependency-free tests and PHP syntax checks on PHP
7.4 and 8.5, creates an installable
`argent-sentinel-wordpress-<version>.zip` with
`argent-sentinel-wordpress/` as its single top-level plugin directory, and
publishes the ZIP plus its SHA-256 checksum as workflow and GitHub Release
assets. Development-only files are excluded through `.gitattributes`, and an
existing release is never overwritten by a rerun or moved tag.

## Planned phases

1. **Foundation (current):** queue, event model, settings/diagnostics
   foundation, abuse capture, privacy, and IP handling.
2. **Verification:** secure email tokens, state machine, login blocking,
   verification mail, admin controls, and WP-CLI.
3. **Lifecycle:** mail suppression, resend limits, stale-user cleanup, and
   cron/CLI jobs.
4. **Export:** deterministic batches, atomic files, retry state, retention,
   diagnostics, and host-collector deployment.
5. **Hardening:** full capability/nonce, proxy, privacy, rate-limit, coding
   standards, compatibility, and threat-model audits.
