<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use EzPhp\JsonSchema\Attribute\Ignore;
use EzPhp\JsonSchema\Attribute\Property;

final class Person
{
    public function __construct(
        #[Property(description: 'Full name')]
        public readonly string $name,
        public readonly int $age,
        public readonly Status $status,
        public readonly Address $address,
        public readonly ?string $nickname = null,
        #[Ignore]
        public readonly string $internalNotes = '',
    ) {
    }
}
