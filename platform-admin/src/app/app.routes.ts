import { Routes } from '@angular/router';
import { authGuard } from './guards/auth.guard';
import { LoginComponent } from './pages/login/login.component';
import { ForgotPasswordComponent } from './pages/forgot-password/forgot-password.component';
import { ResetPasswordComponent } from './pages/reset-password/reset-password.component';
import { TenantsListComponent } from './pages/tenants-list/tenants-list.component';
import { OnboardTenantComponent } from './pages/onboard-tenant/onboard-tenant.component';
import { TenantDetailComponent } from './pages/tenant-detail/tenant-detail.component';
import { OperatorsComponent } from './pages/operators/operators.component';

export const routes: Routes = [
  { path: 'login', component: LoginComponent },
  { path: 'forgot-password', component: ForgotPasswordComponent },
  { path: 'reset-password', component: ResetPasswordComponent },
  { path: 'tenants', component: TenantsListComponent, canActivate: [authGuard] },
  { path: 'tenants/new', component: OnboardTenantComponent, canActivate: [authGuard] },
  { path: 'operators', component: OperatorsComponent, canActivate: [authGuard] },
  { path: 'tenants/:id', component: TenantDetailComponent, canActivate: [authGuard] },
  { path: '', pathMatch: 'full', redirectTo: 'tenants' },
  { path: '**', redirectTo: 'tenants' },
];
