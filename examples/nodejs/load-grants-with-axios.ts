import axios from 'axios';
import {
  test,
  explain,
  Permission,
  PermissionGrants
} from '../../nodejs/src';

type AppPermission =
  | 'project.read'
  | 'project.delete'
  | 'project.delete.owner';

interface GrantRow {
  permission: string;
  access: boolean | null; // example, an alternative could be 'allow'|'deny'|'inherit'
}

async function loadUserGrants(userId: string): Promise<PermissionGrants> {
  const response = await axios.get<GrantRow[]>(`/api/users/${userId}/grants`);

  return PermissionGrants(
    Object.fromEntries(
      response.data.map((row) => [row.permission, row.access]),
    ),
  );
}

async function main() {
  const grants = await loadUserGrants('42');
  const permission = Permission<AppPermission>('project.delete.owner');

  if (test(grants, permission)) {
    console.log('Allowed');
  } else {
    console.log(explain(grants, permission));
  }
}

void main();
