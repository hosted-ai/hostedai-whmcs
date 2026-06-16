# Changelog

All notable changes to the hosted·ai WHMCS module are documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
