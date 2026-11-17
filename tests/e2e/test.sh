#!/usr/bin/env bash
#
# Seed the multisite, then duplicate a child site and the main site and check the copies.
# Runs inside the DDEV web container (see `ddev e2e-test`).
#
set -euo pipefail

REPO_ROOT="/var/www/html"
DOCROOT="${REPO_ROOT}/tests/wordpress"
MAIN_URL="${DDEV_PRIMARY_URL:?DDEV_PRIMARY_URL is not set, run this script through ddev}"
ASSERT="${REPO_ROOT}/tests/e2e/assert-duplicate.php"

bash "${REPO_ROOT}/tests/e2e/seed.sh"

cd "${DOCROOT}"

echo "==> Duplicate the child site, with a new admin email"
wp site duplicate child-copy dup-admin@example.test --url="${MAIN_URL}/child"
wp eval-file "${ASSERT}" child child-copy dup-admin@example.test --url="${MAIN_URL}"

echo "==> Duplicate the main site, without email"
wp site duplicate main-copy --url="${MAIN_URL}"
wp eval-file "${ASSERT}" main main-copy --url="${MAIN_URL}"

echo "==> All e2e scenarios passed"
