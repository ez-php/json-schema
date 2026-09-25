<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use EzPhp\JsonSchema\Attribute\Property;

/**
 * Fixture exercising every #[Property] field, including on a nullable property.
 */
final class JsonSchemaConstrained
{
    public function __construct(
        #[Property(format: 'email', pattern: '^[^@]+@[^@]+$')]
        public readonly string $email,
        #[Property(description: 'Age in years', minimum: 0, maximum: 150)]
        public readonly int $age,
        #[Property(description: 'Optional score', minimum: 0.5)]
        public readonly ?float $score = null,
    ) {
    }
}
