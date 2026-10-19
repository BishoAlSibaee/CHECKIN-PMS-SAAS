import { Component, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TenantsService } from '../../services/tenants.service';
import { OnboardTenantResponse, TENANT_CURRENCIES, TenantCurrency } from '../../models/tenant.model';

@Component({
  selector: 'app-onboard-tenant',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './onboard-tenant.component.html',
  styleUrl: './onboard-tenant.component.css',
})
export class OnboardTenantComponent {
  tenantNameAr = '';
  tenantNameEn = '';
  hotelNameAr = '';
  hotelNameEn = '';
  buildingsCount = 1;
  floorsCount = 4;
  roomsPerFloor = 10;
  readonly currencies = TENANT_CURRENCIES;
  currency: TenantCurrency = 'SAR';
  noExpiry = false;
  subscriptionDays: number | null = 14;
  adminName = '';
  adminJobNumber = '';
  adminEmail = '';
  adminMobile = '';
  adminPassword = '';

  readonly submitting = signal(false);
  readonly error = signal<string | null>(null);
  readonly result = signal<OnboardTenantResponse | null>(null);

  constructor(private tenantsService: TenantsService) {}

  submit(): void {
    this.submitting.set(true);
    this.error.set(null);

    this.tenantsService
      .create({
        tenant_name_ar: this.tenantNameAr,
        tenant_name_en: this.tenantNameEn,
        hotel_name_ar: this.hotelNameAr,
        hotel_name_en: this.hotelNameEn,
        buildings_count: this.buildingsCount,
        floors_count: this.floorsCount,
        rooms_per_floor: this.roomsPerFloor,
        currency: this.currency,
        subscription_days: this.noExpiry ? null : this.subscriptionDays,
        admin_name: this.adminName,
        admin_job_number: this.adminJobNumber,
        admin_email: this.adminEmail,
        admin_mobile: this.adminMobile,
        admin_password: this.adminPassword,
      })
      .subscribe({
        next: (res) => {
          this.submitting.set(false);
          if (res.success) {
            this.result.set(res.data);
          } else {
            this.error.set(res.message || 'تعذر إنشاء العميل');
          }
        },
        error: (err) => {
          this.submitting.set(false);
          this.error.set(err?.error?.message || 'تعذر إنشاء العميل. تحقق من الحقول.');
        },
      });
  }
}
