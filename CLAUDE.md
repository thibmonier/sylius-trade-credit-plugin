# CLAUDE.md

Sylius 2 plugin: B2B trade credit (payment on account): credit accounts, payment terms on
Offline methods, atomic reservations, statement, admin open items, Factur-X terms. First
consumer of tmonier/sylius-order-hold-plugin. Design: `SPEC.md`. Public docs: `README.md`.

## Before publishing

- **Remove the composer path repository** (`repositories` in `composer.json`, pointing to
  `../sylius-order-hold-plugin`) once tmonier/sylius-order-hold-plugin 1.0 is on Packagist.
  CI already ignores it (`composer config --unset repositories.order-hold` in
  `.github/workflows/build.yaml`): the build needs order-hold 1.0 on Packagist; drop that step
  together with the repository.
- After `composer update tmonier/sylius-order-hold-plugin` (path repository, mirrored copy),
  delete `vendor/tmonier/sylius-order-hold-plugin/vendor` and `.../var` (copied from the
  order-hold working copy, disk space).

## Commands

PHP needs more than 128 MB to compile the test application container: prefix console and
PHPStan calls with `php -d memory_limit=2G` when running locally.

```bash
docker compose up -d mysql mailhog        # ports come from compose.override.yml (gitignored)
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit   # integration + functional, test DB + fixtures
vendor/bin/phpstan analyse                # level max, src + tests
vendor/bin/ecs check
vendor/bin/console lint:container
vendor/bin/console tmonier:trade-credit:recalculate
```

Local env overrides (gitignored): `compose.override.yml` (MySQL published on 3328, MailHog on
8047/1047), `tests/TestApplication/.env.local` (DATABASE_URL + MAILER_DSN) and
`.env.test.local` (DATABASE_URL). Databases: `tmonier_sylius_trade_credit_dev` / `_test`, loaded
with the default fixture suite + `tmonier_trade_credit` (method "On account (net 30)",
`b2b@example.com` / `sylius` with an account and two open items, one overdue). Dev server:
`symfony server:start -d --port=8094 --no-tls` (admin `sylius` / `sylius`).

## Architecture

- `Entity/` (XML mappings in `config/doctrine`, migration `src/Migrations`, Schema API):
  `TradeCreditAccount`, `PaymentTermsPolicy`, `OrderPaymentTerms` (UUID), `TradeCreditLedgerEntry` (UUID).
- `Reservation/TradeCreditReserver`: the only writer of committed amounts, terms and ledger
  (SQL, `transactional()`): `reserve()`, `close()` (idempotent), `adjust()`, `committedAmount()`.
  Refreshes managed entities, except while Doctrine flushes (`$refreshEntities = false`).
- `EventListener/TradeCreditWorkflowListener` (checkout complete transition, payment
  complete / fail / cancel, order cancel), `PaymentMethodSwitchListener` (Doctrine onFlush),
  `ReservationExceptionListener` (kernel.exception: 422 API, redirect + flash elsewhere).
- `OrderHold/`: eligibility checker, payment entry blocker, unpaid-orders query extension
  (parameters prefixed `tmonier_tc_`, never `tmonier_oh_`). Tagged, never a decorator.
- `Account/`: account provider (order-hold owner resolver), policy provider (memoized,
  `kernel.reset`), B2B check (billing company). `Terms/`: `PaymentTerms::dueDate()`,
  `EndOfMonthMode`, `LegalTermsChecker`.
- `Statement/` + `Controller/Shop/StatementController` + `Api/` (API Platform XML in
  `config/api_platform`): statement of the signed-in customer.
- Admin: resource routes `config/routes/admin.yaml`, grids `config/grids`, hooks
  `config/twig_hooks/admin.yaml`, `Controller/Admin/MarkAsPaidController`, `Grid/Filter/OverdueFilter`,
  `Factory/TradeCreditAccountFactory::createForCustomer()`, menus.
- `FacturX/TradeCreditInvoicePaymentTermsProvider` (`config/services/facturx.php`, loaded when
  the Factur-X interface exists; facturx is require-dev, not enabled in the test application).
- `Fixture/TradeCreditFixture` (demo).
- Tests: `tests/Functional/TradeCreditTestTrait` builds carts and orders through the real
  checkout state machine in the rolled-back transaction (own zone, shipping and payment
  methods, product, customer, account). The test application registers both bundles and
  `dbal_savepoints.php` (DBAL 3 nests the reserver's transactions with savepoints).

## Rules

- Never update `committed_amount` through the entity; never release without `close()`.
- Labels in `messages`, flashes in `flashes`, refusals in `validators` (also used by the API
  exception mapping); every key in en, fr, de, es, it, nl, pl (`TranslationsTest`, which also
  checks the keys used in src/templates/config). Translations are generated from a script kept
  outside the repository: edit the YAML files directly now.
- Tests never depend on the random Sylius fixtures (`NoSkippedTestsTest`).
