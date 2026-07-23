=== Argent Sentinel WordPress Connector ===
Contributors: argent-sentinel
Tags: security, spam, login, email verification, self hosted
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Queues privacy-conscious WordPress security events for a self-hosted Argent Sentinel collector.

== Description ==

Argent Sentinel WordPress Connector is an unprivileged event producer. It records immutable, UUID-identified WordPress security events in an append-only local queue.

Version 0.2.0 adds bounded, deterministic JSON batch export to a local host drop directory. Completed files are written under a temporary name, flushed, and atomically renamed before queue rows are marked exported. A separate privileged host collector is responsible for correlation, abuse reporting, and CrowdSec decisions.

The connector currently records:

* comments marked as spam;
* failed logins for known accounts; and
* login attempts for nonexistent accounts ("ghost logins").

It does not run cscli, change nftables, invoke Fail2ban, write under WordPress uploads, call a commercial validation service, or directly create firewall decisions.

Version 0.2.0 also adds scheduled export and exported-row retention, exporter diagnostics, and WP-CLI commands. Email verification, unverified-login blocking, email suppression, and abandoned-account cleanup remain future work.

See README.md and docs/export-batches.md for architecture, privacy, configuration, deployment permissions, and testing details.

== Installation ==

1. Upload the directory to `/wp-content/plugins/argent-sentinel-wordpress/`.
2. Define a stable site ID, source host, HMAC secret, and absolute drop directory in wp-config.php.
3. Create the drop directory outside the web root and make it writable only by the site's PHP-FPM user and the restricted Sentinel group.
4. Activate Argent Sentinel WordPress Connector.
5. Run `wp argent-sentinel status` and `wp argent-sentinel export --format=json`.
6. Install and validate the separate host collector before enabling enforcement.

== Privacy ==

The connector stores IP addresses and bounded security metadata. It does not persist full submitted email addresses or unknown submitted usernames; site-keyed HMAC identifiers are used instead. It excludes passwords, cookies, sessions, nonces, verification tokens, request bodies, and query strings.

Exported batches contain the same bounded event evidence as the queue. Protect the drop and archive directories as security records.

== Changelog ==

= 0.2.0 =

* Add deterministic, size-bounded JSON event batches.
* Write batch files atomically outside the WordPress web root.
* Add one-minute export and hourly exported-row pruning jobs.
* Add `wp argent-sentinel export`, `status`, and `prune` commands.
* Add exporter diagnostics, retry tracking, and dependency-free tests.

= 0.1.1 =

* Use explicit wpdb field formats so WordPress does not coerce string `site_id` values to integer zero.

= 0.1.0 =

* Add idempotent event-queue schema and activation migration.
* Add immutable UUIDv4 event model and privacy filtering.
* Add direct and trusted-proxy client IP handling for IPv4 and IPv6.
* Record comment-spam, failed-login, and unknown-account login events.
* Add unit tests and Phase 1 security/deployment documentation.
