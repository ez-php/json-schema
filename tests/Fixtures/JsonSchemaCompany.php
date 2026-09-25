<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Depth-3 fixture: JsonSchemaCompany → Person → Address.
 */
final class JsonSchemaCompany
{
    public function __construct(
        public readonly string $name,
        public readonly Person $ceo,
    ) {
    }
}
