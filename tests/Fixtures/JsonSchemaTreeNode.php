<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Self-referencing fixture: a node that points at another node of its own class.
 */
final class JsonSchemaTreeNode
{
    public function __construct(
        public readonly string $label,
        public readonly ?JsonSchemaTreeNode $next = null,
    ) {
    }
}
