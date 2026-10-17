import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { ApiEnvelope } from '../models/auth.model';
import {
  AddHotelRequest,
  Hotel,
  OnboardTenantRequest,
  OnboardTenantResponse,
  RenewSubscriptionRequest,
  Tenant,
  UpdateTenantRequest,
} from '../models/tenant.model';

@Injectable({ providedIn: 'root' })
export class TenantsService {
  constructor(private http: HttpClient) {}

  list(): Observable<ApiEnvelope<Tenant[]>> {
    return this.http.get<ApiEnvelope<Tenant[]>>(environment.apiUrl + 'platform-admin/tenants');
  }

  get(tenantId: number): Observable<ApiEnvelope<Tenant>> {
    return this.http.get<ApiEnvelope<Tenant>>(environment.apiUrl + 'platform-admin/tenants/' + tenantId);
  }

  create(payload: OnboardTenantRequest): Observable<ApiEnvelope<OnboardTenantResponse>> {
    return this.http.post<ApiEnvelope<OnboardTenantResponse>>(environment.apiUrl + 'platform-admin/tenants', payload);
  }

  update(tenantId: number, payload: UpdateTenantRequest): Observable<ApiEnvelope<Tenant>> {
    return this.http.put<ApiEnvelope<Tenant>>(environment.apiUrl + 'platform-admin/tenants/' + tenantId, payload);
  }

  renew(tenantId: number, payload: RenewSubscriptionRequest): Observable<ApiEnvelope<Tenant>> {
    return this.http.post<ApiEnvelope<Tenant>>(
      environment.apiUrl + 'platform-admin/tenants/' + tenantId + '/renew',
      payload,
    );
  }

  addHotel(tenantId: number, payload: AddHotelRequest): Observable<ApiEnvelope<Hotel>> {
    return this.http.post<ApiEnvelope<Hotel>>(
      environment.apiUrl + 'platform-admin/tenants/' + tenantId + '/hotels',
      payload,
    );
  }
}
