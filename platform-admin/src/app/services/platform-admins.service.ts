import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { ApiEnvelope } from '../models/auth.model';
import { CreatePlatformAdminRequest, PlatformAdminAccount } from '../models/platform-admin.model';

@Injectable({ providedIn: 'root' })
export class PlatformAdminsService {
  constructor(private http: HttpClient) {}

  list(): Observable<ApiEnvelope<PlatformAdminAccount[]>> {
    return this.http.get<ApiEnvelope<PlatformAdminAccount[]>>(environment.apiUrl + 'platform-admin/admins');
  }

  create(payload: CreatePlatformAdminRequest): Observable<ApiEnvelope<PlatformAdminAccount>> {
    return this.http.post<ApiEnvelope<PlatformAdminAccount>>(environment.apiUrl + 'platform-admin/admins', payload);
  }
}
