import { describe, expect, it } from 'vitest';
import {
  explain,
  explainPermission,
  mergeGrants,
  PermissionGrants,
  resolvePermission,
  test,
  Permission,
} from './index';

type TestPermissions =
  'project.update.owner';

describe('permissions', () => {
  it('allows a permission from a matching prefix', () => {
    expect(
      test<TestPermissions>(
        PermissionGrants({ project: 'allow' }),
        Permission('project.update.owner'),
      ),
    ).toBe(true);
  });

  it('denies when the most specific matching grant is deny', () => {
    const grants = PermissionGrants({
      project: 'allow',
      'project.update': 'deny',
    });

    expect(test(grants, Permission('project.update.owner'))).toBe(false);
  });

  it('uses the most specific matching grant', () => {
    const grants = PermissionGrants({
      project: 'allow',
      'project.update': 'deny',
      'project.update.owner': 'allow',
    });

    expect(resolvePermission(grants, Permission('project.update.owner'))).toEqual({
      scope: 'project.update.owner',
      access: 'allow',
    });
  });

  it('does not treat partial segment matches as prefixes', () => {
    const grants = PermissionGrants({
      project: 'allow',
      projectile: 'deny',
    });

    expect(resolvePermission(grants, Permission('projectile.delete'))).toEqual({
      scope: 'projectile',
      access: 'deny',
    });
    expect(resolvePermission(grants, Permission('project.delete'))).toEqual({
      scope: 'project',
      access: 'allow',
    });
  });

  it('lets later same-specificity grants override earlier grants when merging grant sets', () => {
    const grants = mergeGrants([
      PermissionGrants({
        project: 'allow',
        'project.delete': 'deny',
      }),
      PermissionGrants({
        'project.delete': 'allow',
      }),
      PermissionGrants({})
    ]);

    expect(test(grants, Permission('project.delete'))).toBe(true);
  });

  it('ignores inherit grants so a broader grant can apply', () => {
    const grants = PermissionGrants({
      project: 'allow',
      'project.update.owner': 'inherit',
    });

    expect(test(grants, Permission('project.update.owner'))).toBe(true);
  });

  it('ignores inherit grants even when they are more specific than a deny', () => {
    const grants = PermissionGrants({
      project: 'allow',
      'project.update': 'deny',
      'project.update.owner': 'inherit',
    });

    expect(resolvePermission(grants, Permission('project.update.owner'))).toEqual({
      scope: 'project.update',
      access: 'deny',
    });
    expect(test(grants, Permission('project.update.owner'))).toBe(false);
  });

  it('supports a root grant', () => {
    expect(test(PermissionGrants({ '*': 'allow' }), Permission('anything.deep'))).toBe(true);
  });

  it('lets a specific grant override a root grant', () => {
    const grants = PermissionGrants({
      '*': 'allow',
      'admin.delete': 'deny',
    });

    expect(test(grants, Permission('admin.read'))).toBe(true);
    expect(test(grants, Permission('admin.delete'))).toBe(false);
  });

  it('supports boolean aliases for allow and deny', () => {
    const grants = PermissionGrants({
      project: true,
      'project.delete': false,
    });

    expect(test(grants, Permission('project.read'))).toBe(true);
    expect(test(grants, Permission('project.delete'))).toBe(false);
  });

  it('defaults to deny without a matching grant', () => {
    const explanation = explainPermission(PermissionGrants({}), Permission('project.delete'));

    expect(explanation.allowed).toBe(false);
    expect(explanation.matched).toBeNull();
  });

  it('returns a structured explanation with considered grants in specificity order', () => {
    const explanation = explainPermission(
      PermissionGrants({
        '*': 'allow',
        project: 'allow',
        'project.update': 'deny',
        'project.update.owner': 'inherit',
      }),
      Permission('project.update.owner'),
    );

    expect(explanation).toMatchObject({
      allowed: false,
      permission: 'project.update.owner',
      matched: {
        scope: 'project.update',
        access: 'deny',
      },
    });
    expect(explanation.considered).toEqual([
      { scope: '*', access: 'allow' },
      { scope: 'project', access: 'allow' },
      { scope: 'project.update', access: 'deny' },
    ]);
  });

  it('returns a readable explanation for allowed, denied, and default-denied checks', () => {
    expect(explain(PermissionGrants({ project: 'allow' }), Permission('project.read'))).toContain(
      "'project.read' is allowed.",
    );
    expect(explain(PermissionGrants({ project: 'deny' }), Permission('project.read'))).toContain(
      "'project.read' is denied.",
    );
    expect(explain(PermissionGrants({}), Permission('project.read'))).toContain(
      'No matching grant was found',
    );
  });

  it('tags valid grant keys and checked permissions', () => {
    const checkedPermission: Permission = Permission('Project1.Module2.Action3');

    expect(checkedPermission).toBe('Project1.Module2.Action3');
  });

  it('rejects invalid permission syntax before tagging', () => {
    for (const invalid of [
      '',
      ' ',
      '*',
      '.project',
      'project.',
      'project..delete',
      'project-delete',
      'project/delete',
      'project delete',
      'project:*',
      'project._delete',
    ]) {
      expect(() => Permission(invalid), invalid).toThrow('Permission must match');
    }
  });

  it('rejects invalid grant keys and values while defining grants', () => {
    expect(() => PermissionGrants({ 'project-delete': 'allow' })).toThrow(
      'Permission grant key must match ^[a-zA-Z0-9]+(?:\\.[a-zA-Z0-9]+)*$: "project-delete".',
    );
    expect(() =>
      PermissionGrants({ project: 'unknown' as unknown as 'allow' }),
    ).toThrow('Invalid permission grant value');
  });

  it('rejects raw strings at compile time for checked permissions', () => {
    const grants = PermissionGrants({ project: 'allow' });

    // @ts-expect-error Raw strings must be tagged with permission first.
    test(grants, 'project.read');
  });

  it('supports user-defined literal collections for checked permissions', () => {
    type AppPermission = 'project.read' | 'project.delete.owner';

    const grants = PermissionGrants({ project: 'allow' });
    const checkedPermission: Permission<AppPermission> =
      Permission<AppPermission>('project.delete.owner');

    expect(test(grants, checkedPermission)).toBe(true);
  });
});
