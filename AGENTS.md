# Agent notes — MksDdn Migrate Content

## Tests Runtime

This project uses `doiftrue/unitest-wp-copy` with `WP_Mock` for PHPUnit **unit** tests, and the official WordPress PHPUnit suite for **integration** tests.

Before writing or changing tests:

1. Read `vendor/doiftrue/unitest-wp-copy/README.md` to understand the unit test runtime.
2. Check `vendor/doiftrue/unitest-wp-copy/SYMBOLS-INFO.md` for the WordPress functions and classes available in the runtime. Its first section lists runtime-adapted classes (like `\Unitest_WP_Copy\wpdb__Runtime`) with their public methods — use or extend them instead of WP_Mock.
3. Use `WP_Mock` when a runtime function listed as mockable needs to be mocked.
4. If a symbol is missing from the runtime and is not mockable, put the test in `tests/Integration/` (full WordPress + MySQL). Do not write a weak unit stub for it.
5. Never mix `WP_Mock\Tools\TestCase` and `WP_UnitTestCase` in the same PHPUnit process. Use `phpunit.unit.xml.dist` or `phpunit.integration.xml.dist`.
6. Keep the coverage matrix in `tests/COVERAGE.md` up to date when adding classes or tests.
7. Pipeline tests must be **behavioral**: create live data, run real export to a file, mutate or wipe state, run real import, assert restored state. `instanceof`, DI resolve, and “class constructs” only count as **wired** coverage — they do not close export/import behavior.

### Commands

```bash
composer install
composer test:unit
make install-wp-tests   # once, needs MySQL
composer test:integration
composer test
composer test:coverage:unit          # needs pcov; clover in tmp/coverage/
composer test:coverage:integration
# Optional mutation testing (PHP >= 8.3; not in default composer.lock / CI):
composer require --dev infection/infection:^0.29
composer infection:unit
```

### Coverage and infection notes

- PHPUnit configs include a `<coverage>` filter limited to `mksddn-migrate-content/trunk/includes`.
- `phpunit.xml.dist` is a symlink to `phpunit.unit.xml.dist` so Infection discovers the unit suite.
- Infection is **optional** and not part of default `composer install` (keeps the lock installable on PHP 8.1). Install it locally on PHP ≥8.3 with `composer require --dev infection/infection:^0.29`, then run via `bin/infection-unit.php` (or `composer infection:unit`): plugin sources call `exit` when `ABSPATH` is undefined, and Infection’s ReflectionVisitor autoloads those classes.
- Infection is **unit-only** and whitelist-scoped in `infection.json5` (not run in CI). Thresholds: `minMsi` / `minCoveredMsi` = 68.
- Line-% from clover supplements the behavioral/wired labels in `tests/COVERAGE.md`; it does not replace them.
