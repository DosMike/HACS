import { describe, expect, it } from 'vitest';
import {
  can,
  makePermission,
  defineGrants,
  explain,
  explainPermission,
  permissionKey,
  resolvePermission,
  test,
  type CheckPermission,
  type PermissionKey,
} from './index';

describe('permissions', () => {
  it('allows a permission from a matching prefix', () => {
    expect(
      test(
        defineGrants({ project: 'allow' }),
        makePermission('project.update.owner'),
      ),
    ).toBe(true);
  });

  it('denies when the most specific matching grant is deny', () => {
    const grants = defineGrants({
      project: 'allow',
      'project.update': 'deny',
    });

    expect(test(grants, makePermission('project.update.owner'))).toBe(false);
  });

  it('uses the most specific matching grant', () => {
    const grants = defineGrants({
      project: 'allow',
      'project.update': 'deny',
      'project.update.owner': 'allow',
    });

    expect(resolvePermission(grants, makePermission('project.update.owner'))).toEqual({
      key: 'project.update.owner',
      value: 'allow',
    });
  });

  it('does not treat partial segment matches as prefixes', () => {
    const grants = defineGrants({
      project: 'allow',
      projectile: 'deny',
    });

    expect(resolvePermission(grants, makePermission('projectile.delete'))).toEqual({
      key: 'projectile',
      value: 'deny',
    });
    expect(resolvePermission(grants, makePermission('project.delete'))).toEqual({
      key: 'project',
      value: 'allow',
    });
  });

  it('lets later same-specificity grants override earlier grants across grant sets', () => {
    const grants = [
      defineGrants({
        project: 'allow',
        'project.delete': 'deny',
      }),
      defineGrants({
        'project.delete': 'allow',
      }),
    ];

    expect(test(grants, makePermission('project.delete'))).toBe(true);
  });

  it('ignores inherit grants so a broader grant can apply', () => {
    const grants = defineGrants({
      project: 'allow',
      'project.update.owner': 'inherit',
    });

    expect(test(grants, makePermission('project.update.owner'))).toBe(true);
  });

  it('ignores inherit grants even when they are more specific than a deny', () => {
    const grants = defineGrants({
      project: 'allow',
      'project.update': 'deny',
      'project.update.owner': 'inherit',
    });

    expect(resolvePermission(grants, makePermission('project.update.owner'))).toEqual({
      key: 'project.update',
      value: 'deny',
    });
    expect(test(grants, makePermission('project.update.owner'))).toBe(false);
  });

  it('supports a root grant', () => {
    expect(test(defineGrants({ '*': 'allow' }), makePermission('anything.deep'))).toBe(true);
  });

  it('lets a specific grant override a root grant', () => {
    const grants = defineGrants({
      '*': 'allow',
      'admin.delete': 'deny',
    });

    expect(test(grants, makePermission('admin.read'))).toBe(true);
    expect(test(grants, makePermission('admin.delete'))).toBe(false);
  });

  it('supports boolean aliases for allow and deny', () => {
    const grants = defineGrants({
      project: true,
      'project.delete': false,
    });

    expect(test(grants, makePermission('project.read'))).toBe(true);
    expect(test(grants, makePermission('project.delete'))).toBe(false);
  });

  it('defaults to deny without a matching grant', () => {
    const explanation = explainPermission(defineGrants({}), makePermission('project.delete'));

    expect(explanation.allowed).toBe(false);
    expect(explanation.matched).toBeNull();
  });

  it('returns can as an alias for test', () => {
    const grants = defineGrants({ project: 'allow' });
    const permission = makePermission('project.read');

    expect(can(grants, permission)).toBe(test(grants, permission));
  });

  it('returns a structured explanation with considered grants in specificity order', () => {
    const explanation = explainPermission(
      defineGrants({
        '*': 'allow',
        project: 'allow',
        'project.update': 'deny',
        'project.update.owner': 'inherit',
      }),
      makePermission('project.update.owner'),
    );

    expect(explanation).toMatchObject({
      allowed: false,
      permission: 'project.update.owner',
      matched: {
        key: 'project.update',
        value: 'deny',
      },
    });
    expect(explanation.considered).toEqual([
      { key: '*', value: 'allow' },
      { key: 'project', value: 'allow' },
      { key: 'project.update', value: 'deny' },
    ]);
  });

  it('returns a readable explanation for allowed, denied, and default-denied checks', () => {
    expect(explain(defineGrants({ project: 'allow' }), makePermission('project.read'))).toContain(
      "'project.read' is allowed.",
    );
    expect(explain(defineGrants({ project: 'deny' }), makePermission('project.read'))).toContain(
      "'project.read' is denied.",
    );
    expect(explain(defineGrants({}), makePermission('project.read'))).toContain(
      'No matching grant was found',
    );
  });

  it('tags valid grant keys and checked permissions', () => {
    const key: PermissionKey = permissionKey('Project1.Module2.Action3');
    const permission: CheckPermission = makePermission('Project1.Module2.Action3');

    expect(key).toBe('Project1.Module2.Action3');
    expect(permission).toBe('Project1.Module2.Action3');
  });

  it('rejects invalid permission syntax before tagging', () => {
    for (const invalid of [
      '',
      ' ',
      '.project',
      'project.',
      'project..delete',
      'project-delete',
      'project/delete',
      'project delete',
      'project:*',
      'project._delete',
    ]) {
      expect(() => makePermission(invalid), invalid).toThrow('Permission must match');
      expect(() => permissionKey(invalid), invalid).toThrow('Permission must match');
    }
  });

  it('rejects invalid grant keys and values while defining grants', () => {
    expect(() => defineGrants({ 'project-delete': 'allow' })).toThrow(
      'Permission must match',
    );
    expect(() =>
      defineGrants({ project: 'unknown' as unknown as 'allow' }),
    ).toThrow('Invalid permission grant value');
  });

  it('rejects raw strings at compile time for checked permissions', () => {
    const grants = defineGrants({ project: 'allow' });

    // @ts-expect-error Raw strings must be tagged with makePermission first.
    test(grants, 'project.read');
  });

  it('supports user-defined literal collections for checked permissions', () => {
    type AppPermission = 'project.read' | 'project.delete.owner';

    const grants = defineGrants({ project: 'allow' });
    const permission: CheckPermission<AppPermission> =
      makePermission<AppPermission>('project.delete.owner');

    expect(test(grants, permission)).toBe(true);

    // @ts-expect-error Checked permissions must be in the user-defined collection.
    makePermission<AppPermission>('project.delete.member');

    // @ts-expect-error A broadly tagged permission cannot be assigned to a narrower collection.
    const invalidPermission: CheckPermission<AppPermission> =
      makePermission('project.delete.member');

    expect(invalidPermission).toBe('project.delete.member');
  });
});
