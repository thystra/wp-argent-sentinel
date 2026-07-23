# Argent Sentinel WordPress batch export

Version 0.2.0 exports queued WordPress events to immutable JSON batches for a separate host-level collector.

## Trust boundary

The WordPress plugin remains unprivileged. It does not invoke CrowdSec, Fail2ban, nftables, RDAP, or send abuse reports. It only writes completed JSON files to a configured local drop directory.

The host collector runs separately and is responsible for deduplication, correlation, enrichment, reporting, and enforcement.

## Drop directory

The configured path must be absolute, exist already, not itself be a symbolic link, and be writable by the site's PHP-FPM user. Do not place it under the WordPress document root or uploads directory.

Example for `wolfandraven.blog`:

```bash
sudo groupadd --system sentinel 2>/dev/null || true
sudo usermod -aG sentinel wolfandraven

sudo install -d \
  -o wolfandraven \
  -g sentinel \
  -m 2770 \
  /var/lib/argent-sentinel/drop/wordpress/wolfandraven-blog/incoming
```

Restart the site's PHP-FPM service after changing supplementary groups.

Recommended `wp-config.php` values:

```php
define( 'ARGENT_SENTINEL_SITE_ID', 'wolfandraven-blog' );
define( 'ARGENT_SENTINEL_SOURCE_HOST', 'nidhoggur' );
define(
    'ARGENT_SENTINEL_DROP_DIRECTORY',
    '/var/lib/argent-sentinel/drop/wordpress/wolfandraven-blog/incoming'
);
define( 'ARGENT_SENTINEL_HMAC_SECRET', 'a-stable-random-secret' );
```

## Atomic publication

The exporter:

1. Selects queued rows in ascending internal ID order.
2. Stops at the configured event-count or byte limit.
3. Creates a versioned JSON envelope with a UUID batch identifier.
4. Writes a hidden temporary file in the final directory.
5. Applies mode `0640`, flushes the file, and calls `fsync()` when available.
6. Atomically renames the file to its final `.json` name.
7. Marks exactly the emitted queue rows exported.

A crash after step 6 but before step 7 may cause those events to be exported again. The host collector must therefore deduplicate by `batch_uuid` and `event_uuid`.

## Batch envelope

```json
{
  "schema_version": 1,
  "batch_uuid": "UUID",
  "created_at": "UTC RFC3339 timestamp",
  "source": {
    "host": "nidhoggur",
    "site_id": "wolfandraven-blog",
    "site_url": "https://www.wolfandraven.blog/",
    "service": "wordpress",
    "plugin_version": "0.2.0"
  },
  "events": []
}
```

## WP-CLI

```bash
wp argent-sentinel status --format=json
wp argent-sentinel export --format=json
wp argent-sentinel prune --limit=1000 --format=json
```

The cron exporter writes at most one batch per run to bound the work performed in a web-triggered WP-Cron request. Use repeated WP-CLI export calls when draining a large initial backlog.

## Retention

Only rows already marked `exported` are eligible for local pruning. Queued rows are never pruned by the exporter. The default local retention is 30 days after successful export.
