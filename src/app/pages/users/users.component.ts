import { Component, OnInit } from '@angular/core';
import { RoleService } from '../../services/role.service';
import { PermissionService } from '../../services/permission.service';
import { Role, UserAccount, Permission, PermissionCategory, PERMISSION_CATEGORIES, Pagination } from '../../models/rbac.models';
import { Observable, forkJoin, Subject } from 'rxjs';
import { debounceTime, distinctUntilChanged } from 'rxjs/operators';
import { TranslateService } from '@ngx-translate/core';
import { AuthPermissionService } from '../../services/auth-permission.service';
import { ConfirmDialogService } from '../../services/confirm-dialog.service';
import { DataService } from '../../services/Api/data.service';

type UserTab = 'users' | 'permissions' | 'roles';

@Component({
    selector: 'app-users',
    templateUrl: './users.component.html',
    styleUrls: ['./users.component.css'],
    standalone: false
})
export class UsersComponent implements OnInit {
  activeTab: UserTab = 'users';
  search = '';
  statusFilter: 'all' | 'active' | 'inactive' = 'all';
  permissionSearch = '';
  permissionCategoryFilter: PermissionCategory | 'all' = 'all';
  private searchSubject = new Subject<string>();
  currentLang: string;

  // Pagination state
  currentPage = 1;
  perPage = 10;
  pagination: Pagination = {
    total: 0,
    per_page: 10,
    current_page: 1,
    last_page: 1,
    from: 0,
    to: 0
  };

  selectedUser: UserAccount | null = null;
  selectedUsers: Set<string> = new Set();
  showRoleCreateModal = false;
  showUserCreateModal = false;
  selectedRoleForEdit: Role | null = null;
  showRoleAssignmentModal = false;
  roleAssignmentMode: 'single' | 'bulk' = 'single';
  bulkAssignmentUserIds: string[] = [];
  preselectedUserId?: string;
  currentUserId = 'u1';

  users: UserAccount[] = [];
  roles: Role[] = [];
  permissions: Permission[] = [];
  categories = PERMISSION_CATEGORIES;

  isLoading = false;
  isLoadingPermissions = false;

  get primaryActionLabel(): string {
    return this.activeTab === 'roles' ? 'USERS.CREATE_ROLE' : 'USERS.ADD_USER';
  }

  get canShowPrimaryAction(): boolean {
    if (this.activeTab === 'roles') {
      return this.canManageRoles;
    }
    if (this.activeTab === 'users') {
      return this.canManageUsers;
    }
    return false;
  }

  getPages(): number[] {
    const total = Math.max(1, this.pagination.last_page || 1);
    const current = Math.min(this.pagination.current_page || this.currentPage, total);
    const start = Math.max(1, current - 2);
    const end = Math.min(total, start + 4);
    const adjustedStart = Math.max(1, end - 4);
    return Array.from({ length: end - adjustedStart + 1 }, (_, i) => adjustedStart + i);
  }

  clearFilters(): void {
    this.search = '';
    this.statusFilter = 'all';
    this.currentPage = 1;
    this.loadUsers();
  }

  isHiddenAdmin(user: UserAccount): boolean {
    return user.id === '1' || (user as any).id === 1;
  }

  displayName(user: { name?: string; name_ar?: string } | null | undefined): string {
    if (!user) {
      return '';
    }
    return this.currentLang === 'ar'
      ? (user.name_ar || user.name || '')
      : (user.name || user.name_ar || '');
  }

  jobTitleLabel(user: UserAccount): string {
    const title = user.jobTitle;
    if (!title) {
      return this.translate.instant('COMMON.NA');
    }
    return this.currentLang === 'ar'
      ? (title.name_ar || title.name_en || this.translate.instant('COMMON.NA'))
      : (title.name_en || title.name_ar || this.translate.instant('COMMON.NA'));
  }

