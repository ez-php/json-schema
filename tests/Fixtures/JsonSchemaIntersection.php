<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Countable;
use Traversable;

/**
 * Fixture with an intersection-typed property.
 */
final class JsonSchemaIntersection
{
    /**
     * @param Traversable<mixed>&Countable $items
     */
    public function __construct(
        public readonly Traversable&Countable $items,
    ) {
    }
}
