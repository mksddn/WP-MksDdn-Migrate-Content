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

### Commands

```bash
composer install
composer test:unit
make install-wp-tests   # once, needs MySQL
composer test:integration
composer test
```
