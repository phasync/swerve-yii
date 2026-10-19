#!/bin/sh
# Create the Yii test application in tests/Fixtures/app, for Yii version $1 (3): the framework's
# own skeleton (yiisoft/app), with this package installed from the checkout and the test routes
# of tests/Fixtures added. Idempotent.
set -eu
cd "$(dirname "$0")/Fixtures"
case "${1:-3}" in
    3) skeleton='yiisoft/app:^1.4' ;;
    *) echo "Unsupported Yii version: $1" >&2; exit 1 ;;
esac

[ -f app/composer.json ] || composer create-project --no-interaction --no-progress --no-install "$skeleton" app

# This package from the checkout: its composer.json and src/ (the whole checkout would contain
# the app itself, a loop for tools that follow symlinks), and its config/ for yiisoft/config
mkdir -p package
cp ../../composer.json package/
rm -rf package/src package/config && cp -r ../../src package/src && cp -r ../../config package/config

cd app
# The test additions: routes, an identity for yiisoft/user, and the one-line swerve.php
cp ../swerve.php swerve.php
mkdir -p src/SwerveTest
cp ../src/*.php src/SwerveTest/
cp ../di.php config/web/di/swerve-test.php
cp ../routes.php config/common/swerve-test-routes.php
grep -q swerve-test-routes config/common/routes.php \
    || sed -i "s|^return \[\$|return [\n    ...require __DIR__ . '/swerve-test-routes.php',|" config/common/routes.php
# The skeleton reads APP_ENV and APP_DEBUG from .env
[ -f .env ] || printf 'APP_ENV=prod\nAPP_DEBUG=false\n' > .env

composer config minimum-stability stable
composer config prefer-stable true
composer config repositories.swerve-yii '{"type": "path", "url": "../package", "options": {"symlink": true}}'
# The adapter's own requirements: swerve and swerve-psr15
composer config repositories.swerve-psr15 vcs https://github.com/phasync/swerve-psr15
composer require --no-interaction --no-progress -W \
    'phasync/swerve:^0.1' 'phasync/swerve-yii:*@dev' yiisoft/request-body-parser yiisoft/user
