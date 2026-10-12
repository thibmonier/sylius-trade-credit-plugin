# Changelog

## 1.1.0 (2026-10-12)

### Added

- **Payments CSV import**: `tmonier:trade-credit:import-payments <file> [--apply]` settles the
  open items paid by bank transfer (`order_number`, `amount`, optional `currency`). Exact
  match on order number and amount, dry run by default, all or nothing, idempotent (orders
  already paid are reported and skipped). "Mark as paid" and the import share
  `OpenItemSettler`.
- **Payment reminders**: `tmonier:trade-credit:send-reminders [--dry-run]`, levels configurable
  with `tmonier_sylius_trade_credit.reminders.levels` (days relative to the due date, default
  `[-5, 1, 15, 30]`). One reminder per open item and level (new table
  `tmonier_trade_credit_reminder`, unique per order, due date and level), only the highest
  level reached, e-mail `tmonier_trade_credit_payment_reminder` in 7 languages with the L441-10
  mentions once overdue.
- **Ageing buckets** (not due, 1-30, 31-60, 61-90, over 90 days): on the shop statement, in the
  shop API (`ageing`, and `daysPastDue` per open item), on the admin customer card and for all
  accounts (per currency) above the open items grid.
- **`ExternalCreditProviderInterface`**: an ERP can provide the credit limit and the amount owed
  outside the shop (`ExternalCreditPosition`); used by the eligibility checker, the atomic
  reservation, the statement and the API (`externalCommittedAmount`). Default
  `InternalCreditProvider`: Sylius figures only. A failing provider refuses payment on account
  (`payment_method.unavailable` / `reservation.unavailable`).

### Changed

- `TradeCreditStatementView` (shop API) has three new fields: `externalCommittedAmount`,
  `ageing`, `openItems[].daysPastDue`.
- `OrderPaymentTermsRepositoryInterface` has new methods (`findRemindable()`,
  `findLastForOrder()`, `sumAgeingByCurrency()`): a custom repository implementing the
  interface must add them (extending `OrderPaymentTermsRepository` is enough).
- `OpenItemSettler::settle()` locks and re-reads the payment and flushes in its own transaction:
  concurrent settlements (Mark as paid, import) complete a payment once.
- `Statement` has `position` (`CreditPosition`) and `ageing`; `StatementBuilder`,
  `TradeCreditReserver`, `TradeCreditEligibilityChecker` and `MarkAsPaidController` have new
  constructor arguments (services are wired by the plugin).

### Fixed

- PostgreSQL: the ids of the accounts and payment terms policies are now sequence-based like
  the ORM mapping expects (migration `Version20261012200100`; the 1.0 IDENTITY/SERIAL columns
  worked but `doctrine:schema:validate` reported a difference). Nothing changes on MySQL and
  MariaDB.

Upgrade: run `bin/console doctrine:migrations:migrate` (two migrations), then schedule
`tmonier:trade-credit:send-reminders` daily if you want reminders (set
`reminders.levels: []` to disable them in the command).

## 1.0.0 (2026-10-12)

First release.