  departmentLabel(user: UserAccount): string {
    const dept = user.department;
    if (!dept) {
      return this.translate.instant('COMMON.NA');
    }
    return this.currentLang === 'ar'
      ? (dept.name_ar || dept.name_en || this.translate.instant('COMMON.NA'))
      : (dept.name_en || dept.name_ar || this.translate.instant('COMMON.NA'));
  }

  openPrimaryAction(): void {
    if (this.activeTab === 'roles') {
      this.openRoleCreate();
      return;
    }
    this.openUserCreate();
  }

  constructor(
    private roleService: RoleService,
    private permissionService: PermissionService,
    private translate: TranslateService,
    private confirmDialog: ConfirmDialogService,
    private dataService: DataService,
    public authPerm: AuthPermissionService
  ) {
    this.currentLang = this.translate.currentLang || 'en';
    this.translate.onLangChange.subscribe(event => {
      this.currentLang = event.lang;
    });
  }

  get canManageUsers(): boolean {
    return this.authPerm.has('manage users');
  }

  get canManageRoles(): boolean {
    return this.authPerm.has('manage roles');
  }

  get canManagePermissions(): boolean {
    return this.authPerm.has('manage permissions');
  }

  ngOnInit(): void {
    this.loadData();
    this.activeTab = 'users';
    this.searchSubject.pipe(
      debounceTime(500),
      distinctUntilChanged()
    ).subscribe(() => {
      this.currentPage = 1;
      this.loadUsers();
    });
  }

  onSearchChange(): void {
    this.searchSubject.next(this.search);
  }

  onPageChange(page: number): void {
    if (page >= 1 && page <= this.pagination.last_page && page !== this.currentPage) {
      this.currentPage = page;
      this.loadUsers();
    }
  }

  onPerPageChange(event: any): void {
    this.perPage = +event.target.value;
    this.currentPage = 1;
    this.loadUsers();
  }

  switchTab(tab: UserTab): void {
    this.activeTab = tab;
    if (tab !== 'users') {
      this.selectedUsers.clear();
    }
    this.loadTabContent(tab);
  }

  private loadTabContent(tab: UserTab): void {
    switch (tab) {
      case 'users':
        if (this.users.length === 0) this.loadUsers();
        break;
      case 'roles':
        if (this.roles.length === 0) this.loadRoles();
        break;
      case 'permissions':
        if (this.permissions.length === 0) this.loadPermissions();
        break;
    }
  }

  loadUsersPublic(): void {
    this.loadUsers();
  }

  private loadUsers(): void {
    this.isLoading = true;
    this.roleService.getUsers(this.currentPage, this.perPage, this.search).subscribe({
      next: (response) => {
        this.users = response.users;
        this.pagination = response.pagination;
        this.isLoading = false;
      },
      error: () => {
        this.isLoading = false;
      }
    });
  }

  private loadRoles(): void {
    this.isLoading = true;
    this.roleService.getRoles().subscribe(roles => {
      this.roles = roles;
      this.isLoading = false;
    });
  }

  private loadPermissions(): void {
    this.isLoadingPermissions = true;
    this.permissionService.getPermissions().subscribe(permissions => {
      this.permissions = permissions;
      this.isLoadingPermissions = false;
    });
  }

  loadData(): void {
    this.isLoading = true;
    forkJoin({
      users: this.roleService.getUsers(this.currentPage, this.perPage, this.search),
      roles: this.roleService.getRoles(),
      permissions: this.permissionService.getPermissions()
    }).subscribe({
      next: (data) => {
        this.users = data.users.users;
        this.pagination = data.users.pagination;
        this.roles = data.roles;
        this.permissions = data.permissions;
        this.isLoading = false;
      },
      error: (err) => {
        this.showToastMessage(err.message || this.translate.instant('MESSAGES.LOAD_ERROR'), 'error');
        this.isLoading = false;
      }
    });
  }


