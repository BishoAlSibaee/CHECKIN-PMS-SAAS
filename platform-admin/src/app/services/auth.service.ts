import { Injectable, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';
import { environment } from '../../environments/environment';
import { ApiEnvelope, LoginRequest, LoginResponseData } from '../models/auth.model';

const TOKEN_KEY = 'platform_admin_token';
const NAME_KEY = 'platform_admin_name';

@Injectable({ providedIn: 'root' })
export class AuthService {
  readonly displayName = signal<string | null>(localStorage.getItem(NAME_KEY));

  constructor(
    private http: HttpClient,
    private router: Router,
  ) {}

  login(credentials: LoginRequest): Observable<ApiEnvelope<LoginResponseData>> {
    return this.http
      .post<ApiEnvelope<LoginResponseData>>(environment.apiUrl + 'platform-admin/login', credentials)
      .pipe(
        tap((res) => {
          if (res.success && res.data?.token) {
            this.persistSession(res.data);
          }
        }),
      );
  }

  logout(): void {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(NAME_KEY);
    this.displayName.set(null);
    this.router.navigateByUrl('/login');
  }

  get token(): string | null {
    return localStorage.getItem(TOKEN_KEY);
  }

  get isLoggedIn(): boolean {
    return !!this.token;
  }

  private persistSession(data: LoginResponseData): void {
    localStorage.setItem(TOKEN_KEY, data.token);
    localStorage.setItem(NAME_KEY, data.name ?? data.email ?? '');
    this.displayName.set(data.name ?? data.email ?? null);
  }
}
