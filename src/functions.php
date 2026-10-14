<?php

namespace Swerve\Yii;

use Swerve\Psr15\Bridge;
use Swerve\RequestHandler;

/**
 * The entry point of the `yii` swerve adapter, which swerve's workers call once each, with the
 * application directory (see the `extra.swerve` section of this package's composer.json): the
 * application served by a {@see Handler} through swerve-psr15's Bridge, after the skeleton's
 * `src/bootstrap.php` (its .env) when there is one.
 *
 * @param string $appDir the application directory: where swerve was started
 */
function entry(string $appDir): RequestHandler
{
    require_once $GLOBALS['_composer_autoload_path'] ?? "$appDir/vendor/autoload.php";
    \is_file("$appDir/src/bootstrap.php") && require_once "$appDir/src/bootstrap.php";

    return Bridge::wrap(new Handler($appDir));
}
