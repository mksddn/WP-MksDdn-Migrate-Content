ROOT := $(CURDIR)
TMP  := $(ROOT)/tmp
WP_VERSION ?= 7.1
DB_NAME ?= wordpress_test
DB_USER ?= root
DB_PASS ?= root
DB_HOST ?= 127.0.0.1

.PHONY: composer-install install-wp-tests test-unit test-integration test php74-lint

composer-install:
	composer install

install-wp-tests:
	@mkdir -p "$(TMP)"
	bash "$(ROOT)/tests/bin/install-wp-tests.sh" "$(DB_NAME)" "$(DB_USER)" "$(DB_PASS)" "$(DB_HOST)" "$(WP_VERSION)"

test-unit:
	composer test:unit

test-integration:
	composer test:integration

test: test-unit test-integration

php74-lint:
	@find mksddn-migrate-content/trunk -name '*.php' -print0 | xargs -0 -n1 php -l
