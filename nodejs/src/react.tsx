import {
  Permission,
  PermissionGrants,
  test,
  explain,
  mergeGrants,
  UncheckedPermissionGrants,
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
  grants: PermissionGrants | UncheckedPermissionGrants;
}

export interface UseHACSResult<P extends string = string> {
  can: (permission: Permission<P>) => boolean;
  explain: (permission: Permission<P>) => string;
  grants: PermissionGrants;
}

const HACSContext = createContext<PermissionGrants>({});

export function HACSProvider({ grants, children }: HACSProviderProps) {
  const checked = useMemo(()=>PermissionGrants(grants), [grants]);
  return <HACSContext.Provider value={checked}>{children}</HACSContext.Provider>;
}

export function useHACS<P extends string = string>(
  grants: PermissionGrants | UncheckedPermissionGrants = {},
): UseHACSResult<P> {
  const contextGrants = useContext(HACSContext);
  const allGrants = useMemo(
    () => mergeGrants([contextGrants, PermissionGrants(grants)]),
    [contextGrants, grants],
  );

  return {
    grants: allGrants,
    can: useCallback(
      (permission: Permission<P>) => test(allGrants, permission),
      [allGrants],
    ),
    explain: useCallback(
      (permission: Permission<P>) => explain(allGrants, permission),
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
