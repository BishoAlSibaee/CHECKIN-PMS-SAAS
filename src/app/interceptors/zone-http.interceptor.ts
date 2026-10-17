import { Injectable, NgZone } from '@angular/core';
import { HttpEvent, HttpHandler, HttpInterceptor, HttpRequest } from '@angular/common/http';
import { Observable } from 'rxjs';

/**
 * Ensures HttpClient callbacks run inside Angular's zone so change detection
 * updates the UI after API responses (required after provideHttpClient migration).
 */
@Injectable()
export class ZoneHttpInterceptor implements HttpInterceptor {
  constructor(private readonly ngZone: NgZone) {}

  intercept(request: HttpRequest<unknown>, next: HttpHandler): Observable<HttpEvent<unknown>> {
    return new Observable((observer) => {
      const subscription = next.handle(request).subscribe({
        next: (event) => this.ngZone.run(() => observer.next(event)),
        error: (error) => this.ngZone.run(() => observer.error(error)),
        complete: () => this.ngZone.run(() => observer.complete()),
      });
      return () => subscription.unsubscribe();
    });
  }
}
