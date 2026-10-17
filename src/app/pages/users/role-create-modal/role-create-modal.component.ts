import { Component, Input, Output, EventEmitter, OnInit } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { RoleService } from '../../../services/role.service';
import { PermissionService } from '../../../services/permission.service';
import { Permission, Role, PermissionCategory, PERMISSION_CATEGORIES } from '../../../models/rbac.models';
import { TranslateService } from '@ngx-translate/core';

@Component({
    selector: 'app-role-create-modal',
    templateUrl: './role-create-modal.component.html',
    styleUrls: ['./role-create-modal.component.css'],
    standalone: false
})
export class RoleCreateModalComponent implements OnInit {
  @Input() roleToEdit: Role | null = null;
  @Output() closeModal = new EventEmitter<void>();
  @Output() roleCreated = new EventEmitter<any>();
  @Output() roleUpdated = new EventEmitter<any>();

  roleForm!: FormGroup;
  categories = PERMISSION_CATEGORIES;
  selectedPermissions: string[] = [];
  expandedCategories: Set<PermissionCategory> = new Set();
  validationResult: any = null;
  isSubmitting = false;
  currentLang: string;

  isLoadingPermissions = false;

  private existingRoleNames: string[] = [];

  constructor(
    private fb: FormBuilder,
    private roleService: RoleService,
    private permissionService: PermissionService,
    private translate: TranslateService
  ) {
    this.currentLang = this.translate.currentLang || 'en';
    this.translate.onLangChange.subscribe(event => {
      this.currentLang = event.lang;
    });
  }

  ngOnInit(): void {
    this.loadInitialData();

    this.roleForm = this.fb.group({
      name: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(50)]],

    });

    if (this.roleToEdit) {
      this.roleForm.patchValue({
        name: this.roleToEdit.name,
      });
      this.selectedPermissions = [...this.roleToEdit.permissions];
    }

    this.roleForm.get('name')?.valueChanges.subscribe(() => {
      this.checkDuplicateName();
    });
  }

  private loadInitialData(): void {
    this.existingRoleNames = this.roleService.getRolesSync()
      .filter(r => !this.roleToEdit || r.id !== this.roleToEdit.id)
      .map(r => r.name.toLowerCase());

    if (this.permissionService.getPermissionsSync().length === 0) {
      this.isLoadingPermissions = true;
      this.permissionService.getPermissions().subscribe({
        next: (permissions) => {
          this.isLoadingPermissions = false;
          this.initializePermissions();
        },
        error: () => {
          this.isLoadingPermissions = false;
        }
      });
    } else {
      this.initializePermissions();
    }
  }

  private initializePermissions(): void {
    if (!this.roleToEdit) {
      const immutablePerms = this.permissionService.getImmutablePermissionsSync().map((p: Permission) => p.key);
      this.selectedPermissions = [...immutablePerms];
    }
    this.validatePermissions();
  }

  getPermissionsByCategory(category: PermissionCategory): Permission[] {
    return this.permissionService.getPermissionsByCategorySync(category);
  }

  getCategoryPermissionCount(category: PermissionCategory): number {
    return this.getPermissionsByCategory(category).length;
  }

  getCategorySelectedCount(category: PermissionCategory): number {
    const perms = this.getPermissionsByCategory(category);
    return perms.filter(p => this.selectedPermissions.includes(p.key)).length;
  }

  isCategoryExpanded(category: PermissionCategory): boolean {
    return this.expandedCategories.has(category);
  }

  toggleCategory(category: PermissionCategory): void {
    if (this.expandedCategories.has(category)) {
      this.expandedCategories.delete(category);
    } else {
      this.expandedCategories.add(category);
    }
  }

  isPermissionSelected(key: string): boolean {
    return this.selectedPermissions.includes(key);
  }

  togglePermission(key: string): void {
    const permission = this.permissionService.getPermissionByKeySync(key);
    if (!permission || permission.isImmutable) return;

    if (this.selectedPermissions.includes(key)) {
      this.selectedPermissions = this.selectedPermissions.filter(p => p !== key);
    } else {
      this.selectedPermissions = [...this.selectedPermissions, key];
    }

    this.validatePermissions();
  }

  selectAll(): void {
    const mutablePerms = this.permissionService.getMutablePermissionsSync().map((p: Permission) => p.key);
    this.selectedPermissions = [
      ...this.permissionService.getImmutablePermissionsSync().map((p: Permission) => p.key),
      ...mutablePerms
    ];
    this.validatePermissions();
  }

  selectPropertyViewBundle(): void {
    const propertyViewKeys = [
      'view buildings',
      'view floors',
      'view suites',
      'view rooms',
      'view room types'
    ];
    this.selectedPermissions = Array.from(new Set([...this.selectedPermissions, ...propertyViewKeys]));
    this.validatePermissions();
  }

  getPermissionLabel(key: string): string {
    const perm = this.permissionService.getPermissionByKeySync(key);
    if (!perm) return key;
    return this.currentLang === 'ar' ? (perm.label_ar || perm.label) : (perm.label || perm.label_ar);
  }

  getDependencyLabels(deps: string[] | undefined): string {
    if (!deps) return '';
    return deps.map(d => this.getPermissionLabel(d)).join(', ');
  }

  private checkDuplicateName(): void {
    const nameControl = this.roleForm.get('name');
    if (!nameControl) return;

    const name = nameControl.value?.toLowerCase();
    if (name && this.existingRoleNames.includes(name)) {
      nameControl.setErrors({ duplicate: true });
    }
  }

  private validatePermissions(): void {
    this.validationResult = this.permissionService.validatePermissionCombination(this.selectedPermissions);

    if (this.validationResult.warnings.length > 0) {
      const resolvedPerms = this.permissionService.resolveDependencies(this.selectedPermissions);
      const addedPerms = resolvedPerms.filter(p => !this.selectedPermissions.includes(p));
      if (addedPerms.length > 0) {
        this.selectedPermissions = resolvedPerms;
      }
    }
  }

  onSubmit(): void {
    if (this.roleForm.invalid || !this.validationResult?.isValid) return;

    this.isSubmitting = true;

    if (this.roleToEdit) {
      this.roleService.updateRole(
        {
          id: this.roleToEdit.id,
          name: this.roleForm.value.name,
          name_ar: this.roleForm.value.name_ar,
          description: this.roleForm.value.description || '',
          permissions: this.selectedPermissions
        },
        'current_user'
      ).subscribe({
        next: (res) => {
          this.isSubmitting = false;
          if (res.success) {
            this.roleUpdated.emit(res.data || { ...this.roleToEdit, ...this.roleForm.value, permissions: this.selectedPermissions });
            this.closeModal.emit();
          } else {
            this.handleError(res);
          }
        },
        error: (err) => {
          this.isSubmitting = false;
        }
      });
    } else {
      this.roleService.createRole(
        {
          name: this.roleForm.value.name,
          name_ar: this.roleForm.value.name_ar,
          description: this.roleForm.value.description || '',
          permissions: this.selectedPermissions
        },
        'current_user'
      ).subscribe({
        next: (res) => {
          this.isSubmitting = false;
          if (res.success) {
            this.roleCreated.emit(res.data || { name: this.roleForm.value.name });
            this.closeModal.emit();
          } else {
            this.handleError(res);
          }
        },
        error: (err) => {
          this.isSubmitting = false;
        }
      });
    }
  }

  private handleError(res: any): void {
    if (res.message?.toLowerCase().includes('already exists')) {
      this.roleForm.get('name')?.setErrors({ duplicate: true });
    }
  }
}
