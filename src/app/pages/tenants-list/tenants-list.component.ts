import { Component, OnInit, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../services/auth.service';
import { TenantsService } from '../../services/tenants.service';
import { Tenant } from '../../models/tenant.model';

@Component({
  selector: 'app-tenants-list',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './tenants-list.component.html',
  styleUrl: './tenants-list.component.css',
})
export class TenantsListComponent implements OnInit {
  readonly tenants = signal<Tenant[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);

  constructor(
    private tenantsService: TenantsService,
    readonly auth: AuthService,
  ) {}

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);

    this.tenantsService.list().subscribe({
      next: (res) => {
        this.loading.set(false);
        if (res.success) {
          this.tenants.set(res.data);
        } else {
          this.error.set(res.message || 'تعذر جلب المستأجرين');
        }
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'تعذر جلب المستأجرين');
      },
    });
  }

  daysRemaining(tenant: Tenant): string {
    if (!tenant.subscription_ends_at) {
      return 'بلا انتهاء';
    }

    const ends = new Date(tenant.subscription_ends_at).getTime();
    const diffDays = Math.ceil((ends - Date.now()) / (1000 * 60 * 60 * 24));

    if (diffDays < 0) {
      return 'منتهٍ';
    }

    return `${diffDays} يوم متبقٍ`;
  }

  logout(): void {
    this.auth.logout();
  }
}