  get filteredUsers(): UserAccount[] {
    return this.users.filter(user => {
      if (this.isHiddenAdmin(user)) {
        return false;
      }

      const statusOk =
        this.statusFilter === 'all' ||
        (this.statusFilter === 'active' && user.status === 'active') ||
        (this.statusFilter === 'inactive' && user.status !== 'active');
      if (!statusOk) {
        return false;
      }

      const q = this.search.toLowerCase().trim();
      if (!q) {
        return true;
      }

      const roleName = this.getRoleName(user.roleId).toLowerCase();
      const name = (user.name || '').toLowerCase();
      const name_ar = (user.name_ar || '').toLowerCase();
      const roleName_ar = (user.roleName_ar || '').toLowerCase();

      return (
        name.includes(q) ||
        name_ar.includes(q) ||
        (user.email || '').toLowerCase().includes(q) ||
        roleName.includes(q) ||
        roleName_ar.includes(q) ||
        String(user.jobNumber || '').toLowerCase().includes(q)
      );
    });
  }

  get selectedUsersList(): UserAccount[] {
    return this.users.filter(u => this.selectedUsers.has(u.id));
  }

  getRoleById(roleId: string): Role | undefined {
    return this.roles.find(r => r.id === roleId);
  }

  getRoleName(roleId: string): string {
    const role = this.getRoleById(roleId);
    if (!role) return this.translate.instant('COMMON.UNKNOWN');
    return this.currentLang === 'ar' ? (role.name_ar || role.name) : (role.name || role.name_ar);
  }

  getRoleBadgeClass(roleId: string): string {
    const role = this.getRoleById(roleId);
    if (!role) return 'role-reception';
    const name = role.name.toLowerCase();
    if (name === 'admin') return 'role-admin';
    if (name === 'manager') return 'role-manager';
    return 'role-reception';
  }

  getRoleIcon(roleId: string): string {
    const role = this.getRoleById(roleId);
    if (!role) return 'bi-shield';
    const name = role.name.toLowerCase();
    if (name === 'admin') return 'bi-shield-exclamation';
    if (name === 'manager') return 'bi-shield-check';
    return 'bi-shield';
  }

  isUserSelected(userId: string): boolean {
    return this.selectedUsers.has(userId);
  }

  toggleUserSelection(userId: string): void {
    if (this.selectedUsers.has(userId)) {
      this.selectedUsers.delete(userId);
    } else {
      this.selectedUsers.add(userId);
    }
  }

  selectAllUsers(): void {
    if (this.selectedUsers.size === this.filteredUsers.length) {
      this.selectedUsers.clear();
    } else {
      this.selectedUsers = new Set(this.filteredUsers.map(u => u.id));
    }
  }

  openUserDetails(user: UserAccount): void {
    this.selectedUser = user;
    this.preselectedUserId = user.id;
  }

  closeUserDetails(): void {
    this.selectedUser = null;
    this.preselectedUserId = undefined;
  }

  openRoleAssignment(mode: 'single' | 'bulk', userId?: string): void {
    this.roleAssignmentMode = mode;
    if (mode === 'bulk') {
      this.bulkAssignmentUserIds = Array.from(this.selectedUsers);
    } else if (userId) {
      this.preselectedUserId = userId;
      // Close details modal if it's open
      this.selectedUser = null;
    }
    this.showRoleAssignmentModal = true;
  }

  closeRoleAssignment(): void {
    this.showRoleAssignmentModal = false;
    this.bulkAssignmentUserIds = [];
    this.preselectedUserId = undefined;
  }

  onRoleAssignmentComplete(result: { success: boolean; assigned: number; errors: string[] }): void {
    if (result.success) {
      this.showToastMessage(
        this.translate.instant('USERS.MESSAGES.ASSIGN_SUCCESS', { count: result.assigned }),
        'success'
      );
      this.loadData();
    } else {
      this.showToastMessage(
        result.errors.join('; ') || this.translate.instant('USERS.MESSAGES.ASSIGN_ERROR'),
        'error'
      );
    }
    this.closeRoleAssignment();
    this.selectedUsers.clear();
  }

