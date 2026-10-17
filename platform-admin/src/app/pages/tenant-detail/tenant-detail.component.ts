import { Component, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TenantsService } from '../../services/tenants.service';
import { Tenant } from '../../models/tenant.model';

@Component({
  selector: 'app-tenant-detail',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './tenant-detail.component.html',
  styleUrl: './tenant-detail.component.css',
})
export class TenantDetailComponent implements OnInit {
  readonly tenant = signal<Tenant | null>(null);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);

  // Edit tenant (rename / activate-deactivate)
  readonly editing = signal(false);
  readonly saving = signal(false);
  readonly editError = signal<string | null>(null);
  editNameAr = '';
  editNameEn = '';

  // Renew subscription
  readonly showRenew = signal(false);
  readonly renewing = signal(false);
  readonly renewError = signal<string | null>(null);
  extendDays: number | null = 30;
  renewNoExpiry = false;

  // Add branch
  readonly showAddBranch = signal(false);
  readonly addingBranch = signal(false);
  readonly addBranchError = signal<string | null>(null);

  hotelNameAr = '';
  hotelNameEn = '';
  buildingsCount = 1;
  floorsCount = 4;
  roomsPerFloor = 10;

  private tenantId!: number;

  constructor(
    private route: ActivatedRoute,
    private tenantsService: TenantsService,
  ) {}

  ngOnInit(): void {
    this.tenantId = Number(this.route.snapshot.paramMap.get('id'));
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);

    this.tenantsService.get(this.tenantId).subscribe({
      next: (res) => {
        this.loading.set(false);
        if (res.success) {
          this.tenant.set(res.data);
          this.editNameAr = res.data.name_ar;
          this.editNameEn = res.data.name_en;
        } else {
          this.error.set(res.message || 'تعذر جلب بيانات المستأجر');
        }
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'تعذر جلب بيانات المستأجر');
      },
    });
  }

  startEditing(): void {
    const t = this.tenant();
    if (t) {
      this.editNameAr = t.name_ar;
      this.editNameEn = t.name_en;
    }
    this.editError.set(null);
    this.editing.set(true);
  }

  saveEdit(): void {
    this.saving.set(true);
    this.editError.set(null);

    this.tenantsService.update(this.tenantId, { name_ar: this.editNameAr, name_en: this.editNameEn }).subscribe({
      next: (res) => {
        this.saving.set(false);
        if (res.success) {
          this.editing.set(false);
          this.load();
        } else {
          this.editError.set(res.message || 'تعذر حفظ التعديلات');
        }
      },
      error: (err) => {
        this.saving.set(false);
        this.editError.set(err?.error?.message || 'تعذر حفظ التعديلات');
      },
    });
  }

  toggleActive(): void {
    const t = this.tenant();
    if (!t) return;

    const nextState = !t.is_active;
    const confirmed = nextState || confirm('هل أنت متأكد من تعطيل هذا المستأجر؟ سيُمنع جميع مستخدميه من الدخول فورًا.');
    if (!confirmed) return;

    this.tenantsService.update(this.tenantId, { is_active: nextState }).subscribe({
      next: (res) => {
        if (res.success) {
          this.load();
        } else {
          this.error.set(res.message || 'تعذر تحديث حالة المستأجر');
        }
      },
      error: (err) => {
        this.error.set(err?.error?.message || 'تعذر تحديث حالة المستأجر');
      },
    });
  }

  submitRenewal(): void {
    this.renewing.set(true);
    this.renewError.set(null);

    this.tenantsService
      .renew(this.tenantId, this.renewNoExpiry ? { no_expiry: true } : { extend_days: this.extendDays ?? 0 })
      .subscribe({
        next: (res) => {
          this.renewing.set(false);
          if (res.success) {
            this.showRenew.set(false);
            this.load();
          } else {
            this.renewError.set(res.message || 'تعذر تجديد الاشتراك');
          }
        },
        error: (err) => {
          this.renewing.set(false);
          this.renewError.set(err?.error?.message || 'تعذر تجديد الاشتراك');
        },
      });
  }

  submitBranch(): void {
    this.addingBranch.set(true);
    this.addBranchError.set(null);

    this.tenantsService
      .addHotel(this.tenantId, {
        hotel_name_ar: this.hotelNameAr,
        hotel_name_en: this.hotelNameEn,
        buildings_count: this.buildingsCount,
        floors_count: this.floorsCount,
        rooms_per_floor: this.roomsPerFloor,
      })
      .subscribe({
        next: (res) => {
          this.addingBranch.set(false);
          if (res.success) {
            this.showAddBranch.set(false);
            this.hotelNameAr = '';
            this.hotelNameEn = '';
            this.buildingsCount = 1;
            this.floorsCount = 4;
            this.roomsPerFloor = 10;
            this.load();
          } else {
            this.addBranchError.set(res.message || 'تعذر إضافة الفرع');
          }
        },
        error: (err) => {
          this.addingBranch.set(false);
          this.addBranchError.set(err?.error?.message || 'تعذر إضافة الفرع');
        },
      });
  }
}
