# Parallel test runs

Every suite calls `migrate:fresh`, so two runs against the same database will
drop each other's tables mid-migration and produce confusing
"table doesn't exist" errors.

To run a suite in isolation, point it at one of the pre-created private
databases:

```
DB_DATABASE=goldb2b_test_a vendor/bin/phpunit app/Modules/Ledger/Tests
DB_DATABASE=goldb2b_test_b vendor/bin/phpunit app/Modules/Trading/Tests
```

`goldb2b_test_a` through `goldb2b_test_f` exist locally. CI uses the single
`goldb2b_test` because jobs there do not overlap.
