import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { TranslateModule } from '@ngx-translate/core';
import { ReportsHubComponent } from '../../pages/reports/reports-hub.component';
import { ReportRunnerComponent } from '../../pages/reports/report-runner.component';

const routes: Routes = [
  { path: '', component: ReportsHubComponent },
  { path: 'run/:slug', component: ReportRunnerComponent },
  { path: 'overview', redirectTo: 'run/overview', pathMatch: 'full' },
  { path: 'accrual-revenue', redirectTo: 'run/accrual-revenue', pathMatch: 'full' },
  { path: 'cash-box', redirectTo: 'run/cash-box', pathMatch: 'full' },
  { path: 'accounting/chart-of-accounts', redirectTo: 'run/chart-of-accounts', pathMatch: 'full' },
  { path: 'accounting/journal-entries', redirectTo: 'run/journal-entries', pathMatch: 'full' }
];

@NgModule({
  declarations: [
    ReportsHubComponent,
    ReportRunnerComponent
  ],
  imports: [
    CommonModule,
    FormsModule,
    TranslateModule,
    RouterModule.forChild(routes)
  ]
})
export class ReportsModule {}