  openRoleCreate(role?: Role): void {
    if (role) {
      this.selectedRoleForEdit = role;
    } else {
      this.selectedRoleForEdit = null;
    }
    this.showRoleCreateModal = true;
  }

  closeRoleCreate(): void {
    this.showRoleCreateModal = false;
    this.selectedRoleForEdit = null;
  }

  onRoleCreated(role: Role): void {
    const roleName = this.currentLang === 'ar' ? (role.name_ar || role.name) : (role.name || role.name_ar);
    this.showToastMessage(this.translate.instant('USERS.MESSAGES.ROLE_CREATED', { name: roleName }), 'success');
    this.loadData();
  }

  onRoleUpdated(role: Role): void {
    const roleName = this.currentLang === 'ar' ? (role.name_ar || role.name) : (role.name || role.name_ar);
    this.showToastMessage(this.translate.instant('USERS.MESSAGES.ROLE_UPDATED', { name: roleName }), 'success');
    this.loadData();
  }

  openUserCreate(): void {
    this.showUserCreateModal = true;
  }

  closeUserCreate(): void {
    this.showUserCreateModal = false;
  }

  onUserCreated(user: any): void {
    const userName = this.currentLang === 'ar' ? (user.name_ar || user.name) : (user.name || user.name_ar);
    this.showToastMessage(this.translate.instant('USERS.MESSAGES.USER_CREATED', { name: userName }), 'success');
    this.loadData();
  }

