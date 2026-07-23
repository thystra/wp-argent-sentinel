# Argent Sentinel WordPress event schema

## Local queue schema version 1

The local table name is:

```text
{$wpdb->prefix}argent_sentinel_events
```

Rows are evidence records plus a small set of delivery-state fields.

Evidence fields are append-only:

- numeric `id`, used as the deterministic local sequence;
- unique UUIDv4 `event_uuid`;
- UTC occurrence and recording timestamps;
- site ID, site URL, source host, and `wordpress` service;
- event type, severity, and outcome;
- normalized source IP and IP version;
- optional canonical username and WordPress user ID;
- optional email domain and site-keyed HMAC identifier;
- bounded user agent, request method, and query-free request path; and
- bounded, recursively sanitized JSON metadata.

Delivery fields are reserved for Phase 4:

- nullable `batch_uuid`;
- `export_state`, initially `queued`;
- nullable export timestamp;
- retry count; and
- a `TEXT` slot for the future exporter's bounded error summary.

`event_uuid` has a database uniqueness constraint. Producers create a UUID
once for an event; a future retry must reuse that UUID. Evidence is never
updated in place. A correction will be a new event whose metadata references
the earlier UUID.

MariaDB/MySQL `LONGTEXT` is used for metadata instead of a native JSON column
to preserve normal WordPress and `dbDelta()` compatibility.

## Event-type constants

Event names are centralized in `Events\EventType`. Phase 1 emits:

- `comment_marked_spam`
- `login_failed`
- `login_unknown_account`

Constants already reserve the verification, cleanup, suspicious-registration,
and batch audit names from the project brief so later phases do not scatter
string literals.

## Planned batch envelope

Phase 4 will export a versioned envelope resembling:

```json
{
  "schema_version": 1,
  "batch_uuid": "a0f7f640-e87b-4f60-b7ef-cbca5b5e4868",
  "created_at": "2026-07-22T20:10:00Z",
  "source": {
    "host": "nidhoggur",
    "site_id": "wolfandraven-blog",
    "site_url": "https://www.wolfandraven.blog/",
    "service": "wordpress",
    "plugin_version": "0.1.0"
  },
  "events": []
}
```

Events will be sorted by local numeric sequence and then event UUID. The
exporter is not implemented in Phase 1; this document fixes the intended
boundary without claiming that batch files are currently produced.
