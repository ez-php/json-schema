<?php

declare(strict_types=1);

namespace EzPhp\JsonSchema\Attribute;

use Attribute;

/**
 * Overrides/extends the JSON Schema fragment {@see \EzPhp\JsonSchema\SchemaGenerator}
 * would otherwise derive from a property's PHP type alone.
 *
 * @package EzPhp\JsonSchema\Attribute
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Property
{
    /**
     * Property Constructor
     *
     * @param string|null $description
     * @param string|null $format
     * @param string|null $pattern
     * @param float|null  $minimum
     * @param float|null  $maximum
     */
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?string $format = null,
        public readonly ?string $pattern = null,
        public readonly ?float $minimum = null,
        public readonly ?float $maximum = null,
    ) {
    }
}
