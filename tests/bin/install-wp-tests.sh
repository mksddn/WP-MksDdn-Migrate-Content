#!/usr/bin/env bash
#
# Install WordPress core + PHPUnit test library into repo tmp/.
# Usage: install-wp-tests.sh [db-name] [db-user] [db-pass] [db-host] [wp-version] [skip-db-create]
#

set -e

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-127.0.0.1}
WP_VERSION=${5:-7.1}
SKIP_DB_CREATE=${6:-false}

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
TMPDIR="${ROOT_DIR}/tmp"
mkdir -p "${TMPDIR}"

WP_TESTS_DIR="${WP_TESTS_DIR:-${TMPDIR}/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-${TMPDIR}/wordpress}"

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL "$1" -o "$2"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$2" "$1"
	else
		echo "Error: need curl or wget" >&2
		exit 1
	fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	WP_TESTS_TAG="tags/$WP_VERSION"
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	echo "Unsupported WP_VERSION: $WP_VERSION" >&2
	exit 1
fi

install_wp() {
	if [ -f "${WP_CORE_DIR}/wp-settings.php" ]; then
		echo "WordPress already installed at ${WP_CORE_DIR}"
		return
	fi

	mkdir -p "${WP_CORE_DIR}"
	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		download https://wordpress.org/nightly-builds/wordpress-latest.zip "${TMPDIR}/wordpress.zip"
		unzip -q "${TMPDIR}/wordpress.zip" -d "${TMPDIR}/wordpress-unzip"
		mv "${TMPDIR}/wordpress-unzip/wordpress/"* "${WP_CORE_DIR}/"
		rm -rf "${TMPDIR}/wordpress-unzip" "${TMPDIR}/wordpress.zip"
	else
		local ARCHIVE_NAME="wordpress-${WP_VERSION}"
		download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" "${TMPDIR}/wordpress.tar.gz" || \
			download "https://wordpress.org/wordpress-${WP_VERSION}.0.tar.gz" "${TMPDIR}/wordpress.tar.gz" || \
			download "https://wordpress.org/latest.tar.gz" "${TMPDIR}/wordpress.tar.gz"
		tar --strip-components=1 -zxmf "${TMPDIR}/wordpress.tar.gz" -C "${WP_CORE_DIR}"
		rm -f "${TMPDIR}/wordpress.tar.gz"
	fi
	echo "WordPress installed at ${WP_CORE_DIR}"
}

install_test_suite() {
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	if [ ! -f "${WP_TESTS_DIR}/includes/functions.php" ]; then
		rm -rf "${WP_TESTS_DIR}"
		mkdir -p "${WP_TESTS_DIR}"

		if [[ $WP_TESTS_TAG == 'trunk' ]]; then
			ref=trunk
			archive_url="https://github.com/WordPress/wordpress-develop/archive/refs/heads/${ref}.tar.gz"
		elif [[ $WP_TESTS_TAG == branches/* ]]; then
			ref=${WP_TESTS_TAG#branches/}
			archive_url="https://github.com/WordPress/wordpress-develop/archive/refs/heads/${ref}.tar.gz"
		else
			ref=${WP_TESTS_TAG#tags/}
			archive_url="https://github.com/WordPress/wordpress-develop/archive/refs/tags/${ref}.tar.gz"
		fi

		download "${archive_url}" "${TMPDIR}/wordpress-develop.tar.gz"
		tar -zxmf "${TMPDIR}/wordpress-develop.tar.gz" -C "${TMPDIR}"
		mv "${TMPDIR}/wordpress-develop-${ref}/tests/phpunit/includes" "${WP_TESTS_DIR}/"
		mv "${TMPDIR}/wordpress-develop-${ref}/tests/phpunit/data" "${WP_TESTS_DIR}/"
		rm -rf "${TMPDIR}/wordpress-develop-${ref}" "${TMPDIR}/wordpress-develop.tar.gz"
		echo "Test suite installed at ${WP_TESTS_DIR}"
	fi

	if [ ! -f "${WP_TESTS_DIR}/wp-tests-config.php" ]; then
		if [[ $WP_TESTS_TAG == 'trunk' ]]; then
			ref=trunk
		elif [[ $WP_TESTS_TAG == branches/* ]]; then
			ref=${WP_TESTS_TAG#branches/}
		else
			ref=${WP_TESTS_TAG#tags/}
		fi

		download "https://raw.githubusercontent.com/WordPress/wordpress-develop/${ref}/wp-tests-config-sample.php" \
			"${WP_TESTS_DIR}/wp-tests-config.php"

		WP_CORE_DIR_ESC=$(echo "${WP_CORE_DIR}" | sed "s:/\+$::")
		WP_CORE_DIR_ESCAPED=$(printf '%s' "$WP_CORE_DIR_ESC" | sed 's/[\\|&]/\\&/g')
		sed $ioption "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR_ESCAPED}/'|" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed $ioption "s|__DIR__ . '/src/'|'${WP_CORE_DIR_ESCAPED}/'|" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed $ioption "s/youremptytestdbnamehere/${DB_NAME}/" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed $ioption "s/yourusernamehere/${DB_USER}/" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed $ioption "s/yourpasswordhere/${DB_PASS}/" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed $ioption "s|localhost|${DB_HOST}|" "${WP_TESTS_DIR}/wp-tests-config.php"
		rm -f "${WP_TESTS_DIR}/wp-tests-config.php.bak"
		echo "Test suite configured"
	fi
}

install_db() {
	if [ "${SKIP_DB_CREATE}" = "true" ]; then
		echo "Skipping database creation"
		return 0
	fi

	local EXTRA=""
	local PARTS=(${DB_HOST//\:/ })
	local DB_HOSTNAME=${PARTS[0]}
	local DB_SOCK_OR_PORT=${PARTS[1]}

	if [ -n "${DB_HOSTNAME}" ]; then
		if [[ ${DB_SOCK_OR_PORT} =~ ^[0-9]+$ ]]; then
			EXTRA=" --host=${DB_HOSTNAME} --port=${DB_SOCK_OR_PORT} --protocol=tcp"
		elif [ -n "${DB_SOCK_OR_PORT}" ]; then
			EXTRA=" --socket=${DB_SOCK_OR_PORT}"
		else
			EXTRA=" --host=${DB_HOSTNAME} --protocol=tcp"
		fi
	fi

	# Non-interactive: drop and recreate when present (safe for dedicated test DB).
	mysqladmin drop "${DB_NAME}" -f --user="${DB_USER}" --password="${DB_PASS}"${EXTRA} 2>/dev/null || true
	mysqladmin create "${DB_NAME}" --user="${DB_USER}" --password="${DB_PASS}"${EXTRA}
	echo "Database ${DB_NAME} ready"
}

install_wp
install_test_suite
install_db
echo "Done. Export WP_TESTS_DIR=${WP_TESTS_DIR} WP_CORE_DIR=${WP_CORE_DIR}"
