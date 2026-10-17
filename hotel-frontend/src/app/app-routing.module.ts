import { NgModule } from '@angular/core';
import { RouterModule, Routes, PreloadAllModules } from '@angular/router';
import { AuthGuard } from './guards/auth.guard';
import { PermissionGuard } from './guards/permission.guard';

const routes: Routes = [
  { path: '', redirectTo: 'dashboard', pathMatch: 'full' },
  {
    path: 'dashboard',
    canActivate: [AuthGuard],
    loadChildren: () => import('./features/dashboard/dashboard.module').then(m => m.DashboardModule)
  },
  {
    path: 'booking',
    canActivate: [AuthGuard],
    loadChildren: () => import('./features/booking/booking.module').then(m => m.BookingModule)
  },
  {
    path: 'reports',
    canActivate: [AuthGuard, PermissionGuard],
    data: { permission: ['view reports', 'view revenue', 'view earnings', 'view financial reports', 'view accounting reports'] },
    loadChildren: () => import('./features/reports/reports.module').then(m => m.ReportsModule)
  },
  {
    path: 'financials',
    canActivate: [AuthGuard, PermissionGuard],
    data: { permission: ['view revenue', 'view earnings'], requireAllPermissions: true },
    loadChildren: () => import('./features/financials/financials.module').then(m => m.FinancialsModule)
  },
  {
    path: 'admin',
    canActivate: [AuthGuard],
    loadChildren: () => import('./features/admin/admin.module').then(m => m.AdminModule)
  },
  {
    path: 'auth',
    loadChildren: () => import('./features/auth/auth.module').then(m => m.AuthModule)
  },
  { path: '**', redirectTo: 'dashboard' }
];

@NgModule({
  imports: [RouterModule.forRoot(routes, {
    preloadingStrategy: PreloadAllModules,
    useHash: true,
    scrollPositionRestoration: 'enabled',
    anchorScrolling: 'enabled',
  })],
  exports: [RouterModule]
})
export class AppRoutingModule { }
