<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use DateTimeImmutable;

final class Event
{
    public function __construct(
        public readonly string $name,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }
}
