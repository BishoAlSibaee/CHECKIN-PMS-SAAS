import { Component, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { PlatformAdminsService } from '../../services/platform-admins.service';
import { PlatformAdminAccount } from '../../models/platform-admin.model';

@Component({
  selector: 'app-operators',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './operators.component.html',
  styleUrl: './operators.component.css',
})
export class OperatorsComponent implements OnInit {
  readonly operators = signal<PlatformAdminAccount[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);

  readonly showAddForm = signal(false);
  readonly adding = signal(false);
  readonly addError = signal<string | null>(null);

  name = '';
  email = '';
  password = '';

  constructor(private platformAdminsService: PlatformAdminsService) {}

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);

    this.platformAdminsService.list().subscribe({
      next: (res) => {
        this.loading.set(false);
        if (res.success) {
          this.operators.set(res.data);
        } else {
          this.error.set(res.message || 'تعذر جلب قائمة المشغّلين');
        }
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'تعذر جلب قائمة المشغّلين');
      },
    });
  }

  submit(): void {
    this.adding.set(true);
    this.addError.set(null);

    this.platformAdminsService.create({ name: this.name, email: this.email, password: this.password }).subscribe({
      next: (res) => {
        this.adding.set(false);
        if (res.success) {
          this.showAddForm.set(false);
          this.name = '';
          this.email = '';
          this.password = '';
          this.load();
        } else {
          this.addError.set(res.message || 'تعذر إضافة المشغّل');
        }
      },
      error: (err) => {
        this.adding.set(false);
        this.addError.set(err?.error?.message || 'تعذر إضافة المشغّل');
      },
    });
  }
}
