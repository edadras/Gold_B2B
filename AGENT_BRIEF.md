# Agent brief — Gold B2B implementation

You are implementing ONE module of a B2B gold trading platform. The full design
lives in `docs/`. Read the documents named in your task before writing code.

## Non-negotiable rules

1. **No float/double anywhere in a financial path.** All money is `int` rial,
   all weight is `int` milligrams, purity is `int` ten-thousandths. Use
   `App\Modules\Shared\Support\IntMath` for any multiply-then-divide.
2. **Never UPDATE or DELETE `ledger_entries` or `audit_logs`.** Corrections are
   new reversing rows only.
3. **No HTTP calls, queue dispatch, notifications or broadcasts inside a DB
   transaction.** Fire events AFTER commit.
4. **Lock in ascending id order**, always, to avoid deadlocks.
5. **Every financial write** goes through a transaction + pessimistic lock.
6. **No magic numbers** — read from `config('goldb2b.*')` or the
   `system_settings` table.
7. Cross-module access ONLY via the target module's `Contracts/` interfaces or
   domain events. Never touch another module's Eloquent model directly.
8. `declare(strict_types=1);` in every PHP file. Full type hints on every method.

## What already exists (do not recreate)

- `app/Modules/Shared/ValueObjects/` — `Weight`, `FineWeight`, `Purity`,
  `Rial`, `PricePerFineGram`, `NumericInput`
- `app/Modules/Shared/Calculation/` — `TradeValueCalculator`, `TradeValuation`,
  `FeeTerms`, `TaxTerms`
- `app/Modules/Shared/Support/IntMath` — overflow-safe integer arithmetic
- `app/Modules/Shared/Exceptions/` — `DomainException` base +
  `InsufficientBalanceException`, `LimitExceededException`,
  `InvalidStateTransitionException`, `UnbalancedTransactionException`,
  `ConcurrentModificationException`, `OperationNotPermittedException`
- `app/Modules/Shared/Concerns/ModuleServiceProvider` — extend this for your
  module's provider
- `config/goldb2b.php`

Use these. If you need a new shared primitive, add it under `Shared/` and say so
in your report.

## Module layout you must follow

```
app/Modules/<Name>/
├── Contracts/            public interfaces + DTOs other modules may use
├── Domain/               enums, value objects, state machines, pure logic
├── Application/          services / command handlers (own the transaction)
├── Infrastructure/       Eloquent models, repositories
├── Http/                 Controllers, Requests, Resources, routes.php
├── Events/               readonly event classes (scalars only, no models)
├── Listeners/
├── Console/              artisan commands
├── Database/Migrations/  numbered so ordering is deterministic
├── Database/Factories/
├── Tests/
└── <Name>ServiceProvider.php
```

Migration filenames: `YYYY_MM_DD_HHMMSS_*.php`. Use the date prefixes assigned in
your task so modules migrate in dependency order.

## Environment

- PHP 8.4, Laravel 11, MariaDB 10.11 (stands in for MySQL 8 locally), Redis 7
- DB `goldb2b` / user `goldb2b` / pass `goldb2b`, already created
- Run migrations with `php artisan migrate`
- Run tests with `vendor/bin/phpunit <path>`

MariaDB note: `CHECK` constraints and `SELECT ... FOR UPDATE SKIP LOCKED` work.
Native table PARTITIONING is supported but skip it locally — write the schema
without `PARTITION BY` and leave a comment pointing at ADR-012.

## Definition of done

- `php -l` clean on every file
- `php artisan migrate:fresh` succeeds with your migrations present
- Your module's tests pass
- Report: files created, decisions made, anything you could not finish and why

Do not commit to git. Do not modify files outside your module (exception: adding
your provider to `bootstrap/providers.php` is handled by the coordinator — do
NOT edit that file).
