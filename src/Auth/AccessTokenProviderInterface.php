<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Auth;

interface AccessTokenProviderInterface
{
    public function getAccessToken(): string;
}
