<?php

declare(strict_types=1);

use Swerve\Yii\Handler;
use Yiisoft\ErrorHandler\Event\ApplicationError;

// A worker that stops ends its requests with a WorkerStoppingException, which the ErrorCatcher
// middleware turns into a 500: the handler notes it here and throws it on after the request
return [
    ApplicationError::class => [
        static fn (ApplicationError $event) => Handler::noteError($event->getThrowable()),
    ],
];
