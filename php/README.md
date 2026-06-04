# HACS PHP

Hierarchical Authorization Capability helpers for PHP.

## Install

```sh
composer require hacs/hacs
```

## Usage

```php
use HACS\HACS;

$grants = HACS::defineGrants([
    'project' => 'allow',
    'project.delete' => 'deny',
    'project.delete.owner' => 'allow',
]);

HACS::test($grants, HACS::makePermission('project.delete.owner')); // true
HACS::test($grants, HACS::makePermission('project.delete.member')); // false
```

Grant values can be `allow`, `deny`, or `inherit`. Boolean aliases are accepted:
`true` is `allow`, and `false` is `deny`.

Grant sets can be passed as a single associative array or as a list of grant sets:

```php
$grants = [
    HACS::defineGrants(['project' => 'allow', 'project.delete' => 'deny']),
    HACS::defineGrants(['project.delete' => 'allow']),
];
```

The most specific matching grant wins. Later same-specificity grants override
earlier grants because PHP's stable sort preserves insertion order for equal
specificity.

## API

- `HACS::permissionKey(string $value): string`
- `HACS::makePermission(string $value): string`
- `HACS::defineGrants(array $grants): array`
- `HACS::permissionGrant(array $grants): array`
- `HACS::test(array $grants, string $permission): bool`
- `HACS::can(array $grants, string $permission): bool`
- `HACS::resolvePermission(array $grants, string $permission): ?array`
- `HACS::explain(array $grants, string $permission): string`
- `HACS::explainPermission(array $grants, string $permission): array`

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
