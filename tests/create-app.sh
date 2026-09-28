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
# the app itself, a loop for tools that follow symlinks)
mkdir -p package
cp ../../composer.json package/
rm -rf package/src && cp -r ../../src package/src

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

composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.swerve-yii '{"type": "path", "url": "../package", "options": {"symlink": true}}'
composer require --no-interaction --no-progress -W \
    'phasync/swerve-yii:*@dev' yiisoft/request-body-parser yiisoft/user
