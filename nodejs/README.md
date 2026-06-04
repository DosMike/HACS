# HACS Node.js

Hierarchical Authorization Capability helpers for TypeScript and React.

## Install

```sh
npm install hacs
```

React is a peer dependency when using the React entry point.

## Permission Model

Permissions are dot-separated keys. A broader grant applies to deeper
permissions unless a more specific matching grant overrides it.

```ts
import { defineGrants, makePermission, test } from 'hacs';

const grants = defineGrants({
  project: 'allow',
  'project.delete': 'deny',
  'project.delete.owner': 'allow',
});

test(grants, makePermission('project.delete.owner')); // true
test(grants, makePermission('project.delete.member')); // false
```

Grant values can be:

- `allow`: permits the permission.
- `deny`: denies the permission.
- `inherit`: skips this grant and falls back to the next matching grant.

Boolean values are also accepted as aliases: `true` is `allow`, and `false` is
`deny`.

The root grant key `*` matches every permission. If no grant matches, HACS
denies by default.

## Core API

```ts
import {
  can,
  defineGrants,
  explain,
  explainPermission,
  makePermission,
  permissionKey,
  resolvePermission,
  test,
} from 'hacs';
```

Permission strings are tagged at runtime with `permissionKey(...)` for grant
keys and `makePermission(...)` for checked permissions. Both validators require
this syntax:

```txt
^(?:\*|[a-zA-Z0-9]+(?:\.[a-zA-Z0-9]+)*)$
```

### Typed Checked Permissions

Checked permissions can be constrained to a user-defined literal collection.
This does not affect grant storage; it only tightens app-authored permission
checks at compile time.

```ts
type AppPermission =
  | 'project.read'
  | 'project.delete.owner';

const permission = makePermission<AppPermission>('project.delete.owner');

// Type error:
makePermission<AppPermission>('project.delete.member');
```

### `test(grants, permission)`

Returns `true` when the most specific matching grant is `allow`.

```ts
test(defineGrants({ project: 'allow' }), makePermission('project.update.owner'));
```

### `can(grants, permission)`

Alias for `test`.

### `resolvePermission(grants, permission)`

Returns the matched grant, or `null` when no grant applies.

### `explain(grants, permission)`

Returns a human-readable explanation of the permission decision.

### `explainPermission(grants, permission)`

Returns a structured explanation object with the decision, matched grant, and
considered grants.

## React API

```tsx
import { HACSProvider, If, useHACS } from 'hacs/react';
import { defineGrants, makePermission } from 'hacs';

function App() {
  return (
    <HACSProvider
      grants={defineGrants({
        project: 'allow',
        'project.delete': 'deny',
      })}
    >
      <ProjectActions />
    </HACSProvider>
  );
}

function ProjectActions() {
  const { can } = useHACS(defineGrants({
    'project.delete.owner': 'allow',
  }));

  return (
    <If test={can(makePermission('project.delete.owner'))}>
      <button type="button">Delete project</button>
    </If>
  );
}
```

Providers can be nested. Grants from inner providers and `useHACS(localGrants)`
are evaluated together with outer grants, and the most specific matching grant
wins.

The hook can also be typed with the same checked permission collection:

```ts
type AppPermission = 'project.read' | 'project.delete.owner';

const { can } = useHACS<AppPermission>();

can(makePermission<AppPermission>('project.delete.owner'));
```

## Development

```sh
npm install
npm run typecheck
npm test
npm run build
```

The package builds with `tsup`, tests with `vitest`, and exposes separate core
and React entry points.
