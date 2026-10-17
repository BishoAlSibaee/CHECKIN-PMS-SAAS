import { Injectable } from '@angular/core';
import { BehaviorSubject, Observable, of } from 'rxjs';
import { delay, map, tap, catchError } from 'rxjs/operators';
import { HttpClient } from '@angular/common/http';
import { DataService } from './Api/data.service';
import {
  Role,
  RoleCreateRequest,
  RoleUpdateRequest,
  UserAccount,
  UserRoleAssignment,
  BulkRoleAssignment,
  DEFAULT_ROLES,
  PaginatedUsersResponse
} from '../models/rbac.models';
import { PermissionService } from './permission.service';

@Injectable({
  providedIn: 'root'
})
export class RoleService {
  private rolesSubject = new BehaviorSubject<Role[]>(DEFAULT_ROLES);
  private usersSubject = new BehaviorSubject<UserAccount[]>([]);
  roles$ = this.rolesSubject.asObservable();
  users$ = this.usersSubject.asObservable();

  constructor(
    private http: HttpClient,
    private dataService: DataService,
    private permissionService: PermissionService
  ) {}

  getRoles(): Observable<Role[]> {
    return this.http.get<any>(`${this.dataService.Url}users/getRoles`, {
      headers: this.dataService.getHeaders()
    }).pipe(
      map(res => {
        if (res.success) {
          return res.data.map((r: any) => ({
            id: r.id.toString(),
            name: r.name,
            name_ar: r.name_ar,
            description: r.description || '',
            permissions: r.permissions?.map((p: any) => p.name) || [],
            isSystem: r.is_system === 1,
            createdAt: r.created_at,
            updatedAt: r.updated_at,
            createdBy: 'Admin'
          }));
        }
        return [];
      }),
      tap(roles => this.rolesSubject.next(roles)),
      catchError(() => of([]))
    );
  }

  getUsers(page: number = 1, perPage: number = 10, search: string = ''): Observable<PaginatedUsersResponse> {
    let url = `${this.dataService.Url}users/getInfoUsers?page=${page}&per_page=${perPage}`;
    if (search) {
      url += `&search=${encodeURIComponent(search)}`;
    }

    return this.http.get<any>(url, {
      headers: this.dataService.getHeaders()
    }).pipe(
      map(res => {
        if (res.success) {
          const users = res.data.users.map((u: any) => ({
            id: u.id.toString(),
            name: u.name,
            name_ar: u.name_ar,
            username: u.username || u.email.split('@')[0],
            email: u.email,
            phone: u.phone || u.mobile || 'N/A',
            mobile: u.mobile,
            jobNumber: u.job_number,
            jobTitle: u.jobtitle,
            department: u.department,
            discount: u.discount,
            roleId: u.roles?.[0]?.id?.toString() || '',
            roleName: u.roles?.[0]?.name || 'No Role',
            roleName_ar: u.roles?.[0]?.name_ar || 'بدون دور',
            permissions: [
              ...(u.permissions?.map((p: any) => p.name) || []),
              ...(u.roles?.[0]?.permissions?.map((p: any) => p.name) || [])
            ],
            status: u.active === 1 ? 'active' : 'inactive',
            lastActive: u.last_login_at || 'N/A',
            avatar: u.name.substring(0, 2).toUpperCase(),
            createdAt: u.created_at,
            updatedAt: u.updated_at,
            emailVerifiedAt: u.email_verified_at,
            raw: u
          }));

          return {
            users,
            pagination: res.data.pagination
          };
        }
        return {
          users: [],
          pagination: { total: 0, per_page: perPage, current_page: page, last_page: 0, from: 0, to: 0 }
        };
      }),
      tap(response => this.usersSubject.next(response.users)),
      catchError(() => of({
        users: [],
        pagination: { total: 0, per_page: perPage, current_page: page, last_page: 0, from: 0, to: 0 }
      }))
    );
  }

