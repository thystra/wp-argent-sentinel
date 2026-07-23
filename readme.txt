=== Argent Sentinel WordPress Connector ===
Contributors: argent-sentinel
Tags: security, spam, login, email verification, self hosted
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Queues privacy-conscious WordPress security events for a self-hosted Argent Sentinel collector.

== Description ==

This is an early development release of the Argent Sentinel WordPress
Connector. Phase 1 creates an append-only local event queue and records:

* comments marked as spam;
* failed logins for known accounts; and
* login attempts for nonexistent accounts ("ghost logins").

The plugin is an unprivileged event producer. It does not run cscli, change
nftables, invoke Fail2ban, write under WordPress uploads, call a commercial
validation service, or create firewall decisions.

Email verification, queue export, cleanup, cron, administration, and WP-CLI
features are not implemented in version 0.1.0. Do not deploy this release for
unattended high-volume collection because queue export and retention are not
yet present.

See README.md for architecture, privacy, configuration, and testing details.

== Installation ==

1. Upload the directory to `/wp-content/plugins/argent-sentinel-wordpress/`.
2. Activate Argent Sentinel WordPress Connector.
3. Confirm the local event queue table was created.
4. Test on a non-production WordPress installation.

== Privacy ==

The connector stores IP addresses and bounded security metadata. It does not
persist full submitted email addresses or unknown submitted usernames;
site-keyed HMAC identifiers are used instead. It excludes passwords, cookies,
sessions, nonces, verification tokens, request bodies, and query strings.

== Changelog ==

= 0.1.0 =
* Add idempotent event-queue schema and activation migration.
* Add immutable UUIDv4 event model and privacy filtering.
* Add direct and trusted-proxy client IP handling for IPv4 and IPv6.
* Record comment-spam, failed-login, and unknown-account login events.
* Add unit tests and Phase 1 security/deployment documentation.
