<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Internal;

use SensitiveParameter;

/** @internal */
final class BearerToken
{
    private function __construct() {}

    public static function isValid(#[SensitiveParameter] string $value): bool
    {
        return preg_match('#\A[A-Za-z0-9._~+/-]+=*\z#', $value) === 1;
    }
}
