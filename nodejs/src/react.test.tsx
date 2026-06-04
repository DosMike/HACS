/**
 * @vitest-environment jsdom
 */
import { describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach } from 'vitest';
import { makePermission, defineGrants } from './index';
import { HACSProvider, If, useHACS } from './react';

afterEach(() => {
  cleanup();
});

describe('react exports', () => {
  it('exports provider and hook functions', () => {
    expect(HACSProvider).toBeTypeOf('function');
    expect(useHACS).toBeTypeOf('function');
  });
});

describe('HACSProvider and useHACS', () => {
  it('allows components to check provider grants', () => {
    render(
      <HACSProvider grants={defineGrants({ project: 'allow' })}>
        <PermissionProbe permission="project.read" />
      </HACSProvider>,
    );

    expect(screen.getByText('allowed')).toBeTruthy();
  });

  it('combines provider grants with local hook grants', () => {
    render(
      <HACSProvider grants={defineGrants({ project: 'allow', 'project.delete': 'deny' })}>
        <PermissionProbe
          localGrants={defineGrants({ 'project.delete.owner': 'allow' })}
          permission="project.delete.owner"
        />
      </HACSProvider>,
    );

    expect(screen.getByText('allowed')).toBeTruthy();
  });

  it('lets nested providers add more specific grants', () => {
    render(
      <HACSProvider grants={defineGrants({ project: 'allow', 'project.delete': 'deny' })}>
        <HACSProvider grants={defineGrants({ 'project.delete.owner': 'allow' })}>
          <PermissionProbe permission="project.delete.owner" />
        </HACSProvider>
      </HACSProvider>,
    );

    expect(screen.getByText('allowed')).toBeTruthy();
  });

  it('lets later same-key local grants override provider grants', () => {
    render(
      <HACSProvider grants={defineGrants({ 'project.delete': 'deny' })}>
        <PermissionProbe
          localGrants={defineGrants({ 'project.delete': 'allow' })}
          permission="project.delete"
        />
      </HACSProvider>,
    );

    expect(screen.getByText('allowed')).toBeTruthy();
  });

  it('exposes all merged grant sets to consumers', () => {
    render(
      <HACSProvider grants={defineGrants({ project: 'allow' })}>
        <HACSProvider grants={defineGrants({ 'project.delete': 'deny' })}>
          <GrantCount localGrants={defineGrants({ 'project.delete.owner': 'allow' })} />
        </HACSProvider>
      </HACSProvider>,
    );

    expect(screen.getByText('3')).toBeTruthy();
  });

  it('returns explanations from the merged grant set', () => {
    render(
      <HACSProvider grants={defineGrants({ project: 'allow', 'project.delete': 'deny' })}>
        <ExplanationProbe permission="project.delete.owner" />
      </HACSProvider>,
    );

    expect(screen.getByText(/Matched 'project.delete'/u)).toBeTruthy();
  });
});

describe('If', () => {
  it('renders children when the test passes', () => {
    render(
      <If test fallback={<span>blocked</span>}>
        <span>visible</span>
      </If>,
    );

    expect(screen.getByText('visible')).toBeTruthy();
    expect(screen.queryByText('blocked')).toBeNull();
  });

  it('renders fallback when the test fails', () => {
    render(
      <If test={false} fallback={<span>blocked</span>}>
        <span>visible</span>
      </If>,
    );

    expect(screen.getByText('blocked')).toBeTruthy();
    expect(screen.queryByText('visible')).toBeNull();
  });
});

interface PermissionProbeProps {
  permission: string;
  localGrants?: Parameters<typeof useHACS>[0];
}

function PermissionProbe({ permission, localGrants }: PermissionProbeProps) {
  const { can } = useHACS(localGrants);

  return <span>{can(makePermission(permission)) ? 'allowed' : 'denied'}</span>;
}

interface ExplanationProbeProps {
  permission: string;
}

function ExplanationProbe({ permission }: ExplanationProbeProps) {
  const hacs = useHACS();

  return <span>{hacs.explain(makePermission(permission))}</span>;
}

interface GrantCountProps {
  localGrants: Parameters<typeof useHACS>[0];
}

function GrantCount({ localGrants }: GrantCountProps) {
  const { grants } = useHACS(localGrants);

  return <span>{grants.length}</span>;
}
