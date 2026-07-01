# hosted·ai WHMCS — Billing Overview

A quick reference for how billing works end-to-end in this integration: how
invoices are created, how payments reconcile, how services are provisioned and
billed, what happens on non-payment, the custom pieces we added on top of stock
WHMCS, and where the data lives. Intended for onboarding and debugging.

---

## 1. How invoices get generated (triggers, timing, recurring vs one-off)

There are **two** invoice sources:

**A. Stock WHMCS (price-based)**
For products with a normal price and billing cycle, WHMCS's own *Invoice
Creation* cron generates recurring invoices a configurable number of days before
the due date (Setup → Automation Settings → Invoice Generation). One-off charges
(setup fees, manual invoices) are added on top.

**B. hosted·ai usage-based (this module)** — driven by **cron (time), not events**:

| Mode | Cron | Timing | Behaviour |
|---|---|---|---|
| **monthly** | `crons/hostedai_cron.php` | 1st of each month | Pulls **last month's** usage from the hosted·ai API and builds **one detailed invoice** per team — line items for each instance (CPU/RAM/GPU/Ephemeral/Subscription/TFlops/vRAM) plus shared-storage, GPUaaS-pool, PCI and team-metrics costs. |
| **prepaid** | `crons/hostedai_hourly_cron.php` | every hour | Pulls the **last hour's** usage; if > 0, creates an invoice and **immediately pays it from the client's wallet** (`ApplyCredit`). Micro-invoices, auto-paid. |

The mode per service is stored in `mod_hostdaiteam_details.billing_mode`
(seeded from the product's `configoption10` at provisioning, switchable later by
an admin).

> Note: each service is billed against **the hosted·ai server it lives on**. The
> monthly cron binds an API client to the service's own server
> (`hostedaiHelperForService`) so multi-cluster setups bill against the right
> cluster instead of falling back to the first enabled server.

---

## 2. Payment gateways & reconciliation

- Gateways are **stock WHMCS** (Bank Transfer, Stripe, PayPal, etc.), configured
  under Setup → Payments → Payment Gateways. **This module does not touch
  gateways.**
- When an invoice is paid, the gateway callback marks it **Paid** and records a
  transaction in `tblaccounts` (keyed by `transid`). Manual gateways (e.g. bank
  transfer) are reconciled by an admin via **Add Payment**.
- **Prepaid wallet distinction (important):** the wallet **is the client's credit
  balance**. Paying an ordinary invoice books **revenue** — it does **not** fund
  the wallet. The wallet is funded via **Add Funds** (its payment goes into
  credit). Hourly usage invoices are settled **from credit** (`ApplyCredit`), not
  through a gateway.

---

## 3. Provisioning & billing cycles

1. Product is set with **Module Name = `hostedai`**, a **Server Group**, the five
   policies, and **Billing Mode** (`configoption10`).
2. Order → **Accept Order** → **ModuleCreate** → `hostedai_CreateAccount`:
   creates the team on hosted·ai, stores the `team_id` custom field, and inserts a
   row into `mod_hostdaiteam_details` with the billing mode.
3. The **billing cadence is driven by the cron + `billing_mode`**, not by the
   WHMCS product billing cycle: monthly → 1st of month; prepaid → hourly.

Provisioning is atomic: if the local WHMCS writes fail after the upstream team is
created, the module rolls the team back so a retry starts clean.

---

## 4. Failed payments, dunning, suspension, cancellation

**Monthly mode**
- Unpaid invoice → after `configoption8` (*suspension days*) the monthly cron
  **suspends** the service (and the hosted·ai team).
- After `configoption9` (*termination days*) → **terminates**.
- Payment reminders (dunning) are stock WHMCS automated emails.

**Prepaid mode** (no debt by design)
- Balance ≤ `min_balance` (`configoption11`) → hourly cron **suspends**
  (`suspended_reason = balance_zero`).
- Balance between 1× and 2× the threshold → **low-balance warning email**, at most
  once per 24h (tracked by `low_balance_notified_at`).
