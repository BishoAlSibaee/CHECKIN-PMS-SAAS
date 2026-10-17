import { Injectable, Injector } from '@angular/core';
import { HttpRequest, HttpHandler, HttpEvent, HttpInterceptor, HttpErrorResponse } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { Router } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../services/Api/data.service';
import { HotelContextService } from '../services/hotel-context.service';

@Injectable()
export class AuthInterceptor implements HttpInterceptor {
  constructor(
    private dataService: DataService,
    private hotelContext: HotelContextService,
    private router: Router,
    private injector: Injector,
  ) {}

  intercept(
    request: HttpRequest<unknown>,
    next: HttpHandler
  ): Observable<HttpEvent<any>> {
    if (this.isTranslationAssetRequest(request.url)) {
      return next.handle(request);
    }

    const token: string = this.dataService.userToken$;
    const hotelId = this.hotelContext.current;
    let modifiedReq = request;

    if (token || hotelId) {
      modifiedReq = request.clone({
        setHeaders: {
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
          ...(hotelId ? { 'X-Hotel-Id': String(hotelId) } : {}),
        },
      });
    }

    return next.handle(modifiedReq).pipe(
      catchError((error: HttpErrorResponse) => {
        if (error.status === 401 || (error.error && error.error.message === 'Unauthenticated.')) {
          const errorMessage = this.translateMessage(
            'AUTH.SESSION_EXPIRED',
            'Session expired. Please login again.',
          );
          this.dataService.showToast(errorMessage, 'error');

          localStorage.removeItem('user');
          this.dataService.user$.next(null);
          this.dataService.userToken$ = null;

          this.router.navigate(['/auth']);
        } else if (error.status === 403) {
          let forbiddenMessage = this.translateMessage(
            'AUTH.FORBIDDEN',
            'You do not have permission to perform this action.',
          );
          const apiMessage = error.error?.message;
          if (typeof apiMessage === 'string' && apiMessage.trim()) {
            forbiddenMessage = apiMessage;
          }
          this.dataService.showToast(forbiddenMessage, 'error');
        } else if (error.status === 429) {
          const rateLimitMessage = this.translateMessage(
            'AUTH.TOO_MANY_REQUESTS',
            'Too many requests. Please wait a moment and try again.',
          );
          this.dataService.showToast(rateLimitMessage, 'warning');
        }
        return throwError(() => error);
      })
    );
  }

  private isTranslationAssetRequest(url: string): boolean {
    return url.includes('/assets/i18n/') || url.includes('assets/i18n/');
  }

  private translateMessage(key: string, fallback: string): string {
    try {
      const translate = this.injector.get(TranslateService);
      const message = translate.instant(key);
      return message === key ? fallback : message;
    } catch {
      return fallback;
    }
  }
}
