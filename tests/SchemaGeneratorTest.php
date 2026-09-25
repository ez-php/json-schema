<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\JsonSchema\Exception\UnsupportedTypeException;
use EzPhp\JsonSchema\SchemaGenerator;
use Tests\Fixtures\Event;
use Tests\Fixtures\JsonSchemaCompany;
use Tests\Fixtures\JsonSchemaConstrained;
use Tests\Fixtures\JsonSchemaCycleParent;
use Tests\Fixtures\JsonSchemaIntersection;
use Tests\Fixtures\JsonSchemaOrder;
use Tests\Fixtures\JsonSchemaTreeNode;
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

    public function testUnionTypeIsUnsupported(): void
    {
        $this->expectException(UnsupportedTypeException::class);

        $this->generator->generate(UnsupportedUnion::class);
    }

    public function testMixedTypeIsUnsupported(): void
    {
        $this->expectException(UnsupportedTypeException::class);

        $this->generator->generate(MixedProperty::class);
    }

    public function testSelfReferencingClassIsRejectedInsteadOfRecursingForever(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->expectExceptionMessage('JsonSchemaTreeNode::$next: recursive reference');

        $this->generator->generate(JsonSchemaTreeNode::class);
    }

    public function testMutuallyReferencingClassesAreRejected(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->expectExceptionMessage('JsonSchemaCycleChild::$parent: recursive reference');

        $this->generator->generate(JsonSchemaCycleParent::class);
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

    public function testGeneratorIsReusableAfterRejectingACycle(): void
    {
        try {
            $this->generator->generate(JsonSchemaTreeNode::class);
            self::fail('Expected UnsupportedTypeException.');
        } catch (UnsupportedTypeException) {
        }

        $schema = $this->generator->generate(JsonSchemaOrder::class);

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
}
