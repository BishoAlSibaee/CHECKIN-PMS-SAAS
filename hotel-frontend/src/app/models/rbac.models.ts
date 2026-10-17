export interface Pagination {
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
  from: number;
  to: number;
}

export interface PaginatedUsersResponse {
  users: UserAccount[];
  pagination: Pagination;
}

export interface Permission {
  id: string;
  key: string;
  label: string;
  label_ar?: any;
  description: string;
  description_ar?: string;
  category: PermissionCategory;
  isImmutable: boolean;
  dependencies?: string[];
  conflictKeys?: string[];
}

export type PermissionCategory =
  | 'Infrastructure'
  | 'Room Management'
  | 'Settings'
  | 'Users & Permissions'
  | 'Reservations'
  | 'Finance'
  | 'Clients';

export interface Role {
  id: string;
  name: any;
  name_ar?: string;
  description: string;
  description_ar?: string;
  permissions: string[];
  isSystem: boolean;
  createdAt: string;
  updatedAt: string;
  createdBy: string;
}

export interface UserAccount {
  id: string;
  name: string;
  name_ar?: string;
  username?: string;
  email: string;
  phone?: string;
  mobile?: string;
  jobNumber?: string;
  jobTitle?: { id: number; name_en: string; name_ar: string };
  department?: { id: number; name_en: string; name_ar: string };
  discount?: { id: number; name: string; name_ar?: string; percent?: number; fixed_amount?: number };
  roleId: string;
  roleName?: string;
  roleName_ar?: string;
  permissions?: string[];
  status: 'active' | 'inactive' | 'suspended';
  lastActive: string;
  avatar: string;
  createdAt: string;
  updatedAt?: string;
  emailVerifiedAt?: string;
  raw?: any;
}

export interface RoleCreateRequest {
  name: string;
  name_ar: string;
  description: string;
  description_ar?: string;
  permissions: string[];
}

export interface RoleUpdateRequest {
  id: string;
  name?: string;
  name_ar?: string;
  description?: string;
  description_ar?: string;
  permissions?: string[];
}

export interface UserRoleAssignment {
  userId: string;
  roleId: string;
  assignedBy: string;
  assignedAt: string;
}

export interface BulkRoleAssignment {
  userIds: string[];
  roleId: string;
  assignedBy: string;
}

export interface PermissionValidationResult {
  isValid: boolean;
  errors: string[];
  warnings: string[];
}

export const PERMISSION_CATEGORIES: { key: PermissionCategory; label: string; icon: string }[] = [
  { key: 'Infrastructure', label: 'PERMISSIONS_CATEGORIES2.INFRASTRUCTURE', icon: 'bi-building' },
  { key: 'Room Management', label: 'PERMISSIONS_CATEGORIES2.ROOM_MGMT', icon: 'bi-door-open' },
  { key: 'Settings', label: 'PERMISSIONS_CATEGORIES2.SETTINGS', icon: 'bi-gear' },
  { key: 'Users & Permissions', label: 'PERMISSIONS_CATEGORIES2.USERS_PERMS', icon: 'bi-people' },
  { key: 'Reservations', label: 'PERMISSIONS_CATEGORIES2.RESERVATIONS', icon: 'bi-calendar-check' },
  { key: 'Finance', label: 'PERMISSIONS_CATEGORIES2.FINANCE', icon: 'bi-wallet2' },
  { key: 'Clients', label: 'PERMISSIONS_CATEGORIES2.CLIENTS', icon: 'bi-person-badge' }
];

export const DEFAULT_PERMISSIONS: Permission[] = [];
export const DEFAULT_ROLES: Role[] = [];
