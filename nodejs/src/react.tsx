import {
  type CheckPermission,
  type PermissionGrantInput,
  type PermissionGrant,
  can as checkCan,
  explain,
} from './index';
import {
  type ReactNode,
  type PropsWithChildren,
  createContext,
  useCallback,
  useContext,
  useMemo,
} from 'react';

export interface HACSProviderProps extends PropsWithChildren {
  grants: PermissionGrantInput;
}

export interface UseHACSResult<Permission extends string = string> {
  can: (permission: CheckPermission<Permission>) => boolean;
  explain: (permission: CheckPermission<Permission>) => string;
  grants: readonly PermissionGrant[];
}

const HACSContext = createContext<readonly PermissionGrant[]>([]);

export function HACSProvider({ grants, children }: HACSProviderProps) {
  const parentGrants = useContext(HACSContext);
  const ownGrants = useMemo(() => normalizeGrants(grants), [grants]);
  const combinedGrants = useMemo(
    () => [...parentGrants, ...ownGrants],
    [parentGrants, ownGrants],
  );

  return <HACSContext.Provider value={combinedGrants}>{children}</HACSContext.Provider>;
}

export function useHACS<Permission extends string = string>(
  grants: PermissionGrantInput = {},
): UseHACSResult<Permission> {
  const contextGrants = useContext(HACSContext);
  const localGrants = useMemo(() => normalizeGrants(grants), [grants]);
  const allGrants = useMemo(
    () => [...contextGrants, ...localGrants],
    [contextGrants, localGrants],
  );

  return {
    grants: allGrants,
    can: useCallback(
      (permission: CheckPermission<Permission>) => checkCan(allGrants, permission),
      [allGrants],
    ),
    explain: useCallback(
      (permission: CheckPermission<Permission>) => explain(allGrants, permission),
      [allGrants],
    ),
  };
}

export interface IfProps extends PropsWithChildren {
  test: boolean;
  fallback?: ReactNode;
}

export function If({ test, fallback = null, children }: IfProps) {
  return test ? <>{children}</> : <>{fallback}</>;
}

export const HACSIf = If;

function normalizeGrants(grants: PermissionGrantInput): readonly PermissionGrant[] {
  return isGrantList(grants) ? grants : [grants];
}

function isGrantList(grants: PermissionGrantInput): grants is readonly PermissionGrant[] {
  return Array.isArray(grants);
}
