import { Injectable } from '@angular/core';
import { BehaviorSubject, Observable, of } from 'rxjs';
import { delay, map, tap, catchError } from 'rxjs/operators';
import { HttpClient } from '@angular/common/http';
import { DataService } from './Api/data.service';
import { TranslateService } from '@ngx-translate/core';
import {
  Permission,
  PermissionCategory,
  PermissionValidationResult,
  DEFAULT_PERMISSIONS,
  PERMISSION_CATEGORIES
} from '../models/rbac.models';

@Injectable({
  providedIn: 'root'
})
export class PermissionService {
  private permissionsSubject = new BehaviorSubject<Permission[]>(DEFAULT_PERMISSIONS);
  permissions$ = this.permissionsSubject.asObservable();

  constructor(
    private http: HttpClient,
    private dataService: DataService,
    private translate: TranslateService
  ) {}

  getPermissions(): Observable<Permission[]> {
    return this.http.get<any>(`${this.dataService.Url}users/getPermissions`, {
      headers: this.dataService.getHeaders()
    }).pipe(
      map(res => {
        if (res.success) {
          return res.data.map((p: any) => {
            let categoryKey: PermissionCategory = 'Settings';
             const backendCategory = p.category?.name_en?.trim();

             if (backendCategory === 'Infrastructure') categoryKey = 'Infrastructure';
             else if (backendCategory === 'Room Management') categoryKey = 'Room Management';
             else if (backendCategory === 'Settings') categoryKey = 'Settings';
             else if (backendCategory === 'Users & Permissions') categoryKey = 'Users & Permissions';
             else if (backendCategory === 'Reservations') categoryKey = 'Reservations';
             else if (backendCategory === 'Finance & Revenue') categoryKey = 'Finance';
             else if (backendCategory === 'Clients') categoryKey = 'Clients';

            return {
              id: p.id.toString(),
              key: p.name,
              label: p.name_en || p.name,
              label_ar: p.name_ar,
              description: p.description_en || '',
              description_ar: p.description_ar || '',
              category: categoryKey,
              isImmutable: false
            };
          });
        }
        return [];
      }),
      tap(permissions => this.permissionsSubject.next(permissions)),
      catchError(() => of([]))
    );
  }

  getPermissionsSync(): Permission[] {
    return this.permissionsSubject.value;
  }


  getPermissionByKeySync(key: string): Permission | undefined {
    return this.getPermissionsSync().find(p => p.key === key);
  }

  getPermissionsByCategorySync(category: PermissionCategory): Permission[] {
    return this.getPermissionsSync().filter(p => p.category === category);
  }

  getCategories(): { key: PermissionCategory; label: string; icon: string }[] {
    return PERMISSION_CATEGORIES;
  }

  getImmutablePermissionsSync(): Permission[] {
    return this.getPermissionsSync().filter(p => p.isImmutable);
  }

  getMutablePermissionsSync(): Permission[] {
    return this.getPermissionsSync().filter(p => !p.isImmutable);
  }

  isImmutableSync(key: string): boolean {
    return this.getPermissionByKeySync(key)?.isImmutable || false;
  }

  hasPermission(userPermissions: string[], permissionKey: string): boolean {
    return userPermissions.includes(permissionKey);
  }

  validatePermissionCombination(permissionKeys: string[]): PermissionValidationResult {
    const errors: string[] = [];
    const warnings: string[] = [];
    const permissions = this.getPermissionsSync();

    const selectedPermissions = permissions.filter(p => permissionKeys.includes(p.key));

    for (const permission of selectedPermissions) {
      if (permission.dependencies) {
        for (const depKey of permission.dependencies) {
          if (!permissionKeys.includes(depKey)) {
            const depPermission = this.getPermissionByKeySync(depKey);
            const name = this.translate.currentLang === 'ar' ? (permission.label_ar || permission.label) : (permission.label || permission.label_ar);
            const depName = this.translate.currentLang === 'ar' ? (depPermission?.label_ar || depPermission?.label || depKey) : (depPermission?.label || depPermission?.label_ar || depKey);

            warnings.push(
              this.translate.instant('USERS.MESSAGES.VALIDATION.PERMISSIONS_REQUIRES', { name, dependency: depName })
            );
          }
        }
      }

      if (permission.conflictKeys) {
        for (const conflictKey of permission.conflictKeys) {
          if (permissionKeys.includes(conflictKey)) {
            const conflictPermission = this.getPermissionByKeySync(conflictKey);
            const name = this.translate.currentLang === 'ar' ? (permission.label_ar || permission.label) : (permission.label || permission.label_ar);
            const conflictName = this.translate.currentLang === 'ar' ? (conflictPermission?.label_ar || conflictPermission?.label || conflictKey) : (conflictPermission?.label || conflictPermission?.label_ar || conflictKey);

            errors.push(
              this.translate.instant('USERS.MESSAGES.VALIDATION.PERMISSIONS_CONFLICTS', { name, conflict: conflictName })
            );
          }
        }
      }
    }

    return {
      isValid: errors.length === 0,
      errors,
      warnings
    };
  }

  resolveDependencies(permissionKeys: string[]): string[] {
    const resolved = new Set(permissionKeys);
    const permissions = this.getPermissionsSync();
    let changed = true;

    while (changed) {
      changed = false;
      for (const permission of permissions) {
        if (resolved.has(permission.key) && permission.dependencies) {
          for (const depKey of permission.dependencies) {
            if (!resolved.has(depKey)) {
              resolved.add(depKey);
              changed = true;
            }
          }
        }
      }
    }

    return Array.from(resolved);
  }

  checkPermission(userPermissions: string[], requiredPermission: string): boolean {
    return userPermissions.includes(requiredPermission);
  }

  checkPermissions(userPermissions: string[], requiredPermissions: string[]): boolean {
    return requiredPermissions.every(p => userPermissions.includes(p));
  }

  checkAnyPermission(userPermissions: string[], requiredPermissions: string[]): boolean {
    return requiredPermissions.some(p => userPermissions.includes(p));
  }

  getMissingDependencies(permissionKeys: string[]): string[] {
    const missing: string[] = [];
    const permissions = this.getPermissionsSync();

    for (const key of permissionKeys) {
      const permission = permissions.find(p => p.key === key);
      if (permission?.dependencies) {
        for (const depKey of permission.dependencies) {
          if (!permissionKeys.includes(depKey)) {
            const depPermission = this.getPermissionByKeySync(depKey);
            const name = this.translate.currentLang === 'ar' ? (permission.label_ar || permission.label) : (permission.label || permission.label_ar);
            const depName = this.translate.currentLang === 'ar' ? (depPermission?.label_ar || depPermission?.label || depKey) : (depPermission?.label || depPermission?.label_ar || depKey);
            missing.push(this.translate.instant('USERS.MESSAGES.VALIDATION.PERMISSIONS_REQUIRES', { name, dependency: depName }));
          }
        }
      }
    }

    return [...new Set(missing)];
  }
}