  async toggleUserStatus(user: UserAccount): Promise<void> {
    const actionKey = user.status === 'active' ? 'USERS.DEACTIVATE' : 'USERS.ACTIVATE';
    const action = this.translate.instant(actionKey).toLowerCase();
    const userName = this.currentLang === 'ar' ? (user.name_ar || user.name) : (user.name || user.name_ar);

    const confirmMsg = this.translate.instant('USERS.MESSAGES.TOGGLE_STATUS_CONFIRM', {
      action: action,
      name: userName
    });

    const ok = await this.confirmDialog.confirm(confirmMsg);
    if (!ok) {
      return;
    }

    this.roleService.toggleUserStatus(user.id).subscribe({
      next: (res) => {
        if (res.success) {
          const successKey = user.status === 'active' ? 'USERS.MESSAGES.USER_DEACTIVATED' : 'USERS.MESSAGES.USER_ACTIVATED';
          this.showToastMessage(this.translate.instant(successKey, { name: userName }), 'success');
          this.loadData();
          if (this.selectedUser?.id === user.id) {
            this.selectedUser.status = user.status === 'active' ? 'inactive' : 'active';
          }
        } else {
          this.showToastMessage(res.message || this.translate.instant('USERS.MESSAGES.ASSIGN_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.showToastMessage(err.message || this.translate.instant('USERS.MESSAGES.ASSIGN_ERROR'), 'error');
      }
    });
  }

  async deleteRole(roleId: string): Promise<void> {
    const role = this.getRoleById(roleId);
    if (!role) return;

    if (role.isSystem) {
      this.showToastMessage(this.translate.instant('USERS.MESSAGES.SYSTEM_ROLE_DELETE_ERROR'), 'error');
      return;
    }

    const roleName = this.currentLang === 'ar' ? (role.name_ar || role.name) : (role.name || role.name_ar);
    const confirmMsg = this.translate.instant('USERS.MESSAGES.DELETE_ROLE_CONFIRM', { name: roleName });

    const ok = await this.confirmDialog.confirm(confirmMsg);
    if (!ok) {
      return;
    }

    const usersWithRole = this.users.filter(u => u.roleId === roleId);
    if (usersWithRole.length > 0) {
      this.showToastMessage(
        this.translate.instant('USERS.MESSAGES.ROLE_ASSIGNED_ERROR', { count: usersWithRole.length }),
        'error'
      );
      return;
    }

    this.roleService.deleteRole(roleId, this.currentUserId).subscribe({
      next: (res) => {
        if (res.success) {
          this.showToastMessage(this.translate.instant('USERS.MESSAGES.ROLE_DELETED', { name: roleName }), 'success');
          this.loadData();
        } else {
          this.showToastMessage(res.message || this.translate.instant('MESSAGES.ACTION_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.showToastMessage(err.message || this.translate.instant('MESSAGES.ACTION_ERROR'), 'error');
      }
    });
  }

  getPermissionLabel(permKey: string): string {
    const perm = this.permissions.find(p => p.key === permKey);
    if (!perm) return permKey;
    return this.currentLang === 'ar' ? (perm.label_ar || perm.label) : (perm.label || perm.label_ar);
  }

  getPermissionsByCategory(category: PermissionCategory): Permission[] {
    return this.permissions.filter(p => p.category === category);
  }

  permissionCount(category: PermissionCategory): number {
    return this.getPermissionsByCategory(category).length;
  }

  get filteredPermissions(): Permission[] {
    const q = this.permissionSearch.toLowerCase().trim();
    return this.permissions.filter(perm => {
      if (this.permissionCategoryFilter !== 'all' && perm.category !== this.permissionCategoryFilter) {
        return false;
      }
      if (!q) {
        return true;
      }
      const label = (perm.label || '').toLowerCase();
      const labelAr = (perm.label_ar || '').toLowerCase();
      const desc = (perm.description || '').toLowerCase();
      const descAr = (perm.description_ar || '').toLowerCase();
      const key = (perm.key || '').toLowerCase();
      return label.includes(q) || labelAr.includes(q) || desc.includes(q) || descAr.includes(q) || key.includes(q);
    });
  }

  get visiblePermissionCategories(): typeof PERMISSION_CATEGORIES {
    if (this.permissionCategoryFilter !== 'all') {
      return this.categories.filter(c => c.key === this.permissionCategoryFilter);
    }
    const q = this.permissionSearch.toLowerCase().trim();
    if (!q) {
      return this.categories.filter(c => this.permissionCount(c.key) > 0);
    }
    const matched = new Set(this.filteredPermissions.map(p => p.category));
    return this.categories.filter(c => matched.has(c.key));
  }

  getFilteredPermissionsByCategory(category: PermissionCategory): Permission[] {
    const q = this.permissionSearch.toLowerCase().trim();
    return this.getPermissionsByCategory(category).filter(perm => {
      if (!q) {
        return true;
      }
      const label = (perm.label || '').toLowerCase();
      const labelAr = (perm.label_ar || '').toLowerCase();
      const desc = (perm.description || '').toLowerCase();
      const descAr = (perm.description_ar || '').toLowerCase();
      const key = (perm.key || '').toLowerCase();
      return label.includes(q) || labelAr.includes(q) || desc.includes(q) || descAr.includes(q) || key.includes(q);
    });
  }

  clearPermissionFilters(): void {
    this.permissionSearch = '';
    this.permissionCategoryFilter = 'all';
  }

  permLabel(perm: Permission): string {
    return this.currentLang === 'ar' ? (perm.label_ar || perm.label) : (perm.label || perm.label_ar);
  }

  permDescription(perm: Permission): string {
    return this.currentLang === 'ar'
      ? (perm.description_ar || perm.description || '')
      : (perm.description || perm.description_ar || '');
  }

  getRolePermissions(user: UserAccount): string[] {
    if (user.permissions && user.permissions.length > 0) {
      return user.permissions;
    }
    const role = this.getRoleById(user.roleId);
    return role?.permissions || [];
  }

  hasPermission(user: UserAccount, permissionKey: string): boolean {
    return this.getRolePermissions(user).includes(permissionKey);
  }

  private showToastMessage(message: string, type: 'success' | 'error' | 'warning'): void {
    this.dataService.showToast(message, type);
  }

  refreshData(): void {
    this.loadData();
    this.showToastMessage(this.translate.instant('MESSAGES.DATA_REFRESHED'), 'success');
  }
}
