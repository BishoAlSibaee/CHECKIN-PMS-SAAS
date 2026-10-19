import { Component, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../services/auth.service';

@Component({
  selector: 'app-reset-password',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './reset-password.component.html',
  styleUrl: './reset-password.component.css',
})
export class ResetPasswordComponent implements OnInit {
  email = '';
  token = '';
  password = '';
  passwordConfirmation = '';

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly done = signal(false);
  readonly linkInvalid = signal(false);

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private auth: AuthService,
  ) {}

  ngOnInit(): void {
    const params = this.route.snapshot.queryParamMap;
    this.email = params.get('email') ?? '';
    this.token = params.get('token') ?? '';

    if (!this.email || !this.token) {
      this.linkInvalid.set(true);
    }
  }

  submit(): void {
    if (!this.password || this.password !== this.passwordConfirmation) {
      this.error.set('كلمتا المرور غير متطابقتين.');
      return;
    }

    this.loading.set(true);
    this.error.set(null);

    this.auth
      .resetPassword({
        email: this.email,
        token: this.token,
        password: this.password,
        password_confirmation: this.passwordConfirmation,
      })
      .subscribe({
        next: (res) => {
          this.loading.set(false);
          if (res.success) {
            this.done.set(true);
            setTimeout(() => this.router.navigateByUrl('/login'), 2500);
          } else {
            this.error.set(res.message || 'تعذرت إعادة تعيين كلمة المرور.');
          }
        },
        error: (err) => {
          this.loading.set(false);
          this.error.set(err?.error?.message || 'الرابط غير صالح أو منتهي الصلاحية.');
        },
      });
  }
}
