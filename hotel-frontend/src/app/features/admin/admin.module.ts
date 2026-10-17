import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Routes } from '@angular/router';
import { FormsModule, ReactiveFormsModule } from '@angular/forms';
import { TranslateModule } from '@ngx-translate/core';
import { NgbModule } from '@ng-bootstrap/ng-bootstrap';
import { PropertyComponent } from '../../pages/property/property.component';
import { PricingComponent } from '../../pages/pricing/pricing.component';
import { UsersComponent } from '../../pages/users/users.component';
import { RoleCreateModalComponent } from '../../pages/users/role-create-modal/role-create-modal.component';
import { RoleAssignmentModalComponent } from '../../pages/users/role-assignment-modal/role-assignment-modal.component';
import { UserCreateModalComponent } from '../../pages/users/user-create-modal/user-create-modal.component';
import { ClientsComponent } from '../../pages/clients/clients.component';
import { ClientDetailsComponent } from '../../pages/clients/client-details/client-details.component';
import { RoomHistoryComponent } from '../../pages/property/room-history/room-history.component';
import { DepartmentsComponent } from '../../pages/settings/departments/departments.component';
import { JobTitlesComponent } from '../../pages/settings/job-titles/job-titles.component';
import { PenaltiesComponent } from '../../pages/settings/penalties/penalties.component';
import { RefundPoliciesComponent } from '../../pages/settings/refund-policies/refund-policies.component';
import { DiscountsComponent } from '../../pages/settings/discounts/discounts.component';
import { GuestsComponent } from '../../pages/guests/guests.component';
import { HotelsComponent } from '../../pages/settings/hotels/hotels.component';
import { NightAuditComponent } from '../../pages/settings/night-audit/night-audit.component';
import { OtaAllocationsComponent } from '../../pages/settings/ota-allocations/ota-allocations.component';
import { PlatformLedgerComponent } from '../../pages/settings/platform-ledger/platform-ledger.component';
import { CompaniesListComponent } from '../../pages/companies/companies-list/companies-list.component';
import { CompanyLedgerComponent } from '../../pages/companies/company-ledger/company-ledger.component';
import { PosQuickEntryComponent } from '../../pages/pos/pos-quick-entry/pos-quick-entry.component';
import { PosChargesHistoryComponent } from '../../pages/pos/pos-charges-history/pos-charges-history.component';
import { PosCategoriesComponent } from '../../pages/pos/pos-categories/pos-categories.component';
import { ExternalSubscriptionsComponent } from '../../pages/settings/external-subscriptions/external-subscriptions.component';
import { ChannexConnectionComponent } from '../../pages/settings/channex-connection/channex-connection.component';
import { AirbnbSettingsComponent } from '../../pages/settings/airbnb-settings/airbnb-settings.component';
import { AirbnbRequestsComponent } from '../../pages/settings/airbnb-requests/airbnb-requests.component';
import { ChannexReviewsComponent } from '../../pages/settings/channex-reviews/channex-reviews.component';
import { ChannexMessagesComponent } from '../../pages/settings/channex-messages/channex-messages.component';
import { PermissionGuard } from '../../guards/permission.guard';

const routes: Routes = [
  { path: '', redirectTo: 'property', pathMatch: 'full' },
  {
    path: 'property',
    component: PropertyComponent,
    canActivate: [PermissionGuard],
    data: { permission: ['view buildings', 'view floors', 'view suites', 'view rooms', 'view room types'] }
  },
  {
    path: 'property/room/:number/history',
    component: RoomHistoryComponent,
    canActivate: [PermissionGuard],
    data: { permission: ['view rooms', 'view buildings', 'view floors', 'view suites', 'view room types'] }
  },
  {
    path: 'pricing',
    component: PricingComponent,
    canActivate: [PermissionGuard],
    data: { permission: ['manage pricing plans', 'view room types'] }
  },
  {
    path: 'users',
    component: UsersComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view users' }
  },
  {
    path: 'clients',
    component: ClientsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view clients' }
  },
  {
    path: 'clients/:id',
    component: ClientDetailsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view clients' }
  },
  {
    path: 'departments',
    component: DepartmentsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage departments' }
  },
  {
    path: 'job-titles',
    component: JobTitlesComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage job titles' }
  },
  {
    path: 'penalties',
    component: PenaltiesComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage penalties' }
  },
  {
    path: 'refund-policies',
    component: RefundPoliciesComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage refund policies' }
  },
  {
    path: 'discounts',
    component: DiscountsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage discounts' }
  },
  {
    path: 'guests',
    component: GuestsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage guest classifications' }
  },
  {
    path: 'hotels',
    component: HotelsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage hotels' }
  },
  {
    path: 'night-audit',
    component: NightAuditComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage night audit' }
  },
  {
    path: 'ota-allocations',
    component: OtaAllocationsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage ota allocation' }
  },
  {
    path: 'platform-ledger',
    component: PlatformLedgerComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view platform ledger' }
  },
  {
    path: 'companies',
    component: CompaniesListComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view companies' }
  },
  {
    path: 'companies-ledger',
    component: CompanyLedgerComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view company ledger' }
  },
  {
    path: 'pos-quick-entry',
    component: PosQuickEntryComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'create pos charges' }
  },
  {
    path: 'pos-charges',
    component: PosChargesHistoryComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view pos charges' }
  },
  {
    path: 'pos-categories',
    component: PosCategoriesComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'view pos categories' }
  },
  {
    path: 'external-subscriptions',
    component: ExternalSubscriptionsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage external subscriptions' }
  },
  {
    // Rate plans are now managed from within the OTA allocation page —
    // releasing rooms and pricing them are one workflow, not two.
    path: 'ota-rate-plans',
    redirectTo: 'ota-allocations',
    pathMatch: 'full',
  },
  {
    path: 'channex-connection',
    component: ChannexConnectionComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage ota allocation' }
  },
  {
    path: 'airbnb-settings',
    component: AirbnbSettingsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage airbnb settings' }
  },
  {
    path: 'airbnb-requests',
    component: AirbnbRequestsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage airbnb requests' }
  },
  {
    path: 'channex-reviews',
    component: ChannexReviewsComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage channex reviews' }
  },
  {
    path: 'channex-messages',
    component: ChannexMessagesComponent,
    canActivate: [PermissionGuard],
    data: { permission: 'manage channex messages' }
  }
];

@NgModule({
  declarations: [
    PropertyComponent,
    PricingComponent,
    UsersComponent,
    RoleCreateModalComponent,
    RoleAssignmentModalComponent,
    UserCreateModalComponent,
    ClientsComponent,
    ClientDetailsComponent,
    RoomHistoryComponent,
    DepartmentsComponent,
    JobTitlesComponent,
    PenaltiesComponent,
    RefundPoliciesComponent,
    DiscountsComponent,
    GuestsComponent,
    HotelsComponent,
    NightAuditComponent,
    OtaAllocationsComponent,
    PlatformLedgerComponent,
    CompaniesListComponent,
    CompanyLedgerComponent,
    PosQuickEntryComponent,
    PosChargesHistoryComponent,
    PosCategoriesComponent,
    ExternalSubscriptionsComponent,
    ChannexConnectionComponent,
    AirbnbSettingsComponent,
    AirbnbRequestsComponent,
    ChannexReviewsComponent,
    ChannexMessagesComponent
  ],
  imports: [
    CommonModule,
    FormsModule,
    ReactiveFormsModule,
    TranslateModule,
    RouterModule.forChild(routes),
    NgbModule
  ]
})
export class AdminModule {}
