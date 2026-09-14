<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class Address
{
    public function __construct(
        public readonly string $street,
        public readonly string $city,
    ) {
    }
}