- **Auto-unsuspend**: the `InvoicePaid` hook re-activates a `balance_zero`
  service once an invoice is paid **and** the wallet is above the threshold.

**Cancellation / termination**
- **ModuleTerminate** deletes the hosted·ai team, clears the `team_id` custom
  field, and removes the `mod_hostdaiteam_details` row.

---

## 5. Custom modules / hooks on top of stock WHMCS

| Component | Path | Purpose |
|---|---|---|
| Server module | `modules/servers/hostedai/hostedai.php` | Create / Suspend / Unsuspend / Terminate / ChangePackage / ConfigOptions / ClientArea / AdminServicesTab |
| API client | `modules/servers/hostedai/lib/Helper.php` | All hosted·ai REST calls + the custom billing table helpers |
| Wallet hook | `includes/hooks/hostedai_wallet.php` | `InvoicePaid` → auto-unsuspend prepaid services after top-up |
| Monthly cron | `crons/hostedai_cron.php` | End-of-month usage invoice (monthly mode) |
| Hourly cron | `crons/hostedai_hourly_cron.php` | Hourly usage deduction + balance checks (prepaid mode) |
| OTL login | `modules/servers/hostedai/lib/ajax.php` | One-time login into the hosted·ai panel (auth + ownership enforced) |
| Custom table | `mod_hostdaiteam_details` | Links a WHMCS service to a hosted·ai team + billing state |
| Custom field | `team_id` (per product) | Stores the hosted·ai team id; auto-created by ConfigOptions |

---

## 6. Where the data lives (DB schema basics)

**Stock WHMCS tables**

| Table | Holds |
|---|---|
| `tblclients` | clients |
| `tblhosting` | services (userid, packageid, server, domainstatus, billing dates) |
| `tblproducts` | products and their `configoptionN` values |
| `tblservers` / `tblservergroups` / `tblservergroupsrel` | servers & groups |
| `tblinvoices` / `tblinvoiceitems` | invoices & line items |
| `tblaccounts` | transactions (payments) |
| `tblcredit` | wallet (credit) movements |
| `tblcustomfields` / `tblcustomfieldsvalues` | custom fields (incl. `team_id`) |
| `tblactivitylog` | activity log |
| `tblmodulelog` | module API request/response log |

**This module's table — `mod_hostdaiteam_details`**

| Column | Meaning |
|---|---|
| `uid` | WHMCS client id (`tblclients.id`) |
| `sid` | WHMCS service id (`tblhosting.id`) |
| `pid` | WHMCS product id (`tblproducts.id`) |
| `teamid` | hosted·ai team UUID |
| `invoiceid` | last generated invoice id |
| `status` | internal status flag |
| `billing_mode` | `monthly` or `prepaid` |
| `suspended_reason` | `balance_zero`, `invoice_overdue`, or NULL |
| `last_billed_at` | timestamp of last successful hourly bill |
| `low_balance_notified_at` | timestamp of last low-balance email |

---

## 7. Debugging tips — where to look

- **Cron behaviour / billing decisions** → Utilities → Logs → **Activity Log**
  (filter `hostedai` / `Hourly`), or the raw file logs the crons write to.
- **Raw hosted·ai API requests/responses** → **Module Log** (`tblmodulelog`).
- **Why a service suspended / its mode** → `mod_hostdaiteam_details` row for that
  `sid` (`billing_mode`, `suspended_reason`, `last_billed_at`).
- **Wallet balance** → client credit (`GetClientsDetails` `credit`, or
  `tblcredit`); remember: funded only via **Add Funds**, not ordinary invoice
  payments.
- **Invoices / line items** → `tblinvoices` + `tblinvoiceitems`.
- **Cron not running at all** → check it is registered in crontab (this is a
  manual install step; the deploy script does **not** register cron jobs).

See also: [ADMINISTRATOR_GUIDE.md](ADMINISTRATOR_GUIDE.md).
