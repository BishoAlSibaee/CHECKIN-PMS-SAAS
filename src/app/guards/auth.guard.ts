import { Injectable } from '@angular/core';
import { CanActivate, Router, UrlTree } from '@angular/router';
import { DataService } from '../services/Api/data.service';
import { decodeSessionString } from '../utils/session-base64';

@Injectable({ providedIn: 'root' })
export class AuthGuard implements CanActivate {
  constructor(
    private dataService: DataService,
    private router: Router
  ) {}

  canActivate(): boolean | UrlTree {
    if (this.dataService.userToken$) {
      return true;
    }

    const storedUser = localStorage.getItem('user');
    if (storedUser) {
      try {
        const userData = JSON.parse(decodeSessionString(storedUser));
        if (userData?.token) {
          this.dataService.userToken$ = userData.token;
          this.dataService.user$.next(userData);
          return true;
        }
      } catch {
        localStorage.removeItem('user');
      }
    }

    return this.router.createUrlTree(['/auth']);
  }
}
