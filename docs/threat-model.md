# Threat model and security assumptions

## Protected assets

- Verification state and future verification-token hashes.
- Site-specific HMAC secret.
- Security event integrity and replayability.
- WordPress availability during attacks or collector failures.
- Personal data in the local queue and future Sentinel database.
- The privilege boundary between PHP-FPM and host/firewall services.

## Trust boundaries

WordPress/PHP-FPM is an unprivileged event producer. It is trusted to report
what its hooks observe, but it is not trusted with nftables, Fail2ban,
CrowdSec LAPI, Sentinel signing keys, or the protected central outbox.

The WordPress database and plugin files are trusted for local queue integrity.
A fully compromised WordPress process can fabricate or omit application
events; the central collector should retain source identity and must not treat
one application event as sufficient proof for high-impact policy without its
configured correlation rules.

The host collector and Nidhoggur API are separate components and are outside
this plugin's current implementation.

## Network identity

The direct peer in `REMOTE_ADDR` is trusted as the source by default.
Forwarding headers are attacker-controlled unless the immediate peer matches
an explicitly configured trusted proxy CIDR. Trusted proxies are assumed to
overwrite or safely append `X-Forwarded-For`.

The resolver walks only the trusted suffix from right to left and stops at the
first valid untrusted hop. A malformed value within the trusted suffix causes
fallback to the immediate peer. Input bytes and trusted hops are bounded, while
unexamined values to the left of the resolved client are ignored.

For comment moderation, the source IP is WordPress's stored comment-author IP,
not the moderator's request IP. That value can still be inaccurate if
WordPress/proxy handling was misconfigured when the comment was submitted.
Connector-level trusted proxy settings therefore protect request-derived
events, not previously stored comment addresses. User agent, method, and path
are omitted from all comment events because imports, automation, and later
moderation make the current request's attribution ambiguous.

Malformed IPs result in a retained event with a null source IP where possible;
they do not cause the WordPress operation to fail.

## Availability and failure behavior

Event recording is fail-open. Database errors, malformed event context, and
observer failures must not block comments or authentication. A hook is
available for non-sensitive recording-failure diagnostics, but failures must
not cause recursive event creation.

Activation fails explicitly if the queue cannot be created or verified. During
normal requests, a failed runtime migration leaves the connector inactive and
uses a five-minute retry backoff so a broken migration is not attempted on
every request.

The Phase 1 queue has no exporter or retention job. A sustained attack can
grow the database table. Production rollout therefore depends on the export,
retry, pruning, and operator diagnostics work in Phase 4.

`wp_login_failed` does not cover every possible plugin-specific, REST,
application-password, SSO, or custom authentication path. Integrations that
bypass normal WordPress authentication hooks need explicit adapters.

## Privacy

Full submitted email addresses and unknown login identifiers are not
persisted. Stable site-keyed HMAC identifiers allow within-site correlation,
while email domains remain available for abuse analysis. Canonical usernames
are retained only for resolved accounts and are omitted when email-shaped.

HMAC secrecy prevents straightforward offline lookup using only exported
events. It does not protect low-entropy identifiers after the site secret is
compromised. Rotating the secret breaks correlation with earlier identifiers.

Request query strings, bodies, arbitrary headers, WordPress error messages,
and known classes of secret-bearing metadata keys are excluded.

Request path segments are not generically secret-aware. Deployments must not
put credentials in custom route segments. Planned verification links will use
query parameters, which are removed before request paths are recorded.

Phase 2 will establish any existing-account verification baseline when the
feature is explicitly enabled. Phase 1 activation deliberately does not infer
that baseline, preventing an old installation timestamp from becoming a stale
security boundary.

## Out of scope for Phase 1

- Email-verification token security and state transitions.
- Resend and registration rate limiting.
- Email recipient rewriting/suppression.
- Account cleanup and content reassignment.
- Atomic JSON batch export and replay processing.
- Signed cluster ban feeds and policy decisions.
- Admin capability/nonce controls and WP-CLI authorization.
- Multisite network activation.
