import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { TranslateModule } from '@ngx-translate/core';
import { CalendarModule, DateAdapter } from 'angular-calendar';
import { adapterFactory } from 'angular-calendar/date-adapters/date-fns';
import { BaseChartDirective } from 'ng2-charts';
import { NgbModule } from '@ng-bootstrap/ng-bootstrap';
import { ReservationsComponent } from '../../pages/reservations/reservations.component';
import { PeakPeriodsComponent } from '../../pages/peak-periods/peak-periods.component';
import { ReservationDetailComponent } from '../../pages/reservations/reservation-detail/reservation-detail.component';
import { CreateReservationComponent } from '../../pages/reservations/create-reservation/create-reservation.component';
import { CollectionsComponent } from '../../pages/collections/collections.component';
import { GroupBookingsListComponent } from '../../pages/group-bookings/group-bookings-list/group-bookings-list.component';
import { GroupBookingCreateComponent } from '../../pages/group-bookings/group-booking-create/group-booking-create.component';
import { GroupBookingDetailComponent } from '../../pages/group-bookings/group-booking-detail/group-booking-detail.component';
import { PendingChangesGuard } from '../../guards/pending-changes.guard';
import { PermissionGuard } from '../../guards/permission.guard';

const routes: Routes = [
  { path: '', redirectTo: 'reservations', pathMatch: 'full' },
  {
    path: 'reservations',
    component: ReservationsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view reservations' }
  },
  {
    path: 'reservations/create',
    component: CreateReservationComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'create reservations' }
  },
  {
    path: 'reservations/:id',
    component: ReservationDetailComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view reservations' }
  },
  {
    path: 'collections',
    component: CollectionsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view payments' }
  },
  {
    path: 'group-bookings',
    component: GroupBookingsListComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view group bookings' }
  },
  {
    path: 'group-bookings/create',
    component: GroupBookingCreateComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'create group bookings' }
  },
  {
    path: 'group-bookings/:id',
    component: GroupBookingDetailComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view group bookings' }
  },
  {
    path: 'peak-periods',
    component: PeakPeriodsComponent,
    canActivate: [PermissionGuard],
    canDeactivate: [PendingChangesGuard],
    data: { permission: 'manage peak days' }
  }
];

@NgModule({
  declarations: [ReservationsComponent, PeakPeriodsComponent, ReservationDetailComponent, CreateReservationComponent, CollectionsComponent, GroupBookingsListComponent, GroupBookingCreateComponent, GroupBookingDetailComponent],
  imports: [
    CommonModule,
    FormsModule,
    TranslateModule,
    RouterModule.forChild(routes),
    CalendarModule.forRoot({
      provide: DateAdapter,
      useFactory: adapterFactory
    }),
    BaseChartDirective,
    NgbModule
  ]
})
export class BookingModule {}
