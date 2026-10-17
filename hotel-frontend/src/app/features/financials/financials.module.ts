import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule, Routes } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { BaseChartDirective } from 'ng2-charts';
import { NgbModule } from '@ng-bootstrap/ng-bootstrap';
import { FinancialsComponent } from '../../pages/financials/financials.component';

const routes: Routes = [
  { path: '', component: FinancialsComponent }
];

@NgModule({
  declarations: [FinancialsComponent],
  imports: [
    CommonModule,
    FormsModule,
    TranslateModule,
    RouterModule.forChild(routes),
    BaseChartDirective,
    NgbModule
  ]
})
export class FinancialsModule {}
