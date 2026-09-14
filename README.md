# ez-php/json-schema

Reflection/attribute-driven JSON Schema emission for plain PHP classes.

Given a class with typed properties (plain or constructor-promoted), `SchemaGenerator`
walks its declared types via reflection and produces a JSON Schema document — recursing
into nested classes, mapping backed/pure enums to `enum`, and treating
`DateTimeInterface` implementations as `{"type": "string", "format": "date-time"}`.

## Usage

```php
use EzPhp\JsonSchema\SchemaGenerator;

final class Address
{
    public function __construct(
        public readonly string $street,
        public readonly string $city,
    ) {
    }
}

final class Person
{
    public function __construct(
        public readonly string $name,
        public readonly int $age,
        public readonly Address $address,
        public readonly ?string $nickname = null,
    ) {
    }
}

$schema = (new SchemaGenerator())->generate(Person::class);
```

produces:

```json
{
    "type": "object",
    "properties": {
        "name": { "type": "string" },
        "age": { "type": "integer" },
        "address": {
            "type": "object",
            "properties": {
                "street": { "type": "string" },
                "city": { "type": "string" }
            },
            "required": ["street", "city"]
        },
        "nickname": { "type": ["string", "null"], "default": null }
    },
    "required": ["name", "age", "address"]
}
```

## Attributes

- `#[EzPhp\JsonSchema\Attribute\Property(description:, format:, pattern:, minimum:, maximum:)]`
  — overrides/extends the schema fragment derived from a property's PHP type.
- `#[EzPhp\JsonSchema\Attribute\Ignore]` — excludes a property from the generated schema
  entirely.

## What it does not do

- No union type support beyond a single non-null type plus `null` (i.e. plain nullable
  types). A property typed `int|string` throws `UnsupportedTypeException`.
- No PHPDoc-driven array item typing (e.g. `array<Foo>`) — `array` properties always emit
  `{"type": "array"}` with no `items` constraint.
- No JSON Schema validation against a value — this package only *emits* schemas. Validating
  data against a schema is the `ez-php/validation` module's job, or an external library.

## Requirements

- PHP `^8.5`, no other runtime dependencies.

## Installation

```bash
composer require ez-php/json-schema
```
