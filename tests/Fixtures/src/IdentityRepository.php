<?php

declare(strict_types=1);

namespace App\SwerveTest;

use Yiisoft\Auth\IdentityInterface;
use Yiisoft\Auth\IdentityRepositoryInterface;

/** Every name is a user, for the login tests. */
final class IdentityRepository implements IdentityRepositoryInterface
{
    public function findIdentity(string $id): ?IdentityInterface
    {
        return '' === $id ? null : new Identity($id);
    }
}
