# hosted·ai WHMCS — Billing Overview

A quick reference for how billing works end-to-end in this integration: how
invoices are created, how payments reconcile, how services are provisioned and
billed, what happens on non-payment, the custom pieces we added on top of stock
WHMCS, and where the data lives. Intended for onboarding and debugging.

> ⚠️ **Known limitation — no currency conversion.** The module does **not** convert
> between currencies. The hosted·ai pricing-policy currency and the WHMCS client's
> currency **must be the same**, or invoices are booked in the wrong currency. See
> [§8. Currency](#8-currency--no-conversion-currencies-must-match).

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
| **prepaid** | `crons/hostedai_hourly_cron.php` | every hour | Pulls the **last hour's** usage across all categories — **compute (instances), shared storage, GPUaaS pool and team-level usage** — and creates an **itemized** invoice (a line per category), then **immediately pays it from the client's wallet** (`ApplyCredit`). Micro-invoices, auto-paid. |

The mode per service is stored in `mod_hostdaiteam_details.billing_mode`
(seeded from the product's `configoption10` at provisioning, switchable later by
an admin).

> Note: each service is billed against **the hosted·ai server it lives on**. The
> crons bind an API client to the service's own server (`hostedaiHelperForService`)
> so multi-cluster setups bill against the right cluster instead of falling back to
> the first enabled server. Balance checks / auto top-up / suspension still run when
> a service's server is unavailable — only usage billing is skipped.

**Wallet funding (prepaid).** The wallet is topped up via WHMCS **Add Funds**
invoices (`Helper::createAddFundsInvoice` → item type `AddFunds`), so paying them
credits the balance natively and revenue is counted once. Two optional, per-product
mechanisms use it: **initial wallet credit** on provision (grant or invoice) and
**auto top-up** when the balance drops below a threshold. See the
[Administrator Guide → Wallet Funding](ADMINISTRATOR_GUIDE.md#wallet-funding).

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
- **Currency (important):** invoices follow the **client's** currency, the API bills
  in the **pricing-policy's** currency, and the module does **no conversion** — so the
  two must match. Full detail, rationale and how to comply in
  [§8. Currency](#8-currency--no-conversion-currencies-must-match).

---

## 3. Provisioning & billing cycles

1. Product is set with **Module Name = `hostedai`**, a **Server Group**, the five
   policies, and **Billing Mode** (`configoption10`).
2. Order → **Accept Order** → **ModuleCreate** → `hostedai_CreateAccount`:
   creates the team on hosted·ai, stores the `team_id` custom field, inserts a
   row into `mod_hostdaiteam_details` with the billing mode, and (prepaid only)
   seeds the wallet if **Initial Wallet Credit** (`configoption12/13`) is set —
   see §4.
3. The **billing cadence is driven by the cron + `billing_mode`**, not by the
   WHMCS product billing cycle: monthly → 1st of month; prepaid → hourly.

Provisioning is atomic: if the local WHMCS writes fail after the upstream team is
created, the module rolls the team back so a retry starts clean.

---

## 4. Failed payments, dunning, suspension, cancellation

**Monthly mode**
- Unpaid invoice → after `configoption8` (*suspension days*) the monthly cron
  **suspends** the service (and the hosted·ai team).
- After `configoption9` (*termination days*) → **terminates** — but only a service
  that is **already suspended** is terminated (an Active service past the terminate
  window is suspended first; it can be terminated on a later run). A service is never
  destroyed straight from Active.
- Payment reminders (dunning) are stock WHMCS automated emails.

> **Dunning cadence.** The overdue suspend/terminate pass lives in `hostedai_cron.php`.
> Its promptness depends on how often you schedule that cron: if it runs only on the 1st
> (`0 0 1 * *`), an account that goes overdue mid-month is not acted on until the next
> run. To act on overdue accounts daily, schedule `hostedai_cron.php` **daily**
> (`0 2 * * *`) — the monthly *invoice-generation* block still only fires on the 1st,
> so a daily schedule just tightens dunning without creating extra invoices.

**Prepaid mode** (no debt by design)
- **Initial credit** (`configoption12/13`) — on provision the wallet is seeded so
  a new account doesn't start at $0: `grant` tops it up for free, `invoice` raises
  an Add Funds invoice the client pays.
- **Auto top-up** (`configoption14/15`) — when the balance drops below the top-up
  threshold, the hourly cron raises an Add Funds invoice (deduped per client) so
  the wallet is refilled before it hits the suspend threshold; with a saved
  auto-capture pay method WHMCS charges it automatically.
- Balance ≤ `min_balance` (`configoption11`) → hourly cron **suspends**
  (`suspended_reason = balance_zero`).
- Balance between 1× and 2× `min_balance` → **low-balance warning email**, at most
  once per 24h (tracked by `low_balance_notified_at`).
- **Auto-unsuspend**: the `InvoicePaid` hook re-activates a `balance_zero`
  service once an invoice is paid **and** the wallet is above the threshold. This
  fires for Add Funds top-ups too (WHMCS credits the wallet on payment).
- Balance/top-up/suspend run even if the service's hosted·ai server is
  unavailable — only usage billing is skipped.

**Cancellation / termination**
- **ModuleTerminate** deletes the hosted·ai team, clears the `team_id` custom
  field, and removes the `mod_hostdaiteam_details` row.
- **No final partial-period invoice is raised on termination** (by design). Monthly
  usage accrued since the last 1st-of-month invoice, and the final partial hour in
  prepaid mode, are not billed at teardown. This is intentional: overdue terminations
  are already unpaid (nothing to collect), and the prepaid final hour is negligible
  (usage is billed hourly up to that point). If you need to bill a voluntary
  mid-cycle cancellation, raise the final invoice manually before terminating.

---

## 5. Custom modules / hooks on top of stock WHMCS

| Component | Path | Purpose |
|---|---|---|
| Server module | `modules/servers/hostedai/hostedai.php` | Create / Suspend / Unsuspend / Terminate / ChangePackage / ConfigOptions / ClientArea / AdminServicesTab |
| API client | `modules/servers/hostedai/lib/Helper.php` | All hosted·ai REST calls + billing-table helpers + `createAddFundsInvoice()` / `hasOpenAddFundsInvoice()` (wallet funding) |
| Wallet hook | `includes/hooks/hostedai_wallet.php` | `InvoicePaid` → auto-unsuspend prepaid services after top-up |
| Monthly cron | `crons/hostedai_cron.php` | End-of-month usage invoice (monthly mode) |
| Hourly cron | `crons/hostedai_hourly_cron.php` | Hourly usage deduction, balance checks, auto top-up (prepaid mode) |
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

---

## 8. Currency — no conversion (currencies must match)

**Current state: the module performs no FX conversion.** It writes the API's numeric
amount straight onto the invoice. Whether that number *means* €26 or $26 is decided
entirely by the client's WHMCS currency — the module does not translate between them.

Why this is a hard constraint, not a bug we can paper over:

- **WHMCS has no per-invoice currency.** `tblinvoices` has no currency column; every
  invoice is denominated in the **client's** currency (`tblclients.currency`). Passing
  a `currency` parameter to `CreateInvoice` does **not** change this.
- **The hosted·ai API bills in the pricing-policy's currency** (e.g. `currency_code:
  "EUR"` in the `team-billing/*` responses).
- The module bills the API amount **as-is**. So if the policy is in EUR and the client
  is in USD, `€26.00` is recorded as `$26.00` — right number, wrong currency.

**Requirement: the client's WHMCS currency must equal the pricing-policy currency.**

How to comply:

1. Check the policy currency — the `currency_code` field in the billing API response
   (or the pricing policy in the hosted·ai admin panel).
2. Set the WHMCS client to that currency (**Clients → Profile → Currency**) *before*
   provisioning, and price the hostedai products in the same currency.
3. Keep a single currency per server / server-group so one cluster's policies never
   mix currencies across clients. (Sell EUR-priced clusters only to EUR clients, etc.)

**Detection already in place:** both crons call `Helper::warnOnCurrencyMismatch()` and
log `currency mismatch for UID …` to the Activity Log when the API currency differs
from the client's. This only *warns* — it does not correct the amount. Treat that
warning as "this client is being mis-billed until the currencies are aligned."

**Not yet implemented (possible future work):** automatic conversion using WHMCS
exchange rates (`tblcurrencies`) at bill time. This would allow selling a single-
currency policy to clients of other currencies, at the cost of FX-rate drift,
rounding, and invoices no longer matching the hosted·ai panel figure to the cent. Not
built today — currencies must match instead.

See also: [ADMINISTRATOR_GUIDE.md](ADMINISTRATOR_GUIDE.md).
