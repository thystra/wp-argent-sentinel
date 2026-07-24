# WordPress onboarding and Nginx request correlation

Version 0.2.1 keeps all normal connector settings in the WordPress options table.
It does not need to modify `wp-config.php`; existing constants remain authoritative
over option values.

## Host provisioning

The settings screen and `wp argent-sentinel onboarding_command` print an
idempotent command for the collector repository's
`scripts/onboard-wordpress-site.sh`. The host helper creates the protected drop
directory, adds the PHP-FPM account to the `sentinel` group, stores option-backed
settings through WP-CLI, runs diagnostics, and performs a test export.

## Request ID

Add this FastCGI parameter to the PHP location that serves the WordPress site:

```nginx
fastcgi_param ARGENT_SENTINEL_REQUEST_ID $request_id;
```

The value is supplied as a FastCGI server variable rather than an HTTP header,
so a remote client cannot choose the identifier used for correlation. The same
`$request_id` must be present in the Nginx `abuse_context` JSON log.

The connector validates the value, stores it inside sanitized event metadata,
and exports it as `request.request_id`. No cookie, password, submitted email
address, or raw targeted username is added.

## Central service boundary

The plugin never connects to `sentinel.argentwolf.org`. It only writes to the
local spool. A host-side agent will own HTTPS/mTLS delivery in a later release,
allowing the central service to move without changing WordPress installations.
