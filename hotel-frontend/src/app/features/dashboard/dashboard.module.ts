import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule, Routes } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { BaseChartDirective } from 'ng2-charts';
import { DashboardComponent } from '../../pages/dashboard/dashboard.component';
import { RoomBoardComponent } from '../../pages/room-board/room-board.component';
import { PermissionGuard } from '../../guards/permission.guard';

const routes: Routes = [
  { path: '', component: DashboardComponent },
  {
    path: 'room-board',
    component: RoomBoardComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view reservations' }
  }
];

@NgModule({
  declarations: [DashboardComponent, RoomBoardComponent],
  imports: [
    CommonModule,
    FormsModule,
    TranslateModule,
    BaseChartDirective,
    RouterModule.forChild(routes),
  ]
})
export class DashboardModule {}
