<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Internal;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** @internal */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
