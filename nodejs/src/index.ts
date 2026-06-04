export type PermissionKey = string & { readonly __permissionKeyBrand: 'PermissionKey' };
export type CheckPermission<Permission extends string = string> = Permission & {
  readonly __checkPermissionBrand: 'CheckPermission';
};
export type PermissionValue = 'allow' | 'deny' | 'inherit';
export type PermissionGrant = Partial<Record<PermissionKey, PermissionValue | boolean>>;
export type PermissionGrantInput = PermissionGrant | readonly PermissionGrant[];

export interface PermissionMatch {
  key: PermissionKey;
  value: PermissionValue;
}

export interface PermissionExplanation<Permission extends string = string> {
  allowed: boolean;
  permission: CheckPermission<Permission>;
  matched: PermissionMatch | null;
  considered: PermissionMatch[];
  reason: string;
}

const PERMISSION_PATTERN = /^(?:\*|[a-zA-Z0-9]+(?:\.[a-zA-Z0-9]+)*)$/;

export function permissionKey(value: string): PermissionKey {
  return normalizePermission(value) as PermissionKey;
}

export function makePermission<Permission extends string = string>(
  value: Permission,
): CheckPermission<Permission> {
  return normalizePermission(value) as CheckPermission<Permission>;
}

export function permissionGrant(grants: Record<string, PermissionValue | boolean>): PermissionGrant {
  const normalizedGrants: PermissionGrant = {};

  for (const [key, value] of Object.entries(grants)) {
    normalizedGrants[permissionKey(key)] = normalizeGrantValue(value);
  }

  return normalizedGrants;
}

export const defineGrants = permissionGrant;

export function test<Permission extends string = string>(
  grants: PermissionGrantInput,
  permission: CheckPermission<Permission>,
): boolean {
  return resolvePermission(grants, permission)?.value === 'allow';
}

export function can<Permission extends string = string>(
  grants: PermissionGrantInput,
  permission: CheckPermission<Permission>,
): boolean {
  return test(grants, permission);
}

export function explain<Permission extends string = string>(
  grants: PermissionGrantInput,
  permission: CheckPermission<Permission>,
): string {
  const explanation = explainPermission(grants, permission);

  if (explanation.matched) {
    return [
      `'${explanation.permission}' is ${explanation.allowed ? 'allowed' : 'denied'}.`,
      `Matched '${explanation.matched.key}' with value '${explanation.matched.value}'.`,
      explanation.reason,
    ].join(' ');
  }

  return [
    `'${explanation.permission}' is denied.`,
    'No matching grant was found, so the default is deny.',
  ].join(' ');
}

export function explainPermission<Permission extends string = string>(
  grants: PermissionGrantInput,
  permission: CheckPermission<Permission>,
): PermissionExplanation<Permission> {
  const normalizedPermission = normalizePermission(permission) as CheckPermission<Permission>;
  const considered = getMatchingGrants(grants, normalizedPermission);
  const matched = considered[considered.length - 1] ?? null;
  const allowed = matched?.value === 'allow';

  return {
    allowed,
    permission: normalizedPermission,
    matched,
    considered,
    reason: matched
      ? 'The most specific matching grant wins.'
      : 'Permissions without an explicit grant are denied.',
  };
}

export function resolvePermission<Permission extends string = string>(
  grants: PermissionGrantInput,
  permission: CheckPermission<Permission>,
): PermissionMatch | null {
  const normalizedPermission = normalizePermission(permission) as CheckPermission<Permission>;
  const considered = getMatchingGrants(grants, normalizedPermission);
  return considered[considered.length - 1] ?? null;
}

function getMatchingGrants(
  grants: PermissionGrantInput,
  permission: string,
): PermissionMatch[] {
  return flattenGrants(grants)
    .filter((grant): grant is PermissionMatch => {
      if (grant.value === 'inherit') {
        return false;
      }

      return grant.key === '*' || permission === grant.key || permission.startsWith(`${grant.key}.`);
    })
    .sort((left, right) => specificity(left.key) - specificity(right.key));
}

function flattenGrants(grants: PermissionGrantInput): PermissionMatch[] {
  const grantList = Array.isArray(grants) ? grants : [grants];

  return grantList.flatMap((grantSet) => {
    const matches: PermissionMatch[] = [];

    for (const [key, value] of Object.entries(grantSet)) {
      if (value === undefined) {
        continue;
      }

      matches.push({
        key: normalizeGrantKey(key),
        value: normalizeGrantValue(value),
      });
    }

    return matches;
  });
}

function normalizePermission(permission: string): string {
  const normalized = permission;

  if (!PERMISSION_PATTERN.test(normalized)) {
    throw new Error(
      `Permission must match ${PERMISSION_PATTERN.source}: ${JSON.stringify(permission)}.`,
    );
  }

  return normalized;
}

function normalizeGrantKey(key: string): PermissionKey {
  const normalized = key;

  if (!PERMISSION_PATTERN.test(normalized)) {
    throw new Error(
      `Permission grant key must match ${PERMISSION_PATTERN.source}: ${JSON.stringify(key)}.`,
    );
  }

  return normalized as PermissionKey;
}

function normalizeGrantValue(value: unknown): PermissionValue {
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
