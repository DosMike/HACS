# HACS PHP

Hierarchical Authorization Capability helpers for PHP.

## Install

copy HACS.php

## Usage

```php
use HACS\HACS;

$hacs = new HACS([
    'project' => 'allow',
    'project.delete' => 'deny',
    'project.delete.owner' => 'allow',
]);

$hacs->can('project.delete.owner'); // true
$hacs->can('project.delete.member'); // false
```

Grant values can be `allow`, `deny`, or `inherit`. Boolean aliases are accepted:
`true` is `allow`, and `false` is `deny` and `null` is `inherit`.
This should simplify reading grants from other tri-state stores, like nullable DB columns.

Additional grant sets can be loaded into the same instance:

```php
$hacs = new HACS(['project' => 'allow', 'project.delete' => 'deny']);
$hacs->addGrantSet(['project.delete' => 'allow']);

$hacs->can('project.delete'); // true
```

The most specific matching grant wins. When a loaded grant set contains an exact
key that already exists, the new value overrides the previous value unless the
new value is `inherit`. `inherit` is ignored while loading so it cannot erase an
earlier explicit `allow` or `deny`.

`HACS::test($grants, $permission)` remains as a convenience for checking a
single grant set without manually creating an instance.

## API

- `HACS::test(array $grants, string $permission): bool`
- `new HACS(array $grants = [])`
- `$hacs->addGrants(array $grants): HACS`
- `$hacs->can(string $permission): bool`
- `$hacs->resolvePermission(string $permission): ?array`
- `$hacs->explain(string $permission): string`
- `$hacs->explainPermission(string $permission): array`
- `$hacs->grants(): array`

Permission strings and grant keys are validated with:

```txt
/^(?:\*|[a-zA-Z0-9]+(?:\.[a-zA-Z0-9]+)*)$/
```

## Development

```sh
composer test
```

The test file is dependency-free and can also be run directly:

```sh
php tests/HACSTest.php
```

## Examples

- [Roles and groups](../examples/php/roles-and-groups.php): demonstrates loading
  ordered user and group grants with the standalone SQL helper in
  `examples/php/framework/SQL.php`. The SQL helper expects
  `examples/php/framework/server.config.php` to define the mysqli connection.
