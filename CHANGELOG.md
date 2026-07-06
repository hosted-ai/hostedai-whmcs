# Changelog

All notable changes to the hosted·ai WHMCS module are documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Prepaid wallet billing mode** — per-product `Billing Mode` (`monthly` or `prepaid`). In prepaid, hourly usage is deducted from the client's WHMCS credit balance (the wallet) via an auto-paid micro-invoice.
- **Wallet funding at signup** — `Initial Wallet Credit` on provisioning: `grant` seeds the wallet for free (trials/demos), or `invoice` raises an Add Funds invoice the client pays to activate.
- **Auto top-up** — when the wallet drops below a configurable threshold, an Add Funds top-up invoice is raised automatically (deduped per client).
- **Low-balance email alerts** — warn the client when the wallet nears the minimum threshold, at most once per 24 h (auto-creates the email template).
- **Auto-suspend / auto-unsuspend** — the service is suspended when the wallet reaches the minimum balance and automatically unsuspended when a payment brings it back above the threshold (`InvoicePaid` hook).
- **Wallet & Billing admin tab** — live wallet balance, thresholds and timestamps in the WHMCS service view, plus switching billing mode without touching the database.
- **Client-area wallet widget** — prepaid clients see balance, minimum, low-balance and suspension status in the portal.
- **Currency-mismatch warning** — both crons log a warning when the hosted·ai API bills in a different currency than the client's WHMCS currency (WHMCS has no per-invoice currency, so amounts follow the client — the client's currency must match the pricing policy).
- Deploy/rollback scripts (`deploy/deploy.sh`, `deploy/rollback.sh`) with server-side backups.

### Changed
- Added inline help text to the product's Module Settings options, and marked "No. of Suspension/Termination Days" as monthly-mode-only.

### Security
- Crons are now CLI-only: `hostedai_cron.php` and `hostedai_hourly_cron.php` reject any non-CLI (HTTP) invocation with 403 before bootstrapping. Previously they were executable over an unauthenticated HTTP GET, allowing anyone to trigger billing/suspension.
- OTL login endpoint (`lib/ajax.php`) now enforces authentication and service ownership and derives the client email server-side instead of trusting the request body (fixes an IDOR).
- Escaped API-sourced values in the client area (`manage.tpl`: team member email/role/status, resource-type keys/values) and the admin service tab (`$used`/`$aval`) to prevent stored XSS from hosted·ai team data.
- Sanitized module logging (no API token / PII in logs) and enforced SSL certificate verification on API calls.
- Hardened the hourly cron lock: it now lives in a private per-install `0700` directory instead of a predictable world-writable `/tmp` path (which a local user could squat to silently stall billing), and a lock-open failure now exits with an error instead of masquerading as "already running".

### Fixed
- Overdue automation no longer terminates (deletes) a team when "No. of Termination Days" is blank. Day-counts are now validated as numbers; a blank value skips the service instead of coercing to an always-true comparison.
- Overdue termination now only applies to a service that is already suspended — an Active overdue service is suspended first, then terminated on a later run (never destroyed in one step).
- Added an idempotency guard to the monthly cron: a service already invoiced in the current month is skipped, preventing duplicate invoices on a re-run.
- Monthly invoices no longer bill VM (non-pod) instances at $0 — when the API omits per-instance `total_cost`, the cost is summed from the interval resources (incl. Disk Storage / Public IP).
- Shared-storage costs are now invoiced: the cron descends into the `intervals` structure to read cost/hours (previously read at the wrong level, so shared storage was never billed).
- Product upgrade/downgrade (ChangePackage) no longer reports a false error when a policy is unchanged (the resource endpoint returns 400 "already linked" for a no-op), and now surfaces the real API message on genuine failure.
- ChangePackage now propagates all five policies (pricing, resource, service, instance-type, image) instead of only pricing + resource, so an upgrade to a product with different policies actually applies them.
- Prepaid: a service already suspended for zero balance is no longer billed each hour (avoids lingering unpayable invoices); the balance check still runs.
- GPUaaS-pool invoice description reads the correctly-spaced `"Subscription Rate"` / `"Ephemeral Storage"` keys (were showing $0.00; the billed total was already correct).
- Billing windows are built in UTC (`gmdate`/`gmmktime`) to match the `timezone=UTC` API parameter, fixing over/under-billing when the WHMCS server is not on UTC.
- CreateAccount is now atomic — the upstream hosted·ai team is rolled back if the local WHMCS writes fail, avoiding an orphaned team.
- Each cron binds to the service's own hosted·ai server (multi-cluster) instead of falling back to the first enabled server, which billed against the wrong cluster.
- Team-level GPUaaS consumption (`team_metrics`) is now billed in the monthly cron (sourced from the detailed team-billing endpoint).
- Admin billing-mode switch reads `billing_mode_switch` from `$_REQUEST` so the Wallet & Billing tab save works.
- Raised curl timeout to 30 s for slower write operations; unified Helper return values and stopped fabricating API responses (success is determined by the HTTP code); deduped a redundant API call; fixed TestConnection undefined variables; removed a dead CDN script.

## [2.4.0] - 2026-06-16

### Changed
- Adapted team creation payload to ariel API: policy IDs now nested under `general` object with `has_general_policies: true` flag (breaking change in `POST /api/team`)

## [2.2.0] - 2025-03-12

### Changed
- Adapted module to updated hosted·ai API format (datetime format, timezone, response structure)
- Adapted billing endpoints to titan API changes
- Removed Role dropdown from product settings (now set automatically)

### Fixed
- OTL login button path in client area
- Pre-onboard for new users so OTL login works immediately
- Team name uniqueness by appending service ID
- Helper fallback was selecting disabled servers
- curlCall parameter order in billing/update methods
- Cron billing adapted to new API resource structure

## [2.1.0] - 2025-02-XX

### Added
- One Time Login (OTL) support for seamless user authentication
- Server group support for multi-server environments

### Fixed
- AJAX endpoint WHMCS initialization issue
- Removed unnecessary 100MB.bin file

## [2.0.0] - 2025-01-XX

### Added
- Shared storage and ephemeral storage billing
- GPUaaS pool cost tracking and invoicing
- Multi-currency support for WHMCS invoices
- Hours:minutes formatting for billing time displays

### Fixed
- PCI device invoice amount calculation
- Resource overview alignment in WHMCS admin area
- Login URL concatenation in templates
- PHP syntax error in hostedai_cron.php

### Security
- Disabled debug mode and sanitized logging for production

## [1.1.0] - 2024-XX-XX

### Added
- Pricing policy and resource policy updates on product upgrade/downgrade
- API token hidden in service edit view

### Fixed
- Test connection functionality
- Resource limit display (was showing unlimited despite policy restrictions)

## [1.0.0] - 2024-XX-XX

### Added
- Initial release
- Team provisioning (create, suspend, unsuspend, terminate)
- Server connection and API token authentication
- Product configuration with pricing, service, image, resource, and instance type policies
- Client area with resource overview and login link
- Admin area with resource usage display
- Basic billing cron for usage-based invoicing
