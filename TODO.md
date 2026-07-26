# wp-argent-sentinel TODO

## Completed in 0.2.4

- [x] Represent helper availability as available, missing, or unverifiable.
- [x] Avoid stat calls against paths excluded by PHP open_basedir.
- [x] Do not report the package helper missing merely because `/usr/sbin` is outside the PHP-FPM sandbox.
- [x] Explain that `/usr/sbin` should not be added to open_basedir solely for helper verification.
- [x] Add administrator and Site Health coverage for restricted and genuinely missing helpers.
- [x] Preserve the web-UI-first onboarding workflow.

## Open

- [ ] Test 0.2.4 by uploading the ZIP through the WordPress plugin web UI.
- [ ] Test a clean second-site onboarding through Settings → Argent Sentinel.
- [ ] Add collector acknowledgement so WordPress can show whether a published batch was collected.
- [ ] Consider a multisite network-status screen if network activated.
