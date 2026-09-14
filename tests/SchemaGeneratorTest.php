<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\JsonSchema\Exception\UnsupportedTypeException;
use EzPhp\JsonSchema\SchemaGenerator;
use Tests\Fixtures\Event;
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
}
