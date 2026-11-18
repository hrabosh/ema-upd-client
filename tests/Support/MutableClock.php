<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class MutableClock implements ClockInterface
{
    public function __construct(public DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
