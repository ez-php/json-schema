<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Root whose child graph contains a cycle not involving the root itself.
 */
final class JsonSchemaCycleHolder
{
    public function __construct(
        public readonly JsonSchemaCycleParent $family,
    ) {
    }
}
