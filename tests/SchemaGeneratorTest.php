<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\JsonSchema\Exception\UnsupportedTypeException;
use EzPhp\JsonSchema\SchemaGenerator;
use Tests\Fixtures\Event;
use Tests\Fixtures\JsonSchemaCompany;
use Tests\Fixtures\JsonSchemaConstrained;
use Tests\Fixtures\JsonSchemaCycleHolder;
use Tests\Fixtures\JsonSchemaCycleParent;
use Tests\Fixtures\JsonSchemaIntersection;
use Tests\Fixtures\JsonSchemaOrder;
use Tests\Fixtures\JsonSchemaTreeNode;
use Tests\Fixtures\JsonSchemaUnions;
use Tests\Fixtures\JsonSchemaUntyped;
use Tests\Fixtures\MixedProperty;
use Tests\Fixtures\Person;
use Tests\Fixtures\UnsupportedUnion;

final class SchemaGeneratorTest extends TestCase
{
    private SchemaGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new SchemaGenerator();
    }

    public function testMapsScalarEnumAndNestedObjectProperties(): void
    {
        $schema = $this->generator->generate(Person::class);

        self::assertSame('object', $schema['type']);
        self::assertSame(['type' => 'string', 'description' => 'Full name'], $schema['properties']['name']);
        self::assertSame(['type' => 'integer'], $schema['properties']['age']);
        self::assertSame(
            ['type' => 'string', 'enum' => ['active', 'inactive']],
            $schema['properties']['status'],
        );
        self::assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'street' => ['type' => 'string'],
                    'city' => ['type' => 'string'],
                ],
                'required' => ['street', 'city'],
            ],
            $schema['properties']['address'],
        );
    }

    public function testNullablePropertyWithDefaultIsOptionalAndTypedAsNullable(): void
    {
        $schema = $this->generator->generate(Person::class);

        self::assertSame(['type' => ['string', 'null'], 'default' => null], $schema['properties']['nickname']);
        self::assertNotContains('nickname', $schema['required']);
    }

    public function testIgnoredPropertyIsExcludedEntirely(): void
    {
        $schema = $this->generator->generate(Person::class);

        self::assertArrayNotHasKey('internalNotes', $schema['properties']);
        self::assertNotContains('internalNotes', $schema['required']);
    }

    public function testRequiredListContainsOnlyPropertiesWithoutADefault(): void
    {
        $schema = $this->generator->generate(Person::class);

        self::assertSame(['name', 'age', 'status', 'address'], $schema['required']);
    }

    public function testDateTimeInterfacePropertyBecomesAnIso8601String(): void
    {
        $schema = $this->generator->generate(Event::class);

        self::assertSame(
            ['type' => 'string', 'format' => 'date-time'],
            $schema['properties']['occurredAt'],
        );
    }

    public function testUnionTypeBecomesAnyOf(): void
    {
        $schema = $this->generator->generate(UnsupportedUnion::class);

        // Members come in PHP's canonical union order (string before int).
        self::assertSame(['anyOf' => [['type' => 'string'], ['type' => 'integer']]], $schema['properties']['value']);
    }

    public function testUnionsOfClassesAndNullableUnions(): void
    {
        $schema = $this->generator->generate(JsonSchemaUnions::class);

        self::assertSame('object', self::dig($schema, 'properties', 'target', 'anyOf', 0, 'type'));
        self::assertSame(['active', 'inactive'], self::dig($schema, 'properties', 'target', 'anyOf', 1, 'enum'));
        self::assertSame(
            ['anyOf' => [['type' => 'integer'], ['type' => 'number'], ['type' => 'null']], 'default' => null],
            self::dig($schema, 'properties', 'amount'),
        );
        self::assertSame(['id', 'target'], $schema['required']);
    }

    public function testMixedTypeIsUnsupported(): void
    {
        $this->expectException(UnsupportedTypeException::class);

        $this->generator->generate(MixedProperty::class);
    }

    public function testSelfReferenceBecomesARefToTheRoot(): void
    {
        $schema = $this->generator->generate(JsonSchemaTreeNode::class);

        self::assertSame(['anyOf' => [['$ref' => '#'], ['type' => 'null']], 'default' => null], $schema['properties']['next']);
        self::assertArrayNotHasKey('$defs', $schema);
    }

    public function testMutualReferenceBackToTheRootUsesTheRootRef(): void
    {
        $schema = $this->generator->generate(JsonSchemaCycleParent::class);

        self::assertSame(
            ['anyOf' => [['$ref' => '#'], ['type' => 'null']], 'default' => null],
            self::dig($schema, 'properties', 'child', 'properties', 'parent'),
        );
    }

    public function testACycleBelowTheRootGoesIntoDefs(): void
    {
        $schema = $this->generator->generate(JsonSchemaCycleHolder::class);
        $parentRef = ['anyOf' => [['$ref' => '#/$defs/JsonSchemaCycleParent'], ['type' => 'null']], 'default' => null];

        self::assertSame('object', self::dig($schema, 'properties', 'family', 'type'));
        self::assertSame(['JsonSchemaCycleParent'], array_keys((array) self::dig($schema, '$defs')));
        self::assertSame($parentRef, self::dig($schema, 'properties', 'family', 'properties', 'child', 'properties', 'parent'));
        self::assertSame($parentRef, self::dig($schema, '$defs', 'JsonSchemaCycleParent', 'properties', 'child', 'properties', 'parent'));
    }

    public function testGenerateDefinitionsNamesTheRootAndUsesTheGivenPrefix(): void
    {
        $definitions = (new SchemaGenerator('#/components/schemas/'))->generateDefinitions(JsonSchemaTreeNode::class);

        self::assertSame(['JsonSchemaTreeNode'], array_keys($definitions));
        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/JsonSchemaTreeNode'], ['type' => 'null']], 'default' => null],
            self::dig($definitions, 'JsonSchemaTreeNode', 'properties', 'next'),
        );
    }

    public function testSameClassUsedByTwoSiblingPropertiesIsNotACycle(): void
    {
        $address = [
            'type' => 'object',
            'properties' => [
                'street' => ['type' => 'string'],
                'city' => ['type' => 'string'],
            ],
            'required' => ['street', 'city'],
        ];

        $schema = $this->generator->generate(JsonSchemaOrder::class);

        self::assertSame($address, $schema['properties']['billing']);
        self::assertSame($address, $schema['properties']['shipping']);
    }

    public function testGeneratorIsReusableAfterAFailedRun(): void
    {
        try {
            $this->generator->generate(MixedProperty::class);
            self::fail('Expected UnsupportedTypeException.');
        } catch (UnsupportedTypeException) {
        }

        $this->generator->generate(JsonSchemaCycleHolder::class);
        $schema = $this->generator->generate(JsonSchemaOrder::class);

        self::assertArrayNotHasKey('$defs', $schema);

        self::assertSame(['billing', 'shipping'], $schema['required']);
    }

    public function testPropertyAttributeAddsEveryConstraintField(): void
    {
        $schema = $this->generator->generate(JsonSchemaConstrained::class);

        self::assertSame(
            ['type' => 'string', 'format' => 'email', 'pattern' => '^[^@]+@[^@]+$'],
            $schema['properties']['email'],
        );
        self::assertSame(
            ['type' => 'integer', 'description' => 'Age in years', 'minimum' => 0.0, 'maximum' => 150.0],
            $schema['properties']['age'],
        );
    }

    public function testPropertyAttributeKeepsTheNullableTypeAndDefault(): void
    {
        $schema = $this->generator->generate(JsonSchemaConstrained::class);

        self::assertSame(
            ['type' => ['number', 'null'], 'description' => 'Optional score', 'minimum' => 0.5, 'default' => null],
            $schema['properties']['score'],
        );
        self::assertSame(['email', 'age'], $schema['required']);
    }

    public function testUntypedPropertyIsUnsupported(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->expectExceptionMessage('JsonSchemaUntyped::$anything: property has no declared type');

        $this->generator->generate(JsonSchemaUntyped::class);
    }

    public function testIntersectionTypeIsUnsupported(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->expectExceptionMessage('intersection types are not supported');

        $this->generator->generate(JsonSchemaIntersection::class);
    }

    public function testNestedClassesAreInlinedAtEveryDepth(): void
    {
        $schema = $this->generator->generate(JsonSchemaCompany::class);

        $ceo = $schema['properties']['ceo'];
        self::assertSame('object', $ceo['type']);
        self::assertIsArray($ceo['properties']);
        self::assertIsArray($ceo['properties']['address']);
        self::assertSame(
            ['street' => ['type' => 'string'], 'city' => ['type' => 'string']],
            $ceo['properties']['address']['properties'],
        );
        self::assertSame(['name', 'ceo'], $schema['required']);
    }

    /**
     * Walk nested arrays, asserting each level is an array.
     *
     * @param array<array-key, mixed> $data
     */
    private static function dig(array $data, string|int ...$keys): mixed
    {
        $value = $data;

        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
