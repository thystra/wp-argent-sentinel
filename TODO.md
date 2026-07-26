# wp-argent-sentinel TODO

## Completed in 0.2.2

- [x] Promote the staged 0.2.1 onboarding classes into the active plugin.
- [x] Register `wp argent-sentinel setup`.
- [x] Preserve `status`, `export`, and `prune`.
- [x] Add `onboarding-command` with PHP-user and WordPress-path overrides.
- [x] Add `status --strict` for automation.
- [x] Add a Settings → Argent Sentinel setup and operational-status page.
- [x] Add a nonce-protected manual export action.
- [x] Add persistent administrator warnings when the connector needs attention.
- [x] Add WordPress Site Health and debug-information integration.
- [x] Show non-secret directory owner, group, mode, PHP process user, queue,
  schema, schedule, and request-correlation status.
- [x] Detect the packaged or development onboarding helper.
- [x] Add dependency-free WP-CLI, onboarding, admin-page, and Site Health tests.

## Open

- [ ] Complete Allaboard Acres onboarding after deploying 0.2.2.
- [ ] Confirm the server package installs the onboarding helper at
  `/usr/sbin/argent-sentinel-onboard-wordpress-site`.
- [ ] Add a collector acknowledgement/receipt mechanism so WordPress can show
  whether a published batch was collected, not only whether it was written.
- [ ] Consider a multisite network-status screen if the plugin is network
  activated.
