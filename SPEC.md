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

Amounts are integers in minor units, BIGINT columns (sums of reservations in low-unit
currencies overflow 32 bits; DBAL 3 hydrates them as strings, the getters cast). UUIDs let the reserver insert rows in SQL on every
platform (no sequence). The account FK of an open item has no cascade: an account with items
cannot be deleted.

## 3. Rules

- **Eligibility** (checker): the method has a policy (disabled: refused, never a plain
  Offline method) → order placed by its signed-in customer (`OrderOwnerChecker`: customer with
  a shop user, not `createdByGuest`; Sylius attaches an existing customer to a guest checkout
  from the typed e-mail, so a guest gets a generic refusal without account details) → order owner has an enabled
  account in the order currency → billing address company not empty (B2B) → not on hold → no
  overdue item when `block_when_overdue` (reserved past its due date, or released by a method
  switch / failed or cancelled payment while the order is still unpaid) → `allow_exceed` or
  `limit − committed + open reservation of this order ≥ order total`. A placed order already
  reserved with this very method keeps it.
- **Locking**: every reserver operation runs in one transaction that first locks the account
  row (`SELECT ... FOR UPDATE`); the open reservation of the order is then re-read with a
  locking read (a plain read could use the snapshot of an outer REPEATABLE READ transaction,
  e.g. the API bus). So an order never has two open reservations, and the recalculation reads
  the sum of the open items and writes the committed amount under the same lock.
- **Reservation** (`TradeCreditReserver::reserve`, in a transaction):
  `UPDATE account SET committed = committed + :amount WHERE id = :id AND enabled AND NOT
  credit_hold AND (allow_exceed OR credit_limit - committed >= :amount)`; 0 rows → refusal
  (`limit_exceeded` or `credit_hold`), else insert the terms and the ledger entry. Idempotent per
  order (one `reserved` row at most): a placed order reserved with the same method and amount
  is left as is; any other open reservation of the order (other method on account, cart left by
  a failed save) is released first. Never for a guest checkout. Terms of a re-reservation of a
  placed order: the previous terms of the same method (same due date), else the policy/account
  terms from the start date of the first reservation (never restarted by the customer). Triggers: `workflow.sylius_order_checkout.transition.complete`;
  Doctrine `onFlush` for a method switch to payment on account of a placed order (a refusal
  aborts the flush) and for a new payment on account created by the order processor (a refusal
  is logged).
- **Closing** (`close`): `UPDATE terms SET state = :new WHERE id = :id AND state = 'reserved'`;
  only the request that changed the row decrements the committed amount and writes the ledger
  entry. Driven by the open reservation made for the payment's method, never by the current
  policy state (a disabled or deleted policy keeps its items settleable). Payment `fail` /
  `cancel` listeners run at priority 200, before the core reprocesses the order (100), so that
  the order-hold kernel can replace a method that is no longer eligible on the next payment. Triggers: `sylius_payment` completed `complete` (settled, reason `paid`), `fail` /
  `cancel` (released), `sylius_order` completed `cancel` (released), method switch away from
  payment on account (released). A refund comes after `complete`: nothing to release.
- **Lost reservation**: `LostReservationCancellationExclusion` (order-hold) makes the unpaid
  orders expiration of an order that lost its reservation start at that release.
- **Admin guard**: a policy with open items can be disabled, not deleted (no bulk delete) nor
  moved to another payment method.
- **Exception mapping**: `TradeCreditReservationException` (also when wrapped by Messenger) →
  422 JSON for `/api/*`, redirect + flash for shop (checkout complete or order page) and admin.
- **Terms**: account terms override the policy; `PaymentTerms::dueDate()` from the completion
  date: net N days; N days then end of month; end of month then N days (`DateTimeImmutable`
  modifiers, leap years handled by PHP). Clock: `symfony/clock` (`clock` service).
- **Legal warnings**: France, article L441-10 of the Code de commerce: 60 days from the invoice
  date or 45 days end of month; lower sector caps (L441-11) configurable per channel, checked
  against the channels of the policy's method (accounts: strictest configured cap). Warning
  only. Late payment penalties and the 40 EUR fixed recovery indemnity are mandatory mentions
  between professionals: added to BT-20 unless the Factur-X channel notes already carry them.
- **Owner model**: order-hold `AccountOwnerResolverInterface` (customer by default); a company
  plugin decorating it makes the account shared by the company.

## 4. Interfaces

- Shop: account menu "Trade credit" (`/{locale}/account/trade-credit`); thank-you page with the
  due date instead of a pay page.
- Shop API: `GET /api/v2/shop/trade-credit/statement` (signed-in customer, 404 without account):
  `availableAmount` never negative, `enabled` and `creditHold` as on the shop page,
  `placedByYou` per open item (shared accounts: no link to other buyers' orders).
- Admin: card on the customer page (open account, edit, ledger), accounts grid, payment terms
  CRUD, open items grid (state, overdue, search) with Mark as paid (CSRF, core `complete`
  transition), ledger per account.
- Factur-X (optional, `interface_exists`): `InvoicePaymentTermsProviderInterface`, priority 10.
- Command `tmonier:trade-credit:recalculate [--apply]`: reservations older than an hour on
  orders that are not placed (or cancelled) are released, then each drifted committed amount
  is set under the account lock.

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
transaction and creates its own data. Review regressions (`ReviewFixesTest`): guest checkout
with a customer's e-mail, disabled / deleted policy, due date kept across switches and failed
payments, switch between two methods on account, a reservation committed by a second
connection seen under the lock, cart left with a reservation, stale reservations released by
the command, release at most once, recalculation under the lock, lost reservation and the
unpaid-orders cron, next payment beyond the limit, overdue not cleared by a switch, shared
account statement, BIGINT amounts.
