<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Union-typed properties.
 */
final class JsonSchemaUnions
{
    public function __construct(
        public readonly int|string $id,
        public readonly Address|Status $target,
        public readonly int|float|null $amount = null,
    ) {
    }
}
