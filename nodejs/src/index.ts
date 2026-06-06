export type Permission<P extends string = string> = P & { readonly __HACSPerm: 'HACSPermission' };
export type PermissionGrantScope = string & { readonly __HACSScope: 'HACSPermissionScope' };
export type PermissionGrantAccess = 'allow' | 'deny' | 'inherit';

export type PermissionGrants = Record<PermissionGrantScope, PermissionGrantAccess>;
export interface PermissionGrant {
  readonly scope: PermissionGrantScope;
  readonly access: PermissionGrantAccess;
}

export type UncheckedPermissionGrants = Record<string, boolean | PermissionGrantAccess | null | undefined>;

export interface PermissionExplanation<P extends string = string> {
  readonly allowed: boolean;
  readonly permission: Permission<P>;
  readonly matched: PermissionGrant | null;
  readonly considered: PermissionGrant[];
  readonly reason: string;
}

const PERMISSION_PATTERN = /^[a-zA-Z0-9]+(?:\.[a-zA-Z0-9]+)*$/;
const PERMISSION_GRANT_PATTERN = /^(?:\*|[a-zA-Z0-9]+(?:\.[a-zA-Z0-9]+)*)$/;


export function PermissionGrants(grants: UncheckedPermissionGrants): PermissionGrants {
  const normalizedGrants: PermissionGrants = {};

  for (const [key, value] of Object.entries(grants)) {
    normalizedGrants[normalizeGrantScope(key)] = normalizeGrantAccess(value);
  }

  return normalizedGrants;
}

export function Permission<P extends string = string>(permission: P): Permission<P> {
  return normalizePermission(permission);
}

export function test<P extends string = string>(
  grants: PermissionGrants,
  permission: Permission<P>,
): boolean {
  return resolvePermission(grants, permission)?.access === 'allow';
}

export function explain<P extends string = string>(
  grants: PermissionGrants,
  permission: Permission<P>,
): string {
  const explanation = explainPermission(grants, permission);

  if (explanation.matched) {
    return `'${explanation.permission}' is ${explanation.allowed ? 'allowed' : 'denied'}. Matched '${explanation.matched.scope}' with value '${explanation.matched.access}'. ${explanation.reason}`;
  }

  return `'${explanation.permission}' is denied. No matching grant was found, so the default is deny.`;
}

export function explainPermission<P extends string = string>(
  grants: PermissionGrants,
  permission: Permission<P>,
): PermissionExplanation<Permission> {
  const normalizedPermission = normalizePermission(permission) as Permission<P>;
  const considered = getMatchingGrants(grants, normalizedPermission);
  const matched = considered[considered.length - 1] ?? null;
  const allowed = matched?.access === 'allow';

  return {
    allowed,
    permission: normalizedPermission,
    matched,
    considered,
    reason: matched ? 'The most specific matching grant wins.' : 'Permissions without an explicit grant are denied.',
  };
}

export function resolvePermission<P extends string = string>(
  grants: PermissionGrants,
  permission: Permission<P>,
): PermissionGrant | null {
  const normalizedPermission = normalizePermission(permission) as Permission<P>;
  const considered = getMatchingGrants(grants, normalizedPermission);
  return considered[considered.length - 1] ?? null;
}

function getMatchingGrants(
  grants: PermissionGrants,
  permission: string,
): PermissionGrant[] {
  return Object.entries(grants)
    .map(([scope, access]): PermissionGrant => {
      return {
        scope: normalizeGrantScope(scope),
        access: normalizeGrantAccess(access),
      };
    })
    .filter((grant) => {
      if (grant.access === 'inherit') {
        return false;
      }

      return grant.scope === '*' || permission === grant.scope || permission.startsWith(`${grant.scope}.`);
    })
    .sort((left, right) => specificity(left.scope) - specificity(right.scope));
}

export function mergeGrants(grantSets: (PermissionGrants|UncheckedPermissionGrants)[]): PermissionGrants {
  if (grantSets.length === 0) {
    return {};
  }
  if (grantSets.length === 1) {
    return PermissionGrants(grantSets[0]!);
  }
  return grantSets.reduce<PermissionGrants>((merged, grants) => {
    for (const [key, value] of Object.entries(grants)) {
      const scope = normalizeGrantScope(key);
      const access = normalizeGrantAccess(value);
      if (access === 'inherit') {
        continue;
      }
      merged[scope] = access;
    };
    return merged;
  }, {});
}

function normalizePermission<P extends string = string>(permission: string): Permission<P> {
  const normalized = permission;

  if (!PERMISSION_PATTERN.test(normalized)) {
    throw new Error(
      `Permission must match ${PERMISSION_PATTERN.source}: ${JSON.stringify(permission)}.`,
    );
  }

  return normalized as Permission<P>;
}

function normalizeGrantScope(key: string): PermissionGrantScope {
  const normalized = key;

  if (!PERMISSION_GRANT_PATTERN.test(normalized)) {
    throw new Error(
      `Permission grant key must match ${PERMISSION_PATTERN.source}: ${JSON.stringify(key)}.`,
    );
  }

  return normalized as PermissionGrantScope;
}

function normalizeGrantAccess(value: unknown): PermissionGrantAccess {
  if (value === undefined || value === null) {
    return 'inherit';
  }
  if (value === true) {
    return 'allow';
  }
  if (value === false) {
    return 'deny';
  }
  if (value !== 'allow' && value !== 'deny' && value !== 'inherit') {
    throw new Error(`Invalid permission grant value: ${String(value)}.`);
  }

  return value;
}

function specificity(key: string): number {
  if (key === '*') {
    return -1;
  }

  return key.split('.').length;
}
