# Unified Investment / Forex / Real-Estate Platform — Implementation Plan

## Context

The repo is currently empty — this is a from-scratch build. The goal is one company platform unifying three product lines under one user identity and one wallet (split into sub-ledgers): a high-yield investment product, a multi-asset forex/crypto/indices/commodities trading platform with **real execution against a liquidity provider**, and a real-estate platform supporting both fractional investment and a **full on-platform buy/sell marketplace with escrow/title transfer**. Each product line gets its own dashboard, sidebar, and feature set; admin gets full cross-platform visibility and compliance-grade controls (freeze, KYC, reverse transactions, approve withdrawals) — explicitly **not** the ability to permanently trap user funds. Backend is native PHP + MySQL (no heavy framework), frontend is Tailwind + vanilla JS, with a ~95-page public marketing site plus three dashboards plus an admin panel.

Two of your answers raise the regulatory bar substantially above a typical MVP:
- **Real execution via liquidity provider** means the platform is acting as (or routing through) a real broker. This requires a liquidity provider/prime-broker relationship (FIX API or broker bridge) and almost certainly a broker-dealer/MSB-type license in your target jurisdiction(s) before real money can flow. Engineering can build the broker-agnostic order/settlement architecture now, but **going live with real execution is gated on a signed liquidity provider contract and legal sign-off**, not on code being done.
- **Full on-platform escrow/title transfer** for real estate means integrating a title company/escrow service and likely involves licensed real-estate-broker participation, varies by jurisdiction, and is one of the more legally complex parts of the whole system.

This plan builds the full engineering architecture for both, but Phase 2 (forex) and Phase 3 (real estate) each have a hard external dependency (signed LP contract; signed escrow/title partner) called out below — engineering should build toward defined integration interfaces and can develop against a sandbox/mock of each partner while the business side closes those contracts in parallel.

## 1. Architecture