  createRole(request: RoleCreateRequest, createdBy: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addRole`, request, {
      headers: this.dataService.getHeaders()
    });
  }

  updateRole(request: RoleUpdateRequest, updatedBy: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateRole`, request, {
      headers: this.dataService.getHeaders()
    });
  }

  deleteRole(roleId: string, deletedBy: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/deleteRole`, { id: roleId }, {
      headers: this.dataService.getHeaders()
    });
  }

  assignRoleToUser(assignment: UserRoleAssignment): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/assignRole`, assignment, {
      headers: this.dataService.getHeaders()
    });
  }

  bulkAssignRole(assignment: BulkRoleAssignment): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/bulkAssignRole`, assignment, {
      headers: this.dataService.getHeaders()
    });
  }

  toggleUserStatus(userId: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/inActive`, { id: userId }, {
      headers: this.dataService.getHeaders()
    });
  }

  createUser(userData: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addUser`, userData, {
      headers: this.dataService.getHeaders()
    });
  }

  changePassword(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/changePassword`, data, {
      headers: this.dataService.getHeaders()
    });
  }

  removeRoleFromUser(userId: string, removedBy: string): Observable<any> {
    // In Spatie, removing a role usually means assigning a "No Role" or empty roles
    // For now, we'll just use assignRole with an empty or default role if needed
    // but the backend assignRole uses syncRoles which replaces existing roles.
    return this.http.post<any>(`${this.dataService.Url}users/assignRole`, { userId, roleId: '' }, {
      headers: this.dataService.getHeaders()
    });
  }

  getRolesSync(): Role[] {
    return this.rolesSubject.value;
  }

  getUserRolesSync(): Role[] {
    return this.rolesSubject.value.filter(r => !r.isSystem);
  }

  getSystemRolesSync(): Role[] {
    return this.rolesSubject.value.filter(r => r.isSystem);
  }

  getRoleByIdSync(id: string): Role | undefined {
    return this.rolesSubject.value.find(r => r.id === id);
  }

  getRoleByNameSync(name: string): Role | undefined {
    return this.rolesSubject.value.find(r => r.name.toLowerCase() === name.toLowerCase());
  }

  getUserByIdSync(id: string): UserAccount | undefined {
    return this.usersSubject.value.find(u => u.id === id);
  }

  getUsersSync(): UserAccount[] {
    return this.usersSubject.value;
  }

  getUserNameSync(id: string): string {
    return this.getUserByIdSync(id)?.name || 'Unknown';
  }

  getUserPermissionsSync(userId: string): string[] {
    const user = this.getUserByIdSync(userId);
    if (!user) return [];
    const role = this.getRoleByIdSync(user.roleId);
    return role?.permissions || [];
  }

  private getDefaultUsers(): UserAccount[] {
    return [
      { id: 'u1', name: 'Alex Johnson', email: 'alex@staymanager.com', roleId: 'r1', status: 'active', lastActive: 'Now', avatar: 'AJ', createdAt: '2025-01-15' },
      { id: 'u2', name: 'Beth Williams', email: 'beth@staymanager.com', roleId: 'r2', status: 'active', lastActive: '5 min ago', avatar: 'BW', createdAt: '2025-02-20' },
      { id: 'u3', name: 'Carlos Rivera', email: 'carlos@staymanager.com', roleId: 'r3', status: 'active', lastActive: '1 hr ago', avatar: 'CR', createdAt: '2025-03-10' },
      { id: 'u4', name: 'Diana Lee', email: 'diana@staymanager.com', roleId: 'r2', status: 'active', lastActive: '2 hr ago', avatar: 'DL', createdAt: '2025-04-05' },
      { id: 'u5', name: 'Erik Svensson', email: 'erik@staymanager.com', roleId: 'r3', status: 'inactive', lastActive: '3 days ago', avatar: 'ES', createdAt: '2025-05-12' },
      { id: 'u6', name: 'Fatima Noor', email: 'fatima@staymanager.com', roleId: 'r3', status: 'active', lastActive: '30 min ago', avatar: 'FN', createdAt: '2025-06-18' }
    ];
  }
}
