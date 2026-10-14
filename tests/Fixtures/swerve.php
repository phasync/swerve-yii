<?php

require __DIR__ . '/src/bootstrap.php';

return Swerve\Psr15\Bridge::wrap(new Swerve\Yii\Handler(__DIR__));
