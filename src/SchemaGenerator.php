<?php

declare(strict_types=1);

namespace EzPhp\JsonSchema;

use BackedEnum;
use DateTimeInterface;
use EzPhp\JsonSchema\Attribute\Ignore;
use EzPhp\JsonSchema\Attribute\Property as PropertyAttribute;
use EzPhp\JsonSchema\Exception\UnsupportedTypeException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

/**
 * Derives a JSON Schema document from a plain PHP class's typed properties,
 * using reflection plus optional {@see PropertyAttribute} / {@see Ignore} attributes.
 *
 * @package EzPhp\JsonSchema
 */
final class SchemaGenerator
{
    /**
     * @param class-string $class
     * @return array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}
     */
    public function generate(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        $properties = [];
        $required = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->getAttributes(Ignore::class) !== []) {
                continue;
            }

            $properties[$property->getName()] = $this->propertySchema($class, $property, $constructor);

            if (!$this->isOptional($property, $constructor)) {
                $required[] = $property->getName();
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * @param class-string $class
     * @return array<string, mixed>
     */
    private function propertySchema(string $class, ReflectionProperty $property, ?ReflectionMethod $constructor): array
    {
        $type = $property->getType();

        if ($type === null) {
            throw UnsupportedTypeException::forProperty($class, $property->getName(), 'property has no declared type');
        }

        [$named, $nullable] = $this->resolveNamedType($class, $property, $type);
        $fragment = $this->typeSchema($class, $property, $named);

        if ($nullable) {
            $fragment = $this->markNullable($fragment);
        }

        foreach ($property->getAttributes(PropertyAttribute::class) as $attribute) {
            $fragment = $this->applyAttribute($fragment, $attribute->newInstance());
        }

        [$hasDefault, $default] = $this->resolveDefault($property, $constructor);

        if ($hasDefault) {
            $fragment['default'] = $default;
        }

        return $fragment;
    }

    /**
     * Reads a property's default from its own declaration, falling back to the
     * matching constructor parameter — reflection does not surface a promoted
     * constructor property's default via {@see ReflectionProperty::hasDefaultValue()}.
     *
     * @return array{0: bool, 1: mixed}
     */
    private function resolveDefault(ReflectionProperty $property, ?ReflectionMethod $constructor): array
    {
        if ($property->hasDefaultValue()) {
            return [true, $property->getDefaultValue()];
        }

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->getName() === $property->getName() && $parameter->isDefaultValueAvailable()) {
                    return [true, $parameter->getDefaultValue()];
                }
            }
        }

        return [false, null];
    }

    /**
     * @param class-string $class
     * @return array{0: ReflectionNamedType, 1: bool}
     */
    private function resolveNamedType(string $class, ReflectionProperty $property, ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type, $type->allowsNull()];
        }

        if (!$type instanceof ReflectionUnionType) {
            throw UnsupportedTypeException::forProperty($class, $property->getName(), 'intersection types are not supported');
        }

        $named = [];

        foreach ($type->getTypes() as $candidate) {
            if (!$candidate instanceof ReflectionNamedType) {
                throw UnsupportedTypeException::forProperty($class, $property->getName(), 'union types are not supported');
            }

            if ($candidate->getName() !== 'null') {
                $named[] = $candidate;
            }
        }

        if (count($named) !== 1) {
            throw UnsupportedTypeException::forProperty($class, $property->getName(), 'union types are not supported');
        }

        return [$named[0], $type->allowsNull()];
    }

    /**
     * @param class-string $class
     * @return array<string, mixed>
     */
    private function typeSchema(string $class, ReflectionProperty $property, ReflectionNamedType $type): array
    {
        return match ($type->getName()) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'string' => ['type' => 'string'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array'],
            default => $this->classTypeSchema($class, $property, $type->getName()),
        };
    }

    /**
     * @param class-string $class
     * @return array<string, mixed>
     */
    private function classTypeSchema(string $class, ReflectionProperty $property, string $typeName): array
    {
        if (!class_exists($typeName) && !enum_exists($typeName) && !interface_exists($typeName)) {
            throw UnsupportedTypeException::forProperty($class, $property->getName(), sprintf('unrecognized type "%s"', $typeName));
        }

        if (enum_exists($typeName)) {
            return $this->enumSchema($typeName);
        }

        if (is_a($typeName, DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if (class_exists($typeName)) {
            return $this->generate($typeName);
        }

        throw UnsupportedTypeException::forProperty($class, $property->getName(), sprintf('interface "%s" cannot be resolved to a concrete schema', $typeName));
    }

    /**
     * @return array<string, mixed>
     */
    private function enumSchema(string $enumClass): array
    {
        /** @var class-string<UnitEnum> $enumClass */
        $enum = new ReflectionEnum($enumClass);
        $backed = $enum->isBacked();

        /** @var list<UnitEnum> $cases */
        $cases = $enumClass::cases();

        $values = array_map(
            static fn (UnitEnum $case): int|string => $backed && $case instanceof BackedEnum ? $case->value : $case->name,
            $cases,
        );

        return [
            'type' => is_int($values[0] ?? null) ? 'integer' : 'string',
            'enum' => $values,
        ];
    }

    /**
     * @param array<string, mixed> $fragment
     * @return array<string, mixed>
     */
    private function markNullable(array $fragment): array
    {
        $enum = $fragment['enum'] ?? null;

        if (is_array($enum)) {
            $enum[] = null;
            $fragment['enum'] = $enum;

            return $fragment;
        }

        if (isset($fragment['type']) && is_string($fragment['type'])) {
            $fragment['type'] = [$fragment['type'], 'null'];
        }

        return $fragment;
    }

    /**
     * @param array<string, mixed> $fragment
     * @return array<string, mixed>
     */
    private function applyAttribute(array $fragment, PropertyAttribute $attribute): array
    {
        foreach ([
            'description' => $attribute->description,
            'format' => $attribute->format,
            'pattern' => $attribute->pattern,
            'minimum' => $attribute->minimum,
            'maximum' => $attribute->maximum,
        ] as $key => $value) {
            if ($value !== null) {
                $fragment[$key] = $value;
            }
        }

        return $fragment;
    }

    private function isOptional(ReflectionProperty $property, ?ReflectionMethod $constructor): bool
    {
        [$hasDefault] = $this->resolveDefault($property, $constructor);

        if ($hasDefault) {
            return true;
        }

        $type = $property->getType();

        return $type instanceof ReflectionNamedType && $type->allowsNull();
    }
}
