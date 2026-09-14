<?php

declare(strict_types=1);

namespace EzPhp\JsonSchema\Attribute;

use Attribute;

/**
 * Excludes a property from the schema {@see \EzPhp\JsonSchema\SchemaGenerator} emits.
 *
 * @package EzPhp\JsonSchema\Attribute
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Ignore
{
}
