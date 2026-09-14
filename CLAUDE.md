# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` unless `--namespace=` overrides it (`bignum` → `BigNum` and
`opcache` → `OPCache` are existing exceptions the guess gets wrong).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4`), `phpstan.neon`, `phpunit.xml`
(test suite **and** coverage source), and `packages.sh` (alphabetical position).

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — editing it marks all ~40
  `CLAUDE.md` copies as drifted at once, so the next `composer full` would fail for
  a brand-new module. The generator prints which ports to claim instead.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| **next free** | **3311** | **6383** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project is the one exception, since it has no host/container split and uses `REDIS_PORT` for both.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/json-schema

> This file is the module-specific half. The coding guidelines above it are
> generated by `sync_guidelines.php` — run `composer guidelines:sync` from the
> monorepo root to fill them in. Never edit that part by hand.

---

## Source Structure

```
src/
  SchemaGenerator.php          — walks a class's typed properties via reflection and
                                  emits a JSON Schema document; recurses into nested classes
  Attribute/
    Property.php                — optional per-property override (description, format,
                                   pattern, minimum, maximum) merged into the derived fragment
    Ignore.php                  — excludes a property from the emitted schema entirely
  Exception/
    JsonSchemaException.php     — base exception for this package (extend for new cases)
    UnsupportedTypeException.php — thrown for an untyped property, an unsupported union,
                                    or a type with no JSON Schema mapping
tests/
  TestCase.php                  — module's PHPUnit base class
  SchemaGeneratorTest.php        — scalar/enum/nested-object mapping, nullable/default
                                    handling, ignored properties, unsupported-type errors
  Fixtures/
    Address.php, Status.php, Person.php, Event.php — fixture classes exercising nested
      objects, backed enums, DateTimeInterface, nullable-with-default properties
    UnsupportedUnion.php, MixedProperty.php — fixtures for the unsupported-type error paths
```

---

## Key Classes and Responsibilities

- **`SchemaGenerator`** — the entry point. `generate(class-string $class): array` reflects
  every public, non-static property (skipping ones carrying `#[Ignore]`), maps each
  property's declared type to a JSON Schema fragment, applies any `#[Property(...)]`
  override, and collects properties without a default/nullable type into `required`.
  Nested class-typed properties recurse into `generate()` for that class.
- **`Attribute\Property`** — a plain data-holder attribute; `SchemaGenerator` merges its
  non-null fields into the fragment it already derived from reflection, so it only ever
  adds detail (description, format, pattern, minimum, maximum) rather than replacing the
  inferred `type`.
- **`Attribute\Ignore`** — a marker attribute; `SchemaGenerator` checks for its presence
  and skips the property before any type resolution happens.
- **`Exception\UnsupportedTypeException`** — the failure mode for every type this package
  deliberately does not support (untyped property, non-nullable union, `mixed`, an
  unrecognized class/interface). Carries the class, property name, and reason in its
  message via the `forProperty()` factory.

---

## Design Decisions and Constraints

- **Reflection-first, attributes are additive only.** The PHP type system is the source
  of truth for `type`/`enum`/nested-object shape; attributes exist only to add metadata
  reflection cannot express (`description`, `format`, `pattern`, `minimum`, `maximum`).
  There is no attribute that overrides the inferred `type` itself — that would let the
  schema silently diverge from what the class actually accepts.
- **Nullable is "exactly one non-null type", not general unions.** A property typed
  `?Foo` or `Foo|null` is supported (nullable `Foo`); a property typed `int|string` throws
  `UnsupportedTypeException`. JSON Schema can express arbitrary unions via `oneOf`, but
  supporting that generally would mean guessing which PHP union member a given JSON value
  round-trips to — out of scope for a first pass (see README "What it does not do").
  `mixed` is likewise rejected, consistent with the project's "avoid `mixed`" guideline.
- **No PHPDoc parsing.** Array item types (`array<Foo>`), template generics, and similar
  are PHPDoc-only conventions with no reflection API — an `array`-typed property always
  emits a bare `{"type": "array"}`. Adding items-type inference would require a PHPDoc
  parser dependency this package deliberately avoids (see "no heavy dependencies").
- **No validation.** This package only emits schemas from PHP types; validating a JSON
  payload against a schema (or against PHP data) is `ez-php/validation`'s job, or an
  external JSON Schema validator the application chooses to add itself.
- **Attached as a git submodule**, not scaffolded from `make_module.php`'s generated-package
  path. The upstream repository was still empty (README only) when attached, so the full
  required-file set (composer.json, phpstan.neon, Docker scaffold, etc.) and the first-pass
  `SchemaGenerator` implementation were written directly into the submodule's working tree
  in this same session, rather than deferred to the submodule's own separate history.
- **No framework dependency.** `composer.json` requires nothing but `php: ^8.5`, matching
  `ez-php/support` and `ez-php/dataloader` — usable standalone or from `ez-php/openapi`'s
  optional component-schema generation without pulling in `ez-php/framework`.

---

## Testing Approach

- Pure unit tests, no infrastructure (no MySQL/Redis/Meilisearch) — every test builds a
  fixture class under `tests/Fixtures/` and asserts on the array `SchemaGenerator::generate()`
  returns.
- Test classes live in the shared `Tests\` namespace but must be uniquely named
  across the whole monorepo — the root `phpunit.xml` loads every package in one
  process, so a duplicate name is a fatal error, not a test failure. Prefix with
  `JsonSchema` when the obvious name is already taken.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Validating a value/payload against a schema | `ez-php/validation`, or an external JSON Schema validator — this package only emits schemas |
| OpenAPI document assembly (`#/components/schemas`, paths, `$ref` wiring) | `ez-php/openapi`, which may optionally call `SchemaGenerator` per-DTO but owns the surrounding document |
| PHPDoc-based generics/array-item type inference | Out of scope — would require a PHPDoc parser dependency; `array` properties stay untyped (`{"type": "array"}`) |
| General union-type (`oneOf`) support | Out of scope for this first pass — only "single type, optionally nullable" is supported |