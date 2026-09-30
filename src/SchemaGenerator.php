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
 * Nested classes are inlined. A recursive reference (a property whose type is
 * a class already on the current path) becomes a `$ref` instead: to the root
 * (`#`) when it points back at the generated class, otherwise to a `$defs`
 * entry holding that class. Reusing a class in sibling properties is not a
 * cycle and stays inlined. Union types map to `anyOf`.
 *
 * generateDefinitions() returns the same schemas as a name → schema map with a
 * configurable reference prefix, for embedding in another document (OpenAPI's
 * `#/components/schemas/`).
 *
 * @package EzPhp\JsonSchema
 */
final class SchemaGenerator
{
    /**
     * Classes on the current generate() path, used to detect recursive references.
     *
     * @var list<class-string>
     */
    private array $ancestors = [];

    /**
     * Where the current run's root class is referenced from: `#` for generate(),
     * a named definition for generateDefinitions().
     */
    private string $rootRef = '#';

    /**
     * @var class-string|null The class generate()/generateDefinitions() was called for.
     */
    private ?string $root = null;

    /**
     * Definition names of classes reached through a cycle, by class.
     *
     * @var array<class-string, string>
     */
    private array $definitionNames = [];

    /**
     * Classes whose definition still has to be generated.
     *
     * @var list<class-string>
     */
    private array $pendingDefinitions = [];

    /**
     * @param string $refPrefix Prefix of `$ref` values pointing at definitions: `#/$defs/`
     *                          for standalone documents, `#/components/schemas/` for OpenAPI.
     */
    public function __construct(private readonly string $refPrefix = '#/$defs/')
    {
    }

    /**
     * The schema of `$class`; cycles through other classes add a `$defs` section.
     *
     * @param class-string $class
     * @return array{type: string, properties: array<string, array<string, mixed>>, required: list<string>, '$defs'?: array<string, array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}>}
     *
     * @throws UnsupportedTypeException When a property's type cannot be mapped.
     */
    public function generate(string $class): array
    {
        try {
            $definitions = $this->run($class, '#');
            $schema = $definitions[$class];
            unset($definitions[$class]);

            if ($definitions !== []) {
                $defs = [];

                foreach ($definitions as $defined => $definition) {
                    $defs[$this->definitionName($defined)] = $definition;
                }

                $schema['$defs'] = $defs;
            }

            return $schema;
        } finally {
            $this->definitionNames = [];
        }
    }

    /**
     * `$class` and every class reached through a cycle, as definition name (the
     * short class name) → schema. References use `$refPrefix . name`, the root
     * included — suited to a document that keeps schemas in one named map.
     *
     * @param class-string $class
     * @return array<string, array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}>
     *
     * @throws UnsupportedTypeException When a property's type cannot be mapped.
     */
    public function generateDefinitions(string $class): array
    {
        try {
            $definitions = $this->run($class, $this->refPrefix . $this->definitionName($class));
            $named = [];

            foreach ($definitions as $defined => $definition) {
                $named[$this->definitionName($defined)] = $definition;
            }

            return $named;
        } finally {
            $this->definitionNames = [];
        }
    }

    /**
     * Generate the root and then every definition its cycles require.
     *
     * @param class-string $class
     * @return array<class-string, array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}>
     */
    private function run(string $class, string $rootRef): array
    {
        $this->root = $class;
        $this->rootRef = $rootRef;
        $this->pendingDefinitions = [];

        try {
            $definitions = [$class => $this->generateNested($class)];

            while (($next = $this->nextPendingDefinition()) !== null) {
                if (!isset($definitions[$next])) {
                    $definitions[$next] = $this->generateNested($next);
                }
            }

            return $definitions;
        } finally {
            $this->root = null;
            $this->ancestors = [];
            $this->pendingDefinitions = [];
        }
    }

    /**
     * The next class a cycle scheduled for a definition, or null when none is left.
     *
     * @phpstan-impure
     *
     * @return class-string|null
     */
    private function nextPendingDefinition(): ?string
    {
        return array_shift($this->pendingDefinitions);
    }

    /**
     * @param class-string $class
     * @return array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}
     */
    private function generateNested(string $class): array
    {
        $this->ancestors[] = $class;

        try {
            return $this->generateObject($class);
        } finally {
            array_pop($this->ancestors);
        }
    }

    /**
     * A unique definition name for a class: its short name, suffixed on collision.
     *
     * @param class-string $class
     */
    private function definitionName(string $class): string
    {
        if (isset($this->definitionNames[$class])) {
            return $this->definitionNames[$class];
        }

        $short = substr($class, (int) strrpos('\\' . $class, '\\'));
        $name = $short;

        for ($i = 2; in_array($name, $this->definitionNames, true); $i++) {
            $name = $short . $i;
        }

        return $this->definitionNames[$class] = $name;
    }

    /**
     * The `$ref` for a class that closes a cycle; schedules its definition.
     *
     * @param class-string $class
     * @return array{'$ref': string}
     */
    private function reference(string $class): array
    {
        if ($class === $this->root) {
            return ['$ref' => $this->rootRef];
        }

        if (!isset($this->definitionNames[$class])) {
            $this->pendingDefinitions[] = $class;
        }

        return ['$ref' => $this->refPrefix . $this->definitionName($class)];
    }

    /**
     * @param class-string $class
     * @return array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}
     */
    private function generateObject(string $class): array
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

        [$members, $nullable] = $this->resolveTypes($class, $property, $type);

        if (count($members) === 1) {
            $fragment = $this->typeSchema($class, $property, $members[0]);

            if ($nullable) {
                $fragment = $this->markNullable($fragment);
            }
        } else {
            // anyOf, not oneOf: PHP accepts a value matching any member, and members can
            // overlap in JSON Schema (5 is both an integer and a number).
            $variants = array_map(fn (ReflectionNamedType $member): array => $this->typeSchema($class, $property, $member), $members);

            if ($nullable) {
                $variants[] = ['type' => 'null'];
            }

            $fragment = ['anyOf' => $variants];
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
     * The non-null member types of a property type, and whether null is allowed.
     *
     * @param class-string $class
     * @return array{0: list<ReflectionNamedType>, 1: bool}
     */
    private function resolveTypes(string $class, ReflectionProperty $property, ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [[$type], $type->allowsNull()];
        }

        if (!$type instanceof ReflectionUnionType) {
            throw UnsupportedTypeException::forProperty($class, $property->getName(), 'intersection types are not supported');
        }

        $named = [];

        foreach ($type->getTypes() as $candidate) {
            if (!$candidate instanceof ReflectionNamedType) {
                throw UnsupportedTypeException::forProperty($class, $property->getName(), 'intersection types inside a union are not supported');
            }

            if ($candidate->getName() !== 'null') {
                $named[] = $candidate;
            }
        }

        return [$named, $type->allowsNull()];
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
            if (in_array($typeName, $this->ancestors, true)) {
                return $this->reference($typeName);
            }

            return $this->generateNested($typeName);
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

            return $fragment;
        }

        // A `$ref` (recursive class) cannot carry a type; allow null alongside it.
        if (isset($fragment['$ref'])) {
            return ['anyOf' => [$fragment, ['type' => 'null']]];
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

        return $type !== null && $type->allowsNull();
    }
}
