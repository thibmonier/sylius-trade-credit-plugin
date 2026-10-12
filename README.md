# Sylius Trade Credit Plugin

[![Build](https://github.com/thibmonier/sylius-trade-credit-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-trade-credit-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**B2B trade credit for Sylius 2: let approved business customers pay on account ("net 30",
"45 days end of month") within a credit limit.**

Business buyers rarely pay by card: they order, receive an invoice and pay it by bank transfer
30 or 45 days later. Sylius can already show an "Offline" payment method, but it does not know
who may use it, how much they still owe, when it is due, and its unpaid-orders job cancels
those orders after 5 days.

This plugin turns an Offline payment method into a real payment on account: a credit account
per customer with a limit, reservations made atomically at checkout, a statement in the
customer account, open items and "Mark as paid" in the back office, due dates and payment
terms in Factur-X invoices. B2B only: this is not consumer credit.

## Features

| Need | What the plugin does |
|---|---|
| Decide who may pay on account | **Trade credit account** per customer (admin, customer page): credit limit and currency, *credit hold* (manual block), *allow exceeding the limit*, *block when overdue*, own terms or the terms of the payment method, internal note. |
| Offer "pay on account" with terms | **Payment terms** on an Offline payment method: net days and end-of-month mode (*net N days*, *N days end of month*, *end of month + N days*). The order stays *awaiting payment*: no new gateway. |
| Show the method only when it is allowed | Through [tmonier/sylius-order-hold-plugin](https://github.com/thibmonier/sylius-order-hold-plugin): hidden in the shop and API method lists, never chosen as the default method, refused on method changes and at checkout completion (with a readable message), when the customer has no account, the order is not billed to a company, the account is on hold, has overdue items (if it blocks them) or not enough available credit. |
| Never exceed the limit, even with concurrent orders | **Atomic reservation** when the checkout is completed: `UPDATE ... SET committed = committed + :total WHERE ... AND (allow_exceed OR limit - committed >= :total)`. A refusal (another order used the credit meanwhile) is a redirect with a message in the shop and a 422 in the API, never a server error. Switching a placed order to or from payment on account reserves or releases. |
| Free the credit once, and only once | The reservation is **settled** when the payment is completed (paid), **released** when the order is cancelled or the payment fails or is cancelled. A reservation is closed exactly once whatever the events (paid then refunded, order cancellation cascading to the payment...). |
| Keep orders on 30-day terms | Orders with a reservation are **excluded from `sylius:cancel-unpaid-orders`** (in SQL). |
| No card payment for an order on account | The shop pay page shows the thank-you page with the due date; the shop API refuses payment requests (customers cannot start, complete or refund their own payment). |
| Know what each customer owes | **Statement** page in the customer account (limit, committed, available, open items with order, due date, overdue flag, invoice PDF link with sylius/invoicing-plugin) and read-only **shop API** `GET /api/v2/shop/trade-credit/statement`. |
| Collect payments | Admin **open items** grid (state and *overdue* filters, search by order number or e-mail) with **Mark as paid** (core `sylius_payment` `complete` transition: invoicing listeners run as for any payment), **ledger** of every movement per account. |
| Stay within the law | Warnings when terms exceed 60 days or 45 days end of month (France, L441-10; per channel limits for the sector caps of L441-11). |
| Factur-X invoices | With [tmonier/sylius-facturx-plugin](https://github.com/thibmonier/sylius-facturx-plugin) 1.1: due date (BT-9) and payment terms (BT-20) of the order, with the late payment penalties and 40 EUR recovery indemnity mentions. |
| Detect drift | `tmonier:trade-credit:recalculate` compares committed amounts with the open items (dry run; `--apply` fixes and logs an adjustment). |

Also included: demo fixture, translations in English, French, German, Spanish, Italian, Dutch
and Polish, Twig Hooks, MySQL, MariaDB and PostgreSQL support.

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ (8.3+ with Sylius 2.3) |
| Sylius | 2.2.9+, 2.3 |
| Symfony | 6.4, 7.4 (Sylius 2.2); 6.4, 7.4, 8.0 (Sylius 2.3) |
| tmonier/sylius-order-hold-plugin | 1.0 (installed as a dependency) |

The checkout, order and payment graphs must use the Symfony Workflow adapter (Sylius 2
default); order-hold checks it when the container is compiled. Sylius 2.2.9 is the first 2.2
release with the fixes for GHSA-2rv4-pjmm-7fxf and GHSA-vv4h-q2x8-74g4 (payment requests).

## Installation

1. Require the package:

    ```bash
    composer require tmonier/sylius-trade-credit-plugin
    ```

2. Register both bundles in `config/bundles.php`:

    ```php
    Tmonier\SyliusOrderHoldPlugin\TmonierSyliusOrderHoldPlugin::class => ['all' => true],
    Tmonier\SyliusTradeCreditPlugin\TmonierSyliusTradeCreditPlugin::class => ['all' => true],
    ```

3. Import the configuration and the routes:

    ```yaml
    # config/packages/tmonier_sylius_trade_credit.yaml
    imports:
        - { resource: "@TmonierSyliusTradeCreditPlugin/config/config.yaml" }
    ```

    ```yaml
    # config/routes/tmonier_sylius_trade_credit.yaml
    tmonier_sylius_trade_credit:
        resource: "@TmonierSyliusTradeCreditPlugin/config/routes.yaml"
    ```

4. Run the migration (plugin tables only, core tables are not changed):

    ```bash
    bin/console doctrine:migrations:migrate
    ```

5. In the admin: create an **Offline** payment method (e.g. "On account (net 30)"), then
   *Customers > Payment terms > Create* for it; open a trade credit account from the customer
   page of each approved business customer.

## Configuration

Optional, with the defaults:

```yaml
tmonier_sylius_trade_credit:
    legal_limits:
        net_days: 60            # warning above 60 days from the invoice date
        end_of_month_days: 45   # warning above 45 days end of month
        channels:               # per channel code, e.g. a sector cap (L441-11)
            WHOLESALE: { net_days: 30, end_of_month_days: 30 }
```

A payment terms policy is checked against the caps of the channels of its payment method; an
account with its own terms against the strictest configured cap.

Resources (`account`, `payment_terms_policy`, `order_payment_terms`, `ledger_entry`) can be
overridden like any Sylius resource (`tmonier_sylius_trade_credit.resources.account.classes.model`, ...).

## How it works

- **Who may pay on account**: the payment method has an enabled payment terms policy, the
  order is placed by its **signed-in customer** (never a guest checkout, even with the e-mail of
  a customer with an account: such orders get a generic refusal that reveals nothing), the
  order owner (order-hold `AccountOwnerResolverInterface`: the customer, a company once a
  company plugin decorates it) has an enabled account in the order currency, the **billing
  address has a company name** (B2B check), the account is not on hold, has no overdue open
  item when it blocks them, and the available credit covers the order total (unless exceeding
  is allowed). Accounts are opened by staff, who verify the customer. An order that left
  payment on account (switched to a method that is not on account, failed payment) and is still
  unpaid after its due date still counts as overdue: it is listed as overdue on the statement,
  the customer card and the admin open items (where it can be marked as paid). Switching to
  another method on account is not leaving: the new terms apply.
- **Disabling or deleting payment terms**: a disabled policy refuses its method (it never
  becomes a plain Offline method for everyone); open items stay settleable, because closing is
  driven by the reservation, not by the current policy. A policy with open items cannot be
  deleted nor moved to another method.
- **Terms**: the account's net days when set (with its own end-of-month mode, else the
  policy's), else the policy's. The due date is computed from the checkout completion date and
  frozen with the reserved amount. A new reservation of the same order (after a failed payment
  or a method switch) never restarts the terms: same due date with the same method, same start
  date with another method on account.
- **States of an open item**: *open* (reserved) → *paid* (settled) or *released*. The committed
  amount only changes in SQL by these transitions; every change is a ledger entry.
- **Refunds**: a refund only happens after a payment was completed, so the credit was already
  freed: nothing changes.

## Customisation

| Hook | Content |
|---|---|
| `sylius_admin.customer.show.content.sections` → `tmonier_trade_credit` | Trade credit card of the customer page |
| `tmonier_trade_credit_admin.account.{create,update}.content.form.sections.general` → `fields` | Account form |
| `tmonier_trade_credit_admin.payment_terms_policy.{create,update}.content.form.sections.general` → `fields` | Payment terms form |

Services: `tmonier_trade_credit.reserver` (only writer of committed amounts),
`tmonier_trade_credit.provider.account`, `tmonier_trade_credit.order_hold.eligibility_checker`
(can be decorated to add rules), `tmonier_trade_credit.facturx.payment_terms_provider`.

## Demo data

The `tmonier_trade_credit` fixture (add it to a fixture suite) creates the "On account (net 30)"
method and its policy, the verified B2B customer `b2b@example.com` / `sylius` (company "Acme
Industries", 5,000.00 limit) with two orders on account, one of them overdue, and verifies the
shop users of the fixtures (the demo channel requires a verified account to sign in).

## Limitations

- **Single currency** per account (the channel base currency); no partial payments: an open
  item is paid in full with "Mark as paid".
- No reminders, ageing buckets, payment CSV import, ERP connector, interest computation or
  accounting export in v1. No fees or surcharges.
- In the shop, the reservation (its own SQL transaction) and the save of the completed order are
  two steps: if saving the order fails afterwards, the reservation left on the cart is replaced
  when the checkout is completed again, and released after an hour by
  `tmonier:trade-credit:recalculate --apply` (run it from a daily cron; it locks each account,
  so it is safe while customers order). Likewise, if the save of a method switch fails after
  the new reservation was made, paying the order still settles it and the command reserves
  again for the method of the payment (or releases). In the API, the command bus transaction covers both.
- A new payment created after a failed one keeps the method on account and reserves again; if
  the credit is no longer available, order-hold replaces the method by an eligible one, or the
  payment stays without reservation and the customer is asked to choose another method.
- An order that loses its reservation (payment failed or cancelled, switched to another method)
  becomes a regular unpaid order: `sylius:cancel-unpaid-orders` counts its expiration period
  from that release.
- With a shared (company) account, the statement lists the orders of the other buyers without
  link nor invoice (`placedByYou` false in the API).
- The legal warnings are not legal advice and do not block anything.
- Administrators can still change payment methods and complete payments: the reservations follow.

## Development

```bash
docker compose up -d mysql mailhog   # host ports in compose.override.yml (gitignored)
composer database-reset              # dev database with fixtures (APP_ENV=test for the test one)
vendor/bin/phpunit                   # every suite once (unit, integration, functional)
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

See [SPEC.md](SPEC.md) for the research and design decisions.

## License

MIT, see [LICENSE](LICENSE).
