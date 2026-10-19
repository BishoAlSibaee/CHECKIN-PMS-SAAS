import { Component, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../services/auth.service';

@Component({
  selector: 'app-forgot-password',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './forgot-password.component.html',
  styleUrl: './forgot-password.component.css',
})
export class ForgotPasswordComponent {
  email = '';
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly sent = signal(false);

  constructor(private auth: AuthService) {}

  submit(): void {
    if (!this.email) {
      return;
    }

    this.loading.set(true);
    this.error.set(null);

    this.auth.forgotPassword(this.email).subscribe({
      next: (res) => {
        this.loading.set(false);
        if (res.success) {
          this.sent.set(true);
        } else {
          this.error.set(res.message || 'حدث خطأ، حاول مرة أخرى.');
        }
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'حدث خطأ، حاول مرة أخرى.');
      },
    });
  }
}
