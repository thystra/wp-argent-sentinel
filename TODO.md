# wp-argent-sentinel TODO

## Completed in 0.2.3

- [x] Use the package-owned onboarding helper name.
- [x] Generate host commands with interactive open_basedir review.
- [x] Detect open_basedir before filesystem checks misreport a blocked path as missing.
- [x] Show effective, required, and suggested open_basedir values.
- [x] Add a dedicated Site Health open_basedir test.
- [x] Add regression coverage for helper discovery and open_basedir health.

## Open

- [ ] Test 0.2.3 through a clean onboarding on another WordPress/FPM pool.
- [ ] Add collector acknowledgement so WordPress can show whether a batch was collected.
- [ ] Consider a multisite network-status screen if network activated.
