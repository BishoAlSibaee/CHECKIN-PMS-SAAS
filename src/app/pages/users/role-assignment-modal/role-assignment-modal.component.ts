import { Component, Output, EventEmitter, Input, OnInit, OnDestroy } from '@angular/core';
import { RoleService } from '../../../services/role.service';
import { PermissionService } from '../../../services/permission.service';
import { UserAccount, Role } from '../../../models/rbac.models';
import { TranslateService } from '@ngx-translate/core';
import { Subscription } from 'rxjs';

@Component({
    selector: 'app-role-assignment-modal',
    templateUrl: './role-assignment-modal.component.html',
    styleUrls: ['./role-assignment-modal.component.css'],
    standalone: false
})
export class RoleAssignmentModalComponent implements OnInit, OnDestroy {
  @Input() mode: 'single' | 'bulk' = 'single';
  @Input() selectedUserIds: string[] = [];
  @Input() preselectedUserId?: string;
  @Output() closeModal = new EventEmitter<void>();
  @Output() assignmentComplete = new EventEmitter<{ success: boolean; assigned: number; errors: string[] }>();

  selectedRoleId = '';
  isAssigning = false;
  currentLang = 'en';
  private langSub?: Subscription;
  private usersSub?: Subscription;
  private rolesSub?: Subscription;

  searchQuery = '';
  selectedUserId = '';
  filteredUsers: UserAccount[] = [];
  selectedUsers: UserAccount[] = [];
  systemRoles: Role[] = [];
  customRoles: Role[] = [];
  selectedRole: Role | null = null;

  constructor(
    private roleService: RoleService,
    private permissionService: PermissionService,
    private translate: TranslateService
  ) {
    this.currentLang = this.translate.currentLang || 'en';
    this.langSub = this.translate.onLangChange.subscribe(event => {
      this.currentLang = event.lang;
    });

    this.systemRoles = this.roleService.getSystemRolesSync();
    this.customRoles = this.roleService.getUserRolesSync();
  }

  ngOnInit(): void {
    if (this.mode === 'single' && this.preselectedUserId && !this.selectedUserIds.length) {
      this.selectedUserIds = [this.preselectedUserId];
      this.selectedUserId = this.preselectedUserId;
    }

    this.usersSub = this.roleService.users$.subscribe(users => {
      this.filteredUsers = users;
      if (this.mode === 'bulk') {
        this.selectedUsers = users.filter(u => this.selectedUserIds.includes(u.id));
      } else if (this.preselectedUserId) {
        const user = users.find(u => String(u.id) === String(this.preselectedUserId));
        if (user) {
          this.selectedUserId = user.id;
          this.selectedUsers = [user];
          // Pre-select current role so radios reflect what the user already has.
          if (user.roleId) {
            this.selectRole(String(user.roleId));
          }
        }
      }
    });

    this.rolesSub = this.roleService.roles$.subscribe(roles => {
      this.systemRoles = roles.filter(r => r.isSystem);
      this.customRoles = roles.filter(r => !r.isSystem);
      if (this.selectedRoleId) {
        this.selectedRole = this.roleService.getRoleByIdSync(this.selectedRoleId) || null;
      }
    });
  }

  ngOnDestroy(): void {
    this.langSub?.unsubscribe();
    this.usersSub?.unsubscribe();
    this.rolesSub?.unsubscribe();
  }

  currentRoleLabel(user: UserAccount): string {
    if (this.currentLang === 'ar') {
      return user.roleName_ar || user.roleName || '';
    }
    return user.roleName || user.roleName_ar || '';
  }

  close(): void {
    this.closeModal.emit();
  }

  selectRole(roleId: string): void {
    this.selectedRoleId = roleId;
    this.selectedRole = this.roleService.getRoleByIdSync(roleId) || null;
  }

  getPermissionLabel(key: string): string {
    const perm = this.permissionService.getPermissionByKeySync(key);
    if (!perm) return key;
    return this.currentLang === 'ar' ? (perm.label_ar || perm.label) : (perm.label || perm.label_ar);
  }

  assignRole(): void {
    if (!this.selectedRoleId) return;

    this.isAssigning = true;

    if (this.mode === 'bulk') {
      this.roleService.bulkAssignRole({
        userIds: this.selectedUserIds,
        roleId: this.selectedRoleId,
        assignedBy: 'current_user'
      }).subscribe({
        next: (res) => {
          this.isAssigning = false;
          this.assignmentComplete.emit({
            success: res.success,
            assigned: res.success ? this.selectedUserIds.length : 0,
            errors: res.success ? [] : [res.message || this.translate.instant('USERS.MESSAGES.FAILED_ASSIGN_ROLES')]
          });
          if (res.success) this.closeModal.emit();
        },
        error: (err) => {
          this.isAssigning = false;
          this.assignmentComplete.emit({ success: false, assigned: 0, errors: [err.message || this.translate.instant('USERS.MESSAGES.ERROR_OCCURRED')] });
        }
      });
    } else {
      const userId = this.selectedUserId || this.preselectedUserId || this.selectedUserIds[0];
      if (!userId) {
        this.isAssigning = false;
        this.assignmentComplete.emit({
          success: false,
          assigned: 0,
          errors: [this.translate.instant('USERS.MODALS.ASSIGN_ROLE.NO_USERS')]
        });
        return;
      }
      this.roleService.assignRoleToUser({
        userId,
        roleId: this.selectedRoleId,
        assignedBy: 'current_user',
        assignedAt: new Date().toISOString()
      }).subscribe({
        next: (res) => {
          this.isAssigning = false;
          this.assignmentComplete.emit({
            success: res.success,
            assigned: res.success ? 1 : 0,
            errors: res.success ? [] : [res.message || this.translate.instant('USERS.MESSAGES.ASSIGN_ERROR')]
          });
          if (res.success) this.closeModal.emit();
        },
        error: (err) => {
          this.isAssigning = false;
          this.assignmentComplete.emit({ success: false, assigned: 0, errors: [err.message || this.translate.instant('USERS.MESSAGES.ERROR_OCCURRED')] });
        }
      });
    }
  }
}
