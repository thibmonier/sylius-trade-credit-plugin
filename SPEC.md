# Specification — trade credit (payment on account)

Decided in the B2B memo of 2026-10-11 (`docs/2026-10-11-memo-b2b.md` of the plugins
workspace, section 3.4, and §2 for the shared order-hold kernel). "Trade credit" everywhere,
never "credit" alone: B2B payment terms, not consumer credit (CCD2 out of scope).

## 1. Constraints found in Sylius 2.2 / 2.3

- `sylius:cancel-unpaid-orders` cancels `awaiting_payment` orders after 5 days: orders on 30-day
  terms must be excluded, in SQL (a guard on `cancel` makes the command loop). → order-hold
  `UnpaidOrderCandidateQueryExtensionInterface`.
- Payment methods are offered by a composite resolver that returns the methods of the first
  supporting resolver; the default method is `findEnabledForChannel()[0]`; methods can be
  changed after checkout (shop order page, API). → order-hold `PaymentMethodEligibilityCheckerInterface`
  (one checker covers all paths; order-hold decorates each Sylius service once).
- The core checkout-complete constraint only checks that the method is enabled in the channel.
  → order-hold validation gives the checker message at completion (shop and API).
- Offline capture completes only the payment request: the order stays `awaiting_payment` —
  a payment method with the Offline gateway is enough, no new gateway.
- Guards run on every `can()` (including the admin order creation plugin): the reservation is a
  `transition` listener of `sylius_order_checkout.complete`, never a guard.
- The pay route and the payment requests let customers start (or, before 2.2.9, complete /
  refund) payments. → order-hold `PaymentEntryBlockerInterface` + floor `^2.2.9`.
- `OrderPaymentProvider` copies the method of a failed / cancelled payment into the new one.
- `OrderRepository::findOneByTokenValue()` excludes carts.

## 2. Model (plugin tables only)

| Table | Content |
|---|---|
| `tmonier_trade_credit_account` | owner (type + identifier from order-hold `AccountOwner`, unique), currency, `credit_limit`, `committed_amount`, `allow_exceed`, `credit_hold`, `block_when_overdue`, own `net_days` / `end_of_month_mode` (null: policy's), enabled, note |
| `tmonier_trade_credit_payment_terms_policy` | 1-1 with an Offline payment method: net days, end-of-month mode, enabled |
| `tmonier_trade_credit_order_terms` | one row per reservation (UUID): order, account, method, amount frozen, terms, due date, state `reserved` → `settled` / `released`, closing reason |
| `tmonier_trade_credit_ledger_entry` | append-only (UUID): reservation (+), settlement / release (−), adjustment, committed after, reason |

Amounts are integers in minor units. UUIDs let the reserver insert rows in SQL on every
platform (no sequence). The account FK of an open item has no cascade: an account with items
cannot be deleted.

## 3. Rules

- **Eligibility** (checker): policy enabled for the method → order owner has an enabled
  account in the order currency → billing address company not empty (B2B) → not on hold → no
  overdue reserved item when `block_when_overdue` → `allow_exceed` or `limit − committed ≥
  order total`. A placed order that already has a reservation keeps its method.
- **Reservation** (`TradeCreditReserver::reserve`, in a transaction):
  `UPDATE account SET committed = committed + :amount WHERE id = :id AND enabled AND NOT
  credit_hold AND (allow_exceed OR credit_limit - committed >= :amount)`; 0 rows → refusal
  (`limit_exceeded` or `credit_hold`), else insert the terms and the ledger entry. Idempotent per
  order (one `reserved` row at most). Triggers: `workflow.sylius_order_checkout.transition.complete`;
  Doctrine `onFlush` for a method switch to payment on account of a placed order (a refusal
  aborts the flush) and for a new payment on account created by the order processor (a refusal
  is logged).
- **Closing** (`close`): `UPDATE terms SET state = :new WHERE id = :id AND state = 'reserved'`;
  only the request that changed the row decrements the committed amount and writes the ledger
  entry. Triggers: `sylius_payment` completed `complete` (settled, reason `paid`), `fail` /
  `cancel` (released), `sylius_order` completed `cancel` (released), method switch away from
  payment on account (released). A refund comes after `complete`: nothing to release.
- **Exception mapping**: `TradeCreditReservationException` (also when wrapped by Messenger) →
  422 JSON for `/api/*`, redirect + flash for shop (checkout complete or order page) and admin.
- **Terms**: account terms override the policy; `PaymentTerms::dueDate()` from the completion
  date: net N days; N days then end of month; end of month then N days (`DateTimeImmutable`
  modifiers, leap years handled by PHP). Clock: `symfony/clock` (`clock` service).
- **Legal warnings**: France, article L441-10 of the Code de commerce: 60 days from the invoice
  date or 45 days end of month; lower sector caps (L441-11) configurable per channel. Warning
  only. Late payment penalties and the 40 EUR fixed recovery indemnity are mandatory mentions
  between professionals: added to BT-20 unless the Factur-X channel notes already carry them.
- **Owner model**: order-hold `AccountOwnerResolverInterface` (customer by default); a company
  plugin decorating it makes the account shared by the company.

## 4. Interfaces

- Shop: account menu "Trade credit" (`/{locale}/account/trade-credit`); thank-you page with the
  due date instead of a pay page.
- Shop API: `GET /api/v2/shop/trade-credit/statement` (signed-in customer, 404 without account).
- Admin: card on the customer page (open account, edit, ledger), accounts grid, payment terms
  CRUD, open items grid (state, overdue, search) with Mark as paid (CSRF, core `complete`
  transition), ledger per account.
- Factur-X (optional, `interface_exists`): `InvoicePaymentTermsProviderInterface`, priority 10.
- Command `tmonier:trade-credit:recalculate [--apply]`.

## 5. Out of scope (v1)

Payment CSV import, reminders, ageing buckets, external credit provider (v1.1); allocation of a
transfer to several invoices, late interest computation, CAMT/MT940, scoring, approval queue
when over limit (purchase-approval plugin), multi-currency, accounting exports, B2C / BNPL,
fees.

## 6. Tests

Unit: due dates (31/01 + 30 days, leap years, both end-of-month modes), legal warnings, account
terms, overdue by day, Factur-X provider texts, translations (7 locales), extension wiring.
Integration (test application, MySQL): reservation and terms, account terms override, refusal
above the limit, allow exceed, credit hold, concurrent reservation (stale entity, 0 rows
updated), idempotence, paid then refunded (one settlement), cancellation cascading (one
release), failed payment (release then new reservation), new payment beyond the limit, method
switches (release / reserve / refused switch not saved), unpaid-orders command, recalculation,
eligibility (no account, disabled, no company, hold, over limit, overdue, placed order, default
method). Functional: shop payment step, completion message, refused reservation redirect,
pay page, statement (with overdue item), anonymous; API statement (JWT), 401, 404, method
list, 422 on refused reservation and over limit, no payment request (capture / refund); admin
customer card and account creation with legal warning, open items filter and Mark as paid,
CSRF, policies and accounts grids, policy creation. Every test runs in a rolled-back
transaction and creates its own data.
