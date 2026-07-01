# hosted·ai WHMCS Module — Administrator Guide

Complete reference for installing, configuring, and operating the hosted·ai server
module, including the prepaid wallet system introduced in v2.x.

**Compatibility:** WHMCS 8.x · PHP 8.1+ · Module type: Server

---

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Server Setup](#server-setup)
- [Product Setup](#product-setup)
- [Module Options](#module-options)
- [Billing Modes](#billing-modes)
  - [Monthly Billing](#monthly-billing)
  - [Prepaid Wallet](#prepaid-wallet)
- [Client Area Widget](#client-area-widget)
- [Low-Balance Alerts](#low-balance-alerts)
- [Auto-Suspension](#auto-suspension)
- [Auto-Unsuspend](#auto-unsuspend)
- [Wallet & Billing Admin Tab](#wallet--billing-admin-tab)
- [Switching Billing Modes](#switching-billing-modes)
- [Cron Jobs](#cron-jobs)
- [InvoicePaid Hook](#invoicepaid-hook)
- [Database Table](#database-table)
- [Troubleshooting](#troubleshooting)

---

## Overview

The hosted·ai WHMCS module connects your WHMCS installation to the hosted·ai
platform. When a client orders a product powered by this module, WHMCS provisions
a dedicated **team** on the hosted·ai API and stores the `team_id` in the
service's custom fields.

- **Automated provisioning** — create, suspend, unsuspend, and terminate hosted·ai
  teams directly from WHMCS order management.
- **Two billing modes** — monthly post-paid invoicing or hourly prepaid wallet
  deductions, configurable per product.
- **Low-balance alerts** — automatic email when a prepaid wallet falls below 2× the
  minimum threshold, at most once per 24 hours.
- **Balance-based suspension** — services whose wallet balance falls to or below the
  minimum threshold are suspended automatically by the hourly cron and unsuspended
  as soon as the client tops up.
- **Admin wallet panel** — a Wallet & Billing tab in the WHMCS service view shows
  live balance and timestamps and lets admins switch billing mode without touching
  the database.
- **Client area widget** — prepaid clients see their wallet balance, minimum
  threshold, and suspension status in the WHMCS client portal.

---

## Requirements

| Component | Minimum | Notes |
|---|---|---|
| WHMCS | 8.0 | Tested on 8.10–8.12 |
| PHP | 8.1 | 8.2+ supported |
| MySQL / MariaDB | 5.7 / 10.3 | Module creates its own table on first use |
| hosted·ai API | Current | API token with admin privileges required |
| Cron access | — | Server-level crontab or WHMCS cron hook |

---

## Installation

### 1. Deploy files with deploy.sh

The repo includes `deploy/deploy.sh`, which copies all module files to the WHMCS
server over SSH with automatic backup and rollback support. Both variables below are
**required** — the script exits with an error if either is missing:

```bash
export WHMCS_SSH_HOST=user@your-whmcs-server.example.com
export WHMCS_ROOT=/var/www/vhosts/your-whmcs-server.example.com/httpdocs
```

Run from the repo root:

```bash
./deploy/deploy.sh
```

On first run, every tracked `.php/.tpl/.css/.js/.svg/.png` file is deployed (module
code, templates, asset icons and logo). Subsequent runs deploy only files changed
since the last deploy. Deployed files are set to `644` automatically. The script
covers the module directory (`modules/servers/hostedai/`), the hook file
(`includes/hooks/hostedai_wallet.php`), and both cron scripts in a single step.

To list available backups and roll back: `./deploy/rollback.sh`

### 2. Register cron jobs

Add both entries to your server's crontab. Replace the PHP binary path and WHMCS
root to match your environment.

```cron
# Monthly billing — runs on the 1st of each month at midnight
0 0 1 * * /usr/bin/php /var/www/whmcs/crons/hostedai_cron.php

# Hourly billing + balance checks — runs every hour
0 * * * * /usr/bin/php /var/www/whmcs/crons/hostedai_hourly_cron.php
```

See [Cron Jobs](#cron-jobs) for what each script does.

### 3. Add and test the server

Configure the server in WHMCS (see [Server Setup](#server-setup)) and click
**Test Connection** to verify connectivity.

> **Note:** The module creates the `mod_hostdaiteam_details` table automatically on
> first provisioning. No manual migration is required.

---

## Server Setup

Navigate to **Setup → Products/Services → Servers**, then click **Add New Server**.

| Field | Value |
|---|---|
| **Name** | Any descriptive label, e.g. `hosted-ai-prod` |
| **Hostname** | Your hosted·ai API hostname, e.g. `your-instance.hostedai.cloud` |
| **Server Type** | `hostedai` |
| **Username** | Leave empty |
| **Password** | Your hosted·ai API token (admin privileges required) |
| **Secure** | Enabled (HTTPS) |

After saving, click **Test Connection**. A green confirmation means the module can
reach the hosted·ai API.

### Server Group

Go to **Setup → Products/Services → Servers** and click **Create New Group**. Give
the group a name (e.g. `hosted·ai Nodes`) and add the server you just created.
Products are assigned to groups, not individual servers, so WHMCS can balance load
across multiple nodes later.

---

## Product Setup

Navigate to **Setup → Products/Services → Products/Services** and create or edit a
product.

1. On the **Details** tab, set *Module Name* to `hostedai`.
2. On the **Module Settings** tab, select the server group created above.
3. Click **Save Changes**. The policy dropdowns (configoptions 1–6) now populate from
   the hosted·ai server in that group. Fill them in as described in the next section.
4. Set **configoption10** (Billing Mode) and **configoption11** (Min Wallet Balance)
   according to how you want this product billed, then **Save Changes** again.

> **Important — save before the policies appear.** The policy dropdowns load from the
> server group **stored on the saved product**. On a brand-new product (or before the
> first save), the group is not yet persisted, so the module falls back to the *first
> enabled hosted·ai server* and shows **that** server's policies — which is why you may
> see policies that don't match the group you just picked. Always select the module and
> server group, **Save Changes**, then re-open Module Settings to see the correct
> policies.

> **Note:** The module creates the `team_id` custom field (type: Text, admin-only)
> automatically the first time you open the product's **Module Settings** tab — no
> manual setup needed. This field stores the hosted·ai team identifier after
> provisioning and is what suspend, unsuspend, and terminate rely on. If it is
> missing, simply re-open Module Settings to recreate it.

---

## Module Options

All configoptions appear on the product's **Module Settings** tab.

| # | Label | Type | Description |
|---|---|---|---|
| 1 | Pricing Policy | Dropdown | Pricing policy from the hosted·ai API. Populated automatically once a server group is selected. |
| 2 | Resources Policy | Dropdown | Resource quota policy (CPU, RAM, GPU limits). |
| 3 | Service Policy | Dropdown | Service-level policy applied to the team. |
| 4 | Instance Type Policy | Dropdown | Allowed instance types for the team. |
| 5 | Image Policy | Dropdown | Allowed OS images for the team. |
| 6 | Color | Dropdown | Accent color shown in the hosted·ai UI for this team's workspace. |
| 7 | Login URL | Text | Override for the "Login" button destination in the client area. Leave empty to use the default user panel URL. |
| 8 | No. of Suspension Days | Text | Days after invoice due date before the service is suspended for non-payment (monthly mode). |
| 9 | No. of Termination Days | Text | Days after suspension before the service is terminated. |
| **10** | **Billing Mode** | Dropdown | Default billing mode for new services: `monthly` or `prepaid`. Can be overridden per-service by an admin after provisioning. |
| **11** | **Min Wallet Balance ($)** | Text | Prepaid mode only. Services are suspended when the client's wallet falls to or below this amount. Low-balance warnings fire when balance falls below 2× this value. Default: `1.00`. |
| **12** | **Initial Wallet Credit ($)** | Text | Prepaid only. On provision, seed the wallet up to this amount so the account does not start at $0 (0/blank = off). See [Wallet Funding](#wallet-funding). |
| **13** | **Initial Credit Mode** | Dropdown | `grant` = add the credit for free (trial/demo); `invoice` = raise an Add Funds invoice the client must pay. |
| **14** | **Auto Top-Up Threshold ($)** | Text | Prepaid only. When the wallet drops below this, auto-raise a top-up invoice (0/blank = off). Set **above** Min Wallet Balance so it fires before suspension. |
| **15** | **Auto Top-Up Amount ($)** | Text | Amount of the auto top-up (Add Funds) invoice. |

> **Config options are positional.** Options 12–15 are appended after Min Wallet
> Balance; never reorder or insert options above existing ones or saved product
> values will shift.

---

## Wallet Funding

Two optional mechanisms keep prepaid wallets funded. Both use WHMCS **Add Funds**
invoices, so paying them credits the wallet natively (deposit recorded once — no
double-counted revenue). Both are per-product and off by default.

### Initial wallet credit (configoption12 / 13)

So a new prepaid account does not start at $0 and suspend on the first hourly
cron:

- **`grant`** — on provision the module tops the wallet **up to** the configured
  amount for free (won't stack if the shared wallet already has funds). Best for
  trials/demos.
- **`invoice`** — on provision the module raises an Add Funds invoice for the
  amount; the client pays it to fund the wallet (and the service auto-unsuspends
  via the InvoicePaid hook once paid).

### Auto top-up (configoption14 / 15)

When the hourly cron sees a prepaid wallet drop **below the top-up threshold**, it
raises an Add Funds invoice for the top-up amount (and emails it), unless the
client already has an open Add Funds invoice (dedup is per client, since credit is
shared). If the client has a saved pay method with auto-capture, WHMCS charges it
automatically — true auto-replenishment. Set the threshold **above** Min Wallet
Balance so top-up happens before suspension.

> The balance check, auto top-up and suspension run even when a service's hosted·ai
> server is unavailable — only usage billing is skipped in that case.

---

## Billing Modes

Each service has a `billing_mode` stored in `mod_hostdaiteam_details`. The mode is
set from the product's configoption10 at provisioning time and can be changed
per-service by an admin at any time.

| Monthly | Prepaid Wallet |
|---|---|
| Invoice generated on the 1st of each month | Client maintains a credit balance in WHMCS |
| Invoice covers actual usage from the prior month | Hourly cron deducts cost from the wallet each hour |
| Suspension triggered by unpaid invoice after grace period | Low-balance email alert at 2× the minimum threshold |
| Standard WHMCS payment flow | Service suspended when balance falls to or below the threshold |
| Hourly cron skips these services entirely | Service auto-unsuspended as soon as client tops up |

> **Note:** Both modes can coexist across products on the same WHMCS installation.
> Monthly services are processed by `hostedai_cron.php`; prepaid services by
> `hostedai_hourly_cron.php`. The two scripts are independent.

### Monthly Billing

The monthly cron reads resource usage from the hosted·ai API for the previous
calendar month and creates a WHMCS invoice for each active service with non-zero
usage.

1. On the 1st of the month, `hostedai_cron.php` runs.
2. It queries `mod_hostdaiteam_details` for all services where
   `billing_mode = 'monthly'` (or NULL for legacy rows).
3. For each service it makes up to three API calls and builds a multi-line invoice:
   - **Main billing** — monthly base fee + per-workspace instance breakdown
     (GPU, CPU, RAM, storage per interval)
   - **Shared storage** — team shared volume costs (separate API call, always processed)
   - **GPUaaS pool** — GPU pool subscription and usage costs (separate API call, always processed)
4. If the combined total is greater than zero, a single WHMCS invoice is created with
   one line item per cost category and emailed to the client.
5. If total = 0 across all three sources, no invoice is created and the skip is logged.

> **Warning — no deduplication guard.** The cron has no check for already-generated
> invoices. Running it twice in the same month produces two invoices for the same
> period. Schedule it strictly on the 1st and do not trigger it manually on
> production without confirming it has not already run that month.

> **Note — timing.** The cron targets the prior calendar month's data. Running it
> before the 1st (e.g. on the 28th) invoices the month before that. Schedule
> strictly on the 1st.

### Prepaid Wallet

Prepaid services are billed hourly. The client's WHMCS credit balance serves as the
wallet. Top-ups are done via the standard WHMCS credit mechanism
(**Billing → Add Credit**).

**Hourly billing flow:**

1. The hourly cron fetches the current-hour cost from the hosted·ai API.
2. If the cost is non-zero, it creates a WHMCS invoice and immediately calls
   `ApplyCredit` to pay it from the client's existing credit balance.
3. It then reads the updated balance and evaluates thresholds.

**55-minute overlap guard:** if the cron fires twice within 55 minutes (e.g. due to
scheduler overlap), the second run skips billing for any service billed in the last
55 minutes. This prevents double-billing when two cron processes briefly overlap at
the top of the hour. The balance check still runs even when billing is skipped.

---

## Client Area Widget

When a client views a prepaid service in the WHMCS client portal, the hosted·ai
panel includes a wallet summary block. It is not shown for monthly services.

| Field | Description |
|---|---|
| Billing Mode | `prepaid` |
| Wallet Balance | Current credit balance, formatted to two decimal places |
| Min Balance | The threshold from configoption11 |
| Low Balance | `true` when balance is between 1× and 2× the minimum threshold |
| Suspended | `true` when `suspended_reason = balance_zero` |

Template variables: `walletBillingMode`, `walletBalance`, `walletMinBalance`,
`walletLowBalance`, `walletSuspended`.

---

## Low-Balance Alerts

When the hourly cron detects that a prepaid service's wallet balance has fallen below
**2× the minimum threshold** (but the service is not yet suspended), it sends a
warning email to the client.

**Email template:** the module auto-creates a WHMCS email template named
`hostedai_low_balance_warning` on first use if one does not already exist. Customise
it at **Setup → Email Templates**. Available merge fields:

- `{$service_id}` — WHMCS service ID
- `{$balance}` — current wallet balance
- `{$threshold}` — minimum balance threshold

**Deduplication:** the warning is sent **at most once per 24 hours** per service. The
timestamp of the last notification is stored in `low_balance_notified_at`. If the
cron runs again within 24 hours and the balance is still low, it logs the skip.

---

## Auto-Suspension

After billing and the balance check, if the client's wallet balance is at or below
the minimum threshold, the hourly cron suspends the service.

1. Calls `ModuleSuspend` on the WHMCS service.
2. Sets `suspended_reason = 'balance_zero'` in `mod_hostdaiteam_details`.
3. Logs the event to the WHMCS activity log.

> **Note:** Only services with `suspended_reason = 'balance_zero'` are eligible for
> auto-unsuspend. Services suspended for other reasons (e.g. `invoice_overdue`) are
> not touched by the wallet hook.

---

## Auto-Unsuspend

When a client pays any invoice, the `InvoicePaid` hook fires. The module checks
whether the client has prepaid services suspended with
`suspended_reason = 'balance_zero'` and, if the wallet balance now exceeds the
minimum threshold, unsuspends them automatically.

1. Client tops up their wallet (**Billing → Add Credit**) and pays any invoice to
   trigger the hook.
2. Hook reads the client's current credit balance via `GetClientsDetails`.
3. For each `balance_zero`-suspended service: if balance > minimum threshold, calls
   `ModuleUnsuspend` and clears `suspended_reason`.

> **Warning:** A top-up that brings the balance exactly to the threshold will not
> unsuspend. The balance must be **strictly greater** than `configoption11`.

---

## Wallet & Billing Admin Tab

A dedicated **Wallet & Billing** tab appears on every service page in the WHMCS
admin. It is rendered from the database without calling the hosted·ai API, so it
works even if the remote server is temporarily unreachable.

**Prepaid services:**

| Field | Description |
|---|---|
| Billing Mode | Current mode label (`prepaid`) |
| Wallet Balance | Live balance from WHMCS credit. Shown in red when at or below the minimum threshold. |
| Min Balance | Threshold from configoption11 |
| Last Billed | Timestamp of the last successful hourly billing cycle |
| Suspended Reason | Current `suspended_reason` value, or — if none |
| Last Warning Sent | Timestamp of the last low-balance email |

**Monthly services:** the tab shows only the billing mode label. No wallet fields.

---

## Switching Billing Modes

Admins can switch a service between monthly and prepaid without touching the
database. The **Wallet & Billing** tab contains a dropdown pre-selected to the
current mode.

1. Open the service in the WHMCS admin panel.
2. Click the **Wallet & Billing** tab.
3. Select the new mode from the *Switch Billing Mode* dropdown.
4. Click **Save Changes**.

> **Note:** When switching to either mode, these fields are cleared:
> `suspended_reason`, `last_billed_at`, `low_balance_notified_at`. This ensures the
> service starts the new billing cycle cleanly. The switch is logged in the WHMCS
> activity log.

---

## Cron Jobs

### Monthly Cron — `crons/hostedai_cron.php`

Processes all services where `billing_mode = 'monthly'` (or NULL). Generates one
invoice per service with non-zero usage for the prior calendar month.

```cron
0 0 1 * * /usr/bin/php /var/www/whmcs/crons/hostedai_cron.php
```

Execution typically completes in under a minute for small installs. Do not run
manually on production without confirming the cron has not already executed that
month — there is no deduplication guard.

### Hourly Cron — `crons/hostedai_hourly_cron.php`

Processes all services where `billing_mode = 'prepaid'`. Runs billing, checks
balances, sends low-balance alerts, and suspends or allows unsuspension.

```cron
0 * * * * /usr/bin/php /var/www/whmcs/crons/hostedai_hourly_cron.php
```

**Per-service logic (each iteration):**

1. **55-minute guard** — if `last_billed_at` is within the last 55 minutes, skip
   billing for this service (but continue to the balance check).
2. **Billing** — call the hosted·ai API for current-hour cost. If cost > 0, create a
   paid invoice and deduct from the client's credit balance.
3. **Balance check** — read the updated balance:
   - Balance ≤ threshold (`configoption11`, default $1.00): suspend the service (`balance_zero`).
   - threshold < balance ≤ 2× threshold: send low-balance warning if not sent in last 24 h.
   - Balance > 2× threshold: nothing.

> **Note — schema migration.** The hourly cron calls `ensureWalletColumns()` at
> startup, which adds the `low_balance_notified_at` column if it does not yet exist.
> The `billing_mode`, `suspended_reason`, and `last_billed_at` columns are added by
> `insert_teamDetail()` during provisioning. All migrations are idempotent.

---

## InvoicePaid Hook

File: `includes/hooks/hostedai_wallet.php`

WHMCS auto-loads all files in `includes/hooks/` on every request — no registration
is needed. The hook fires on `InvoicePaid` and auto-unsuspends prepaid services with
`suspended_reason = 'balance_zero'` when the client's wallet balance exceeds the
minimum threshold after payment. See [Auto-Unsuspend](#auto-unsuspend) for details.

> **Note:** Do not move this file into the module directory. WHMCS does not guarantee
> that hooks registered via `add_hook()` inside a server module file fire on all
> requests. The `includes/hooks/` location is the only reliable placement.

---

## Database Table

Table: `mod_hostdaiteam_details`

| Column | Type | Description |
|---|---|---|
| `id` | INT AUTO_INCREMENT | Primary key |
| `uid` | VARCHAR | WHMCS client ID (`tblclients.id`) |
| `sid` | VARCHAR | WHMCS service ID (`tblhosting.id`) |
| `pid` | VARCHAR | WHMCS product ID (`tblproducts.id`) |
| `teamid` | VARCHAR | hosted·ai team UUID |
| `invoiceid` | VARCHAR | Last generated invoice ID |
| `status` | VARCHAR | Internal status flag |
| `billing_mode` | VARCHAR | `monthly` or `prepaid` |
| `suspended_reason` | VARCHAR NULL | `balance_zero`, `invoice_overdue`, or NULL |
| `last_billed_at` | DATETIME NULL | Timestamp of last successful hourly bill |
| `low_balance_notified_at` | DATETIME NULL | Timestamp of last low-balance warning email |
| `created_at` | DATETIME | Row creation time |
| `updated_at` | DATETIME | Last modification time |

New installations get all columns at table creation time. On existing installations,
`insert_teamDetail()` adds `billing_mode`, `suspended_reason`, and `last_billed_at`
on first provisioning; `ensureWalletColumns()` (called by the hourly cron) adds
`low_balance_notified_at`. All guards are idempotent.

---

## Troubleshooting

**Test Connection fails.** Verify the server's hostname and API token. The token
must have admin-level privileges. Check that the WHMCS server can reach the
hosted·ai API over HTTPS (port 443).

**Wrong policies appear in the dropdowns (from another server).** The policy
dropdowns load from the server group saved on the product. If the product has not been
saved with its server group yet, the module falls back to the first enabled hosted·ai
server and shows that server's policies. Select Module Name + Server Group on the
**Module Settings** tab, click **Save Changes**, then re-open the tab — the dropdowns
will reload from the correct server.

**team_id is empty after provisioning.** The `team_id` custom field is created
automatically when the product's **Module Settings** tab is first opened. If
provisioning ran before the field existed, re-open Module Settings to (re)create it,
then re-provision. Confirm the field is product-scoped (not global) and admin-only
under **Setup → Custom Fields**.

**Low-balance email not sent.** Check that the hourly cron is running
(`last_billed_at` should update each hour). Verify the client's balance is between
1× and 2× the minimum threshold — outside that range no warning is sent. If a warning
was sent in the last 24 hours, the next one is suppressed; check
`low_balance_notified_at`.

**Service not auto-unsuspended after top-up.** The hook fires on `InvoicePaid`.
Adding credit alone does not trigger it — the client must also pay an invoice. The
balance after payment must be **strictly greater than** the minimum threshold.
Confirm the hook file is at `includes/hooks/hostedai_wallet.php` and is readable by
the web server.

**Monthly cron generates no invoices.** The cron targets last month's usage. If run
before the 1st (or during the current month), there may be no billable data yet. Also
confirm the service's `billing_mode` is `monthly` (or NULL) and that last month had
non-zero resource usage in the hosted·ai API.

**Wallet & Billing tab missing.** The tab is returned by
`hostedai_AdminServicesTabFields` regardless of whether the hosted·ai API is
reachable. If absent, check the PHP error log for exceptions and verify the module
files are uploaded and readable.

> **Activity log.** All cron actions and mode switches are written to the WHMCS
> activity log (**Utilities → Logs → Activity Log**). Search for `hostedai` or
> `HostedAI` to filter relevant entries.
