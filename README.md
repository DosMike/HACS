# HACS

[This Repository was vibed with Codex]

HACS is a Hierarchical Authorization Capability System with matching core implementations
for TypeScript/React and PHP.

Permissions are dot-separated keys. A broader grant applies to deeper
permissions unless a more specific matching grant overrides it.

```txt
project                allow
project.delete         deny
project.delete.owner   allow
```

With those grants, `project.delete.owner` is allowed and
`project.delete.member` is denied.

Grant values are:

- `allow`: permits the permission.
- `deny`: denies the permission.
- `inherit`: skips this grant and falls back to the next matching grant.

Boolean values are also accepted as aliases: `true` is `allow`, and `false` is
`deny`. The root grant key `*` matches every permission. If no grant matches,
HACS denies by default.

Permission strings and grant keys are validated with:

```txt
^(?:\*|[a-zA-Z0-9]+(?:\.[a-zA-Z0-9]+)*)$
```

## Packages

- [Node.js / TypeScript](./nodejs/README.md): core TypeScript helpers and React
  bindings.
- [PHP](./php/README.md): core PHP helpers.

## Examples

- [PHP roles and groups](./examples/php/roles-and-groups.php): load direct user
  grants and assigned group grants into a stateful HACS instance.

## Development

```sh
cd nodejs
npm install
npm run typecheck
npm test
npm run build
```

```sh
cd php
composer test
```
