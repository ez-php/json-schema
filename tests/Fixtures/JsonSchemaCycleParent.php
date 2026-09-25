<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Mutual-reference fixture: points at a child that points back at this class.
 */
final class JsonSchemaCycleParent
{
    public function __construct(
        public readonly string $name,
        public readonly JsonSchemaCycleChild $child,
    ) {
    }
}
