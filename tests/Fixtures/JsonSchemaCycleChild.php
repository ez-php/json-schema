<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Mutual-reference fixture: points back at its parent's class.
 */
final class JsonSchemaCycleChild
{
    public function __construct(
        public readonly string $name,
        public readonly ?JsonSchemaCycleParent $parent = null,
    ) {
    }
}
