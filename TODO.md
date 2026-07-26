# wp-argent-sentinel TODO

## Completed in 0.2.5

- [x] Flag disabled open_basedir as a security-hardening issue.
- [x] Mark the connector overall status as Needs attention for unrestricted per-site pools.
- [x] Show an administrator warning with the required Sentinel drop entry.
- [x] Return a Site Health recommended result when open_basedir is disabled.
- [x] Render an empty effective value as `disabled`, not `unknown`.
- [x] Explain that open_basedir is defense in depth rather than a complete sandbox.
- [x] Add web UI and Site Health regression coverage.

## Open

- [ ] Audit and repair the earlier per-user PHP pool creation workflow.
- [ ] Test 0.2.5 by web-uploading the ZIP to an unrestricted test pool.
- [ ] Add collector acknowledgement so WordPress can show whether a batch was collected.
- [ ] Consider a multisite network-status screen if network activated.
