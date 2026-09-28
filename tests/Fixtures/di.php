<?php

declare(strict_types=1);

use App\SwerveTest\IdentityRepository;
use Yiisoft\Auth\IdentityRepositoryInterface;
use Yiisoft\Definitions\Reference;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

// yiisoft/user, set up as its README says
return [
    IdentityRepositoryInterface::class => IdentityRepository::class,
    CurrentUser::class                 => [
        'withSession()' => [Reference::to(SessionInterface::class)],
        'reset'         => function () {
            $this->clear();
        },
    ],
];
