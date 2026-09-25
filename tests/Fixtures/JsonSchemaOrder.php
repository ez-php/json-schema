<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Sibling-reuse fixture: the same class appears twice side by side, which is not a cycle.
 */
final class JsonSchemaOrder
{
    public function __construct(
        public readonly Address $billing,
        public readonly Address $shipping,
    ) {
    }
}
