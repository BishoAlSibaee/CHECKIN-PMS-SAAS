import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { BehaviorSubject, Observable, Subject, tap } from 'rxjs';
import { FullLoginResponse } from '../../models/Auth/full-login-response';
import { Login } from '../../models/Auth/login';
import { LoginResponse } from '../../models/Auth/login-response';
import { environment } from '../../../environments/environment';
import { decodeSessionString, encodeSessionString } from '../../utils/session-base64';

@Injectable({
  providedIn: 'root',
})
export class DataService {
  user$ = new BehaviorSubject<LoginResponse | null>(null);
  userToken$: any;

  // Global toast notifications
  private toastSubject = new Subject<{ message: string, type: 'success' | 'error' | 'warning' }>();
  toast$ = this.toastSubject.asObservable();

  constructor(private httpClient: HttpClient) {
    this.restoreUserFromStorage();
  }

  private restoreUserFromStorage(): void {
    const storedUser = localStorage.getItem('user');
    if (!storedUser) {
      return;
    }
    try {
      const userData = this.normalizeSessionPayload(JSON.parse(decodeSessionString(storedUser)) as Record<string, unknown>);
      if (userData['token']) {
        this.userToken$ = userData['token'];
        this.user$.next(userData as unknown as LoginResponse);
      }
    } catch (e) {
      console.error('Error parsing stored user data', e);
      localStorage.removeItem('user');
    }
  }

  /** Merge fresh roles/permissions from API into stored session. */
  applySessionPatch(patch: Partial<LoginResponse>): void {
    const current = this.user$.value ?? this.readStoredUser();
    if (!current?.token) {
      return;
    }
    const merged = this.normalizeSessionPayload({ ...current, ...patch, token: current.token });
    localStorage.setItem('user', encodeSessionString(JSON.stringify(merged)));
    // userToken$ must be set before user$.next() — user$ is a BehaviorSubject,
    // so .next() synchronously runs subscribers inline (e.g. notification
    // services that fire an HTTP request the instant they see a token), and
    // that request's interceptor reads userToken$ right away.
    this.userToken$ = merged['token'];
    this.user$.next(merged as unknown as LoginResponse);
  }

  /** Save login payload with normalized permission lists. */
  persistLoginSession(data: Record<string, unknown>): void {
    const normalized = this.normalizeSessionPayload(data);
    localStorage.setItem('user', encodeSessionString(JSON.stringify(normalized)));
    this.userToken$ = normalized['token'];
    this.user$.next(normalized as unknown as LoginResponse);
  }

  normalizeSessionPayload(data: Record<string, unknown>): Record<string, unknown> {
    return {
      ...data,
      permissions: this.normalizeStringList(data['permissions']),
      roles: this.normalizeStringList(data['roles']),
    };
  }

  private normalizeStringList(value: unknown): string[] {
    if (Array.isArray(value)) {
      return value
        .map((v) => (typeof v === 'string' ? v : (v as { name?: string })?.name))
        .filter((v): v is string => typeof v === 'string' && v.length > 0);
    }
    if (value && typeof value === 'object') {
      return Object.values(value as Record<string, unknown>)
        .map((v) => (typeof v === 'string' ? v : (v as { name?: string })?.name))
        .filter((v): v is string => typeof v === 'string' && v.length > 0);
    }
    return [];
  }

  /** Refresh roles/permissions from backend (fixes stale localStorage after seed changes). */
  syncCurrentUser(): Observable<any> {
    return this.httpClient.get<any>(this.Url + 'users/me', {
      headers: this.getHeaders(),
    }).pipe(
      tap((res) => {
        if (res?.success && res?.data) {
          this.applySessionPatch(res.data);
        }
      })
    );
  }

  getSessionUser(): LoginResponse | null {
    return this.readStoredUser();
  }

  private readStoredUser(): LoginResponse | null {
    try {
      const raw = localStorage.getItem('user');
      if (!raw) {
        return null;
      }
      return this.normalizeSessionPayload(JSON.parse(decodeSessionString(raw)) as Record<string, unknown>) as unknown as LoginResponse;
    } catch {
      return null;
    }
  }

  Url: string = environment.apiUrl;

  headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };

  getHeaders() {
    const token = this.userToken$;
    return {
      ...this.headers,
      Authorization: `Bearer ${token}`,
    };
  }

  // Auth
  login(data: Login) {
    return this.httpClient.post<FullLoginResponse>(this.Url + 'users/login', data, {
      headers: this.headers,
    });
  }

  /** Public health check — no auth (used when login returns 500). */
  fetchSetupCheck() {
    return this.httpClient.get<any>(this.Url + 'users/setup-check', {
      headers: { Accept: 'application/json' },
    });
  }

  showToast(message: string, type: 'success' | 'error' | 'warning' = 'success') {
    this.toastSubject.next({ message, type });
  }

  logout(): void {
    localStorage.removeItem('user');
    localStorage.removeItem('currentHotelId');
    this.userToken$ = null;
    this.user$.next(null);
  }
}
