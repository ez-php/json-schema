<?php

declare(strict_types=1);

namespace EzPhp\JsonSchema\Exception;

/**
 * Thrown when a property's declared type cannot be represented as a JSON Schema
 * fragment (untyped property, unsupported union, or an unrecognized class).
 *
 * @package EzPhp\JsonSchema\Exception
 */
final class UnsupportedTypeException extends JsonSchemaException
{
    public static function forProperty(string $class, string $property, string $reason): self
    {
        return new self(sprintf('Cannot derive a JSON Schema type for %s::$%s: %s.', $class, $property, $reason));
    }
}
