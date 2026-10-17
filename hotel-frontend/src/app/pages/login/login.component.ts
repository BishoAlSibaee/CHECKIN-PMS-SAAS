import { Component } from '@angular/core';
import { Router } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../services/Api/data.service';
import { AuthPermissionService } from '../../services/auth-permission.service';
import { LanguageService } from '../../services/language.service';
import { environment } from '../../../environments/environment';

@Component({
    selector: 'app-login',
    templateUrl: './login.component.html',
    styleUrls: ['./login.component.css'],
    standalone: false
})
export class LoginComponent {
  isSubmitting = false;
  showPassword = false;
  job_number = '';
  password = '';
  error = '';
  readonly currentYear = new Date().getFullYear();

  constructor(
    private router: Router,
    private dataService: DataService,
    private authPerm: AuthPermissionService,
    private translate: TranslateService,
    private language: LanguageService
  ) {}

  togglePassword(): void {
    this.showPassword = !this.showPassword;
  }

  get currentLang(): string {
    return this.language.current;
  }

  switchLanguage(): void {
    this.language.toggle().subscribe();
  }

  onSubmit(): void {
    if (this.isSubmitting) {
      return;
    }

    const jobNumber = this.job_number.trim();
    if (!jobNumber || !this.password) {
      return;
    }

    this.isSubmitting = true;
    this.error = '';

    this.dataService.login({ job_number: jobNumber, password: this.password }).subscribe({
      next: (res: any) => {
        if (!res?.success || !res?.data?.token) {
          this.error = res?.message || this.translate.instant('LOGIN.FAILED');
          this.isSubmitting = false;
          return;
        }

        localStorage.removeItem('user');
        this.dataService.persistLoginSession(res.data);
        const target = this.authPerm.getDefaultRoute();
        this.dataService.syncCurrentUser().subscribe({
          next: () => this.router.navigate([this.authPerm.getDefaultRoute()]),
          error: () => this.router.navigate([target]),
        });
        this.isSubmitting = false;
      },
      error: (err) => {
        this.resolveLoginError(err).then((message) => {
          this.error = message;
          this.isSubmitting = false;
        });
      }
    });
  }

  private async resolveLoginError(err: { status?: number; error?: { message?: string; success?: boolean } }): Promise<string> {
    const apiMessage = err?.error?.message;
    if (typeof apiMessage === 'string' && apiMessage.trim() && apiMessage !== 'Server Error') {
      return apiMessage;
    }

    if (err?.status === 500 || err?.status === 503) {
      const setupHint = await this.fetchSetupHint();
      if (setupHint) {
        return setupHint;
      }
    }

    if (typeof apiMessage === 'string' && apiMessage.trim()) {
      return apiMessage;
    }
    if (err?.status === 0) {
      return this.translate.instant('LOGIN.ERROR_NETWORK');
    }
    if (err?.status === 404) {
      return this.translate.instant('LOGIN.ERROR_API_NOT_FOUND');
    }
    if (!environment.production) {
      return this.translate.instant('LOGIN.ERROR_GENERIC')
        + ` (${environment.apiUrl})`;
    }
    return this.translate.instant('LOGIN.ERROR_SERVER');
  }

  private fetchSetupHint(): Promise<string | null> {
    return new Promise((resolve) => {
      this.dataService.fetchSetupCheck().subscribe({
        next: (res) => {
          const action = res?.data?.action_required;
          const checks = res?.data?.checks as Array<{ name?: string; status?: string; message?: string }> | undefined;
          const pdoFail = checks?.find((c) => c.name === 'php_pdo_extension' && c.status === 'fail');
          if (typeof action === 'string' && action.trim()) {
            resolve(action);
            return;
          }
          if (pdoFail) {
            resolve(this.translate.instant('LOGIN.ERROR_PDO'));
            return;
          }
          if (res?.data?.ready === false) {
            resolve(this.translate.instant('LOGIN.ERROR_SERVER'));
            return;
          }
          resolve(null);
        },
        error: () => resolve(null),
      });
    });
  }
}
