<?php

declare(strict_types=1);

namespace App\SwerveTest;

use Yiisoft\Auth\IdentityInterface;

final class Identity implements IdentityInterface
{
    public function __construct(private readonly string $id)
    {
    }

    public function getId(): ?string
    {
        return $this->id;
    }
}
