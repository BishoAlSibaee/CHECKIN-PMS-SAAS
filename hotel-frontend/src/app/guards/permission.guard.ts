import { Injectable } from '@angular/core';
import { CanActivate, ActivatedRouteSnapshot, Router } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { AuthPermissionService } from '../services/auth-permission.service';
import { DataService } from '../services/Api/data.service';

@Injectable({ providedIn: 'root' })
export class PermissionGuard implements CanActivate {
  constructor(
    private authPerm: AuthPermissionService,
    private router: Router,
    private dataService: DataService,
    private translate: TranslateService
  ) {}

  canActivate(route: ActivatedRouteSnapshot): boolean {
    const required = route.data['permission'] as string | string[] | undefined;
    if (!required) {
      return true;
    }

    const list = Array.isArray(required) ? required : [required];
    const requireAll = !!route.data['requireAllPermissions'];
    const ok = requireAll
      ? list.every(p => this.authPerm.has(p))
      : this.authPerm.hasAny(...list);

    if (!ok) {
      let message = this.translate.instant('AUTH.PERMISSION_DENIED');
      if (message === 'AUTH.PERMISSION_DENIED') {
        message = 'You do not have permission to access this page.';
      }
      this.dataService.showToast(message, 'error');
      this.router.navigate(['/dashboard']);
      return false;
    }

    return true;
  }
}
