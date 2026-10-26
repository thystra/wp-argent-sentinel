# Access Guard → Argent Sentinel connector handoff (0.2.6)

ArgentWolf Access Guard decides whether a WordPress registration is allowed.
Argent Sentinel owns network reputation, escalation, and enforcement. Since
0.2.6 this connector is the preferred local transport between the two.

Authoritative contract: Access Guard `docs/SENTINEL-INTEGRATION.md`
(`ArgentSentinelBridge`).

## Hooks registered by the connector

| Hook | Args | Purpose |
| --- | --- | --- |
| `argentwolf_access_guard_sentinel_external_event_v1` | `$result, $event, $producer` | Queue one normalized Sentinel WordPress event and return an acknowledgement. |
| `argentwolf_access_guard_sentinel_context_v1` | `$context` | Publish `site_id`, `source_host`, `drop_directory`, `connector_version`, `ready`. |

Both filters are **first-wins**: if an earlier callback already returned a
non-null result (or an array context), the connector returns it unchanged and
does nothing else.

Access Guard detects the handoff with `has_filter()`. Bridge enablement and the
transport mode (`disabled`, `auto`, `connector`, `drop`) remain **per site in
Access Guard**. The connector is site-agnostic: it has no site allowlist and
always stamps the queued event with this installation's own Site ID, site URL,
and source host, exactly like locally captured events.

## Event handling

Accepted input is the existing Sentinel WordPress event shape:
`event_uuid`, `occurred_at`, `event_type`, `outcome`, `source_ip`,
`user_agent`, `request` (`method`, `path`, `request_id`), and `metadata`.

- `event_type` must be `registration_abuse` (`EventType::REGISTRATION_ABUSE`).
- `outcome` must be `denied` (queued as `warning`) or `observed` (`notice`).
- `event_uuid` must be a UUIDv4 and is preserved, so a producer retry of an
  already-queued event is acknowledged as `accepted` instead of duplicated.
- `source_ip` is required and normalized; `occurred_at` is converted to UTC.
- `request.path` loses any query string; a valid `request.request_id`
  (`[A-Za-z0-9._:-]{8,128}`) is kept as `metadata.request_id`, which the
  collector already reads for Nginx correlation.
- Access Guard score, confidence, `rule_ids`, producer/version/build/schema,
  policy action/reason, registration phase, and fingerprint signature/UA hash
  remain metadata. Missing producer fields are filled from `$producer`.
  `metadata.handoff` records the hook name.
- Metadata passes through the normal `MetadataSanitizer`. Its sensitive-key
  rules drop `fingerprint_headers`; the stable fingerprint signature and UA
  hash are retained.

Acknowledgements:

```php
array( 'status' => 'accepted', 'reference' => 'queue:<event_uuid>', 'message' => '' )
array( 'status' => 'rejected', 'reference' => '', 'message' => '<reason>' ) // invalid/unsupported input
array( 'status' => 'error',    'reference' => '', 'message' => '<reason>' ) // queue write failed
```

Exceptions are contained and returned as `error`; the handoff never changes
the registration decision Access Guard already made. Queued events leave via
the normal scheduled batch export into
`/var/lib/argent-sentinel/drop/wordpress/<site-id>/incoming`.