**Database**: one MySQL database (InnoDB, utf8mb4, DECIMAL for all money columns), module-prefixed tables (`inv_*`, `fx_*`, `re_*`) plus a shared core (`users`, `wallets`, `ledger_entries`, `transactions`, `kyc_*`, `audit_log`, `admin_*`). Module isolation is enforced at the application layer (each module's repository classes only ever touch their own table prefix; cross-module data needs go through a `Core/Reporting` aggregation layer, never direct cross-module queries) and reinforced with per-module MySQL DB users/grants in production. Separate physical databases were considered and rejected: they'd break atomic cross-module transactions and FK integrity on the one table that must never be wrong — the wallet.

**Money model — double-entry ledger, not balance columns**:
- `wallets`: one row per `(user_id, ledger_type)` where `ledger_type` ∈ `available | investment | forex | realestate`. Holds a cached `balance`, `locked_amount` (funds in open positions/active plans/pending withdrawal), and `status` (`active` / `frozen_debit` / `frozen_all`).
- `ledger_entries`: immutable, append-only, one row per debit/credit. No UPDATE/DELETE at the app layer or DB grant level.
- `transactions`: groups balanced debit/credit pairs; `reversal_of_transaction_id` self-reference is how reversals work — new offsetting entries, never edited history.
- A single `LedgerService`/`WalletService` (in `/app/Core/Ledger`) is the **only** code path that writes to `wallets`/`ledger_entries`. Every module (investment subscribe/payout, forex trade settlement, real estate fractional purchase/distribution, the internal transfer page) calls into it — no module ever writes a balance directly.
- Internal transfer between the 3 sub-ledgers is one atomic transaction producing a debit/credit pair — instant, free, no external payment rail.

**Auth/sessions**: one login, one session (DB-backed `user_sessions` for revocability), shared across all 3 dashboards. Module access is an authorization check per request (`ModuleAccessMiddleware`: global suspension? this ledger frozen? KYC tier sufficient?), not a separate login. 2FA (TOTP) is account-wide; step-up re-verification required for withdrawals, large transfers, destination-detail changes, KYC changes, and always for admin login.

**Admin**: separate `/admin/*` namespace (recommend separate subdomain in prod), separate `admin_users`/`admin_roles`/`admin_role_permissions` tables (never reuse customer `users`), RBAC permission checks centralized in middleware. Every mutating admin action is wrapped by `AuditLogger` writing before/after state to `audit_log` in the **same DB transaction** as the mutation — impossible to act without being logged. Read-only views logged separately to a lighter `admin_access_log`. `audit_log` has no edit/delete path anywhere in the app.

**Directory structure** (native PHP, PSR-4 via Composer, no heavy framework):
```
/app/Core        Auth, Ledger (LedgerService/WalletService), Kyc, Audit, Notifications, Http (Router/Middleware), Db, Support
/app/Modules/Investment   Domain, Repository, Service, Http  (inv_* tables only)
/app/Modules/Forex        Domain, Repository, Service, Http  (fx_* tables only)
/app/Modules/RealEstate   Domain, Repository, Service, Http  (re_* tables only)
/app/Modules/Admin        Http, Service — every controller wrapped in AuditLogger
/app/Modules/Marketing    Http for the ~95 public pages
/app/Views               server-rendered PHP templates, per /dashboard/{module}, /admin, /marketing
/public/index.php        single front controller; /public/assets per-area (shared, dashboard-investment, dashboard-forex, dashboard-realestate, admin, marketing)
/database/migrations, /database/seeders
/composer.json (PSR-4 root "App\\")
```
Recommended small Composer libraries: `firebase/php-jwt`, `vlucas/phpdotenv`, `spomky-labs/otphp` (2FA), `monolog/monolog`, `guzzlehttp/guzzle` (outbound calls to LP/KYC/market-data/escrow APIs), libsodium (built into PHP 8.4) for PII/document encryption at rest.

## 2. Database Schema (key tables, not full SQL)

**Core**: `users`, `user_sessions`, `wallets`, `transactions`, `ledger_entries`, `kyc_profiles`, `withdrawal_requests`, `disputes`/`support_tickets`, `admin_users`/`admin_roles`/`admin_role_permissions`, `audit_log`, `admin_access_log`, `notifications`, `referrals`/`affiliate_commissions`.

**Investment (`inv_*`)**: `inv_plans`, `inv_plan_versions` (immutable terms snapshot — editing a plan never changes terms for existing subscribers), `inv_subscriptions`, `inv_payouts` (generated by a scheduler per plan's payout frequency), `inv_referral_rates`.

**Forex (`fx_*`)**: `fx_instruments` (symbol, asset_class, leverage/lot specs, admin-managed), `fx_watchlists`/`fx_watchlist_items`, `fx_orders`, `fx_positions`, `fx_trades` (immutable fills), `fx_candles` (store candles, not raw ticks, in MySQL), `fx_strategy_presets`, plus a `fx_lp_orders` mapping table linking internal orders to the liquidity-provider's order/execution IDs once the broker integration is wired up. Every filled trade settles through `LedgerService` against the `forex` wallet.

**Real Estate (`re_*`)**: `re_properties` (status: draft/pending_review/approved/rejected/sold/delisted), `re_fractional_offerings`, `re_fractional_holdings`, `re_property_distributions` + `re_distribution_payouts`, `re_marketplace_offers`, `re_marketplace_transactions`, plus an `re_escrow_transactions` table tracking the on-platform escrow/title workflow state machine (initiated → funds-in-escrow → title-conditions-met → closed/cancelled) once an escrow partner is selected — each state transition is admin/partner-API-driven and audit-logged, with actual fund movement still going through `LedgerService`.

**Cross-cutting rule**: no module table ever stores a balance column. Every money-moving row (payout, trade, distribution, escrow closing) carries a `transaction_id` FK back to core `transactions`, written atomically with the ledger entries.

## 3. Page / Route Inventory

**Public marketing site (~95 templates, not 95 hand-authored files)**: Core/company (10: home, about, how-it-works, security, fees, careers, press, contact, sitemap...), Investment vertical (14: landing, plan pages, calculator, referral, FAQ...), Forex/Markets vertical (20: landing, per-asset-class pages, pair landing pages, order types, strategy overview, API access, FAQ...), Real Estate vertical (16: landing, fractional-investing explainer, marketplace browse, "list your property", city landing pages...), Legal/Compliance (12: ToS, privacy, AML/KYC policy, risk disclosures for both investment and leveraged trading, regulatory/licensing info page, complaints procedure...), Content engine (15+: blog index/template, categories, webinars, resources/education hub...), localization handled as `/[lang]/...` templated routes over CMS content, not duplicated files.

**Dashboards** (full route lists already drafted, to be implemented as listed):
- `/dashboard/investment/...` — overview, plans, plan detail, subscriptions, calculator, statements, referrals, compare.
- `/dashboard/forex/...` — overview, markets, trade/{symbol} (chart+orderbook+ticket), watchlist, orders, positions, history, strategies, news, calendar, statements.
- `/dashboard/realestate/...` — overview, invest (fractional offerings), holdings, marketplace, my-listings (+ new), offers, **escrow/closing status page for in-progress transactions**, statements.
- Shared: `/dashboard` (module picker), `/dashboard/transfer`, `/dashboard/wallet`, `/dashboard/withdraw`, `/dashboard/deposit`, `/dashboard/kyc`, `/dashboard/security`, `/dashboard/notifications`, `/dashboard/support`, `/dashboard/profile`.
- Admin `/admin/...` — users, KYC queue, withdrawal queue, transaction explorer + reverse, investment plan/forex instrument/real-estate listing management, **escrow transaction oversight**, disputes, roles, audit log, reports.

## 4. Features by Module (epics)

- **Investment**: plan CRUD + versioning, subscription lifecycle, scheduled payout engine, referral/affiliate, ROI calculator, statements export.
- **Forex/Markets**: market data ingestion + candlestick charts + order book, order management (market/limit/stop/stop-limit) routed to the liquidity provider, position/trade settlement into the ledger, multi-asset instrument management, strategy presets, news/economic calendar, margin/leverage risk controls, **LP integration layer** (order routing, execution reporting, reconciliation job comparing internal positions against LP statements).
- **Real Estate**: fractional offerings + holdings + distribution engine, marketplace listing CRUD with admin moderation, offer/negotiation flow, **escrow/title integration layer** (partner API calls, document handling, closing state machine, fund release tied to title-conditions-met).
- **Shared/platform-wide**: unified wallet + internal transfer, deposit/withdraw, 2FA, tiered KYC/AML, notifications, support ticketing, platform-wide referrals, API access for power users, cross-module statements.
- **Admin/Compliance**: user 360 view, per-ledger freeze, KYC review, withdrawal approval (terminates in approval+payout or a reasoned, appealable rejection — never a silent indefinite hold), transaction reversal via offsetting entries, RBAC, full audit + access logging, fraud-detection review queues (temporary holds only, mandatory human review), dual-approval threshold for manual ledger adjustments.

## 5. Frontend

- **CSS**: Tailwind, with a separate compiled bundle and design-token theme per area (`marketing.css`, `dashboard-shared.css`, `dashboard-investment.css`, `dashboard-forex.css`, `dashboard-realestate.css`, `admin.css`) so the three dashboards are visually distinct without duplicating a framework.
- **Charts/order book**: `lightweight-charts` (TradingView, canvas-based, framework-agnostic) for candlesticks; a small hand-built vanilla-JS order book component (bid/ask table, WebSocket-driven `update`/`patch`). Real-time price/order-book/P&L updates via WebSocket (e.g. `ratchet/ratchet` as a separate long-lived process — PHP-FPM can't hold persistent connections) with polling as a fallback if that infra isn't available yet.
- **No SPA framework**: server-rendered PHP templates, real `<a href>` navigation between dashboards/pages, vanilla ES modules per area (`/assets/shared/*.js` for header/account-switcher/transfer-widget/csrf/api-client loaded everywhere logged-in; per-dashboard JS for chart/orderbook/listing-wizard/etc.). Vite/esbuild used only as a build-time bundler (not a runtime framework) with manifest-based cache-busting.

## 6. Security & Compliance

2FA (TOTP) mandatory for admins, encouraged/threshold-mandatory for users, step-up re-verification on withdrawals/large transfers/destination changes/KYC changes. Tiered KYC (browse-only → basic → enhanced-due-diligence required for withdrawal/large transactions), document encryption at rest, third-party KYC/AML/sanctions-screening provider feeding a manual admin approval queue. Audit logging as described in section 1 (mutations transactional, reads logged separately, immutable). Rate limiting on login/password-reset/withdrawal/order-submission/API, ideally Redis-backed token bucket. Withdrawal approval workflow that always terminates in payout or a reasoned, appealable rejection. Fraud detection produces review-queue flags and at most temporary (24-48h) holds pending human review — never indefinite. PDO prepared statements everywhere (hard convention, no ORM to enforce it given no-framework constraint), CSRF tokens, CSP headers, Argon2id password hashing, TLS everywhere.

## 7. Phased Roadmap

0. **Foundations** — scaffolding, auth/2FA/sessions, the ledger core (`wallets`/`ledger_entries`/`transactions`/`LedgerService`), admin RBAC + `audit_log` + `AuditLogger`, shared dashboard shell. Everything else depends on this.
1. **Investment module** — simplest money-flow shape, validates the ledger/KYC/admin-moderation patterns end-to-end before harder modules.
2. **Forex/Markets module** — instrument management, market data integration, charting/order book, order management, **LP integration** (build against an LP sandbox/mock while the business side finalizes the liquidity provider contract; do not flip to real execution until that contract + licensing are confirmed).
3. **Real Estate module** — listings/moderation, fractional offerings/distributions, marketplace, **escrow/title integration** (build the state machine and partner-API interface against a sandbox/mock while an escrow/title partner and legal review are finalized; do not enable real closings until that partner is signed).
4. **Cross-module admin/compliance** — full user 360 view, withdrawal approval, transaction reversal, disputes, fraud review queues, compliance reporting.
5. **Marketing site + platform polish** — the ~95-page public site (CMS/template-driven, not hand-authored per page), notification system completion, support ticketing UI, API layer, i18n.
6. **Hardening + launch readiness** — security audit/pen-test, load testing (forex order flow, real-time price updates), backup/DR drills, accessibility, final legal/licensing sign-off, monitoring/alerting.

## 8. Hard External Dependencies / Risks (must be resolved outside engineering)

1. **Regulatory licensing** is the biggest risk overall — real-money investment plans and real forex execution are heavily regulated almost everywhere (MSB/broker-dealer/investment-firm authorization depending on jurisdiction). This plan does not and cannot itself confer compliance; legal counsel must confirm target jurisdictions and required licenses before real-money launch.
2. **Liquidity provider contract** (forex real execution) — Phase 2's LP integration layer needs a signed broker/LP relationship (FIX API or bridge) with defined client-money segregation terms before going live with real funds; build against a sandbox until then.
3. **Escrow/title partner** (real estate) — Phase 3's on-platform closing flow needs a signed title company/escrow service relationship, and likely licensed real-estate-broker involvement, which varies by jurisdiction; build the state machine against a sandbox/mock until then.
4. **Market data provider** — starting with a free/low-cost feed per your answer; confirm before scaling that its license permits commercial redistribution to end users, and budget for a commercial provider before real-money launch.
5. **What funds the investment-plan "ROI"** — paying fixed/guaranteed returns without real underlying investment activity backing them is a regulatory red flag in most jurisdictions regardless of how solid the ledger architecture is; this needs an explicit answer for legal counsel, not just engineering.
6. **Fractional real estate units** may themselves qualify as securities requiring registration, independent of the marketplace/escrow question.
7. **Payment rails** for deposit/withdrawal — gateway/banking partner choice affects KYC tiering and withdrawal SLAs, and processors frequently decline HYIP-adjacent business models during underwriting, so this should be lined up early.
8. **Insider-fraud control** — recommend the client set a specific monetary threshold above which manual ledger adjustments/reversals require two-admin approval.

## Verification

- Phase 0: write unit tests for `LedgerService` covering deposit, internal transfer, freeze enforcement, and reversal (offsetting entries balance to zero); confirm `audit_log` row is created in the same transaction as a test admin mutation by forcing a failure and confirming both roll back together.
- Each module phase: end-to-end manual walkthrough — create a user, complete KYC, perform the module's core money-moving action (subscribe to a plan / place and settle a forex trade against the LP sandbox / invest in a fractional offering or progress an escrow sandbox transaction), confirm wallet balances and ledger entries reconcile, confirm the action is visible and reversible from the admin panel with a correct audit trail.
- Before enabling real LP execution or real escrow closings: confirm contracts are signed and run the full flow against the partner's sandbox/UAT environment first.
- Run `composer audit` and a basic security pass (CSRF, prepared statements, session fixation) before each phase's release.
