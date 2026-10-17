import { Injectable } from '@angular/core';
import { DataService } from './Api/data.service';

@Injectable({ providedIn: 'root' })
export class AuthPermissionService {
  /** Seeded system admin job numbers — always full sidebar/API access in UI. */
  private static readonly ADMIN_JOB_NUMBERS = new Set(['001', '1']);

  private normalizeJobNumber(value: unknown): string {
    const raw = String(value ?? '').trim();
    if (raw === '') {
      return '';
    }
    if (/^\d+$/.test(raw)) {
      return raw.padStart(3, '0');
    }
    return raw;
  }

  constructor(private dataService: DataService) {}

  getPermissions(): string[] {
    return this.normalizeStrings(this.readUser()?.permissions);
  }

  getRoles(): string[] {
    return this.normalizeStrings(this.readUser()?.roles);
  }

  isAdmin(): boolean {
    const user = this.readUser();
    if (!user) {
      return false;
    }

    const roles = this.getRoles().map((r) => r.toLowerCase());
    if (roles.includes('admin')) {
      return true;
    }

    const role = String(user.role ?? '').toLowerCase();
    if (role === 'admin') {
      return true;
    }

    const jobNumber = this.normalizeJobNumber(user.job_number);
    if (AuthPermissionService.ADMIN_JOB_NUMBERS.has(jobNumber)) {
      return true;
    }

    return false;
  }

  /** Admin role or full permission set from backend. */
  hasFullAccess(): boolean {
    if (this.isAdmin()) {
      return true;
    }

    const perms = this.getPermissions();
    return perms.includes('manage permissions') && perms.includes('manage roles');
  }

  has(permission: string): boolean {
    if (this.hasFullAccess()) {
      return true;
    }
    const perms = this.getPermissions();
    if (perms.length === 0) {
      return false;
    }
    return perms.includes(permission);
  }

  hasAny(...permissions: string[]): boolean {
    if (this.hasFullAccess()) {
      return true;
    }
    return permissions.some(p => this.has(p));
  }

  /** First sidebar route the user may open after login. */
  getDefaultRoute(): string {
    if (this.hasFullAccess()) {
      return '/dashboard';
    }

    const candidates: { path: string; permissions?: string[]; requireAll?: boolean }[] = [
      { path: '/dashboard' },
      { path: '/dashboard/room-board', permissions: ['view reservations'] },
      { path: '/booking/reservations', permissions: ['view reservations'] },
      { path: '/admin/clients', permissions: ['view clients'] },
      { path: '/admin/property', permissions: ['view buildings', 'view floors', 'view suites', 'view rooms', 'view room types'] },
      { path: '/admin/pricing', permissions: ['manage pricing plans', 'view room types'] },
      { path: '/financials', permissions: ['view revenue', 'view earnings'], requireAll: true },
      { path: '/reports', permissions: ['view reports', 'view revenue', 'view earnings', 'view financial reports', 'view accounting reports'] },
      { path: '/admin/users', permissions: ['view users'] },
    ];

    for (const item of candidates) {
      if (!item.permissions?.length) {
        return item.path;
      }
      if (item.requireAll) {
        if (item.permissions.every(p => this.has(p))) {
          return item.path;
        }
      } else if (this.hasAny(...item.permissions)) {
        return item.path;
      }
    }

    return '/dashboard';
  }

  private readUser(): any {
    return this.dataService.user$.value ?? this.dataService.getSessionUser();
  }

  private readStoredUser(): any {
    return this.dataService.getSessionUser();
  }

  private normalizeStrings(value: unknown): string[] {
    if (Array.isArray(value)) {
      return value.map((v: any) => (typeof v === 'string' ? v : v?.name)).filter(Boolean);
    }
    if (value && typeof value === 'object') {
      return Object.values(value as Record<string, unknown>)
        .map((v) => (typeof v === 'string' ? v : (v as any)?.name))
        .filter(Boolean) as string[];
    }
    return [];
  }
}
