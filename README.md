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

## Unions and recursive classes

- `int|string` → `{"anyOf": [{"type": "string"}, {"type": "integer"}]}`; a nullable union adds
  `{"type": "null"}`. (`anyOf`, not `oneOf`: PHP accepts a value that matches any member.)
- A property that refers back to a class on the current path becomes a `$ref` — `#` for the
  generated class, `#/$defs/<Name>` (with a `$defs` section) for any other.
- `generateDefinitions(Foo::class)` returns `Foo` and every class on a cycle as a
  `name → schema` map; pass the prefix for the embedding document to the constructor, e.g.
  `new SchemaGenerator('#/components/schemas/')` (what `ez-php/openapi` does).

## What it does not do

- No PHPDoc-driven array item typing (e.g. `array<Foo>`) — `array` properties always emit
  `{"type": "array"}` with no `items` constraint.
- No `$ref` for plain reuse — nested classes are inlined; only a recursive reference
  (`?Node $next`, `Parent` → `Child` → `Parent`) becomes a `$ref` (`#` for the root class,
  `#/$defs/<Name>` otherwise).
- No JSON Schema validation against a value — this package only *emits* schemas. Validating
  data against a schema is the `ez-php/validation` module's job, or an external library.

## Requirements

- PHP `^8.5`, no other runtime dependencies.

## Installation

```bash
composer require ez-php/json-schema
```
