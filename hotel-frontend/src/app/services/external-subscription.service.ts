import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { DataService } from './Api/data.service';

@Injectable({ providedIn: 'root' })
export class ExternalSubscriptionService {
  constructor(private http: HttpClient, private dataService: DataService) {}

  fetchTaqnyatBalance(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/external-subscriptions/taqnyat`, {
      headers: this.dataService.getHeaders()
    });
  }

  saveTaqnyatCredentials(token: string, sender: string | null): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/external-subscriptions/taqnyat/credentials`, { token, sender }, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchGuestClassifications(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/promo-campaigns/guest-classifications`, {
      headers: this.dataService.getHeaders()
    });
  }

  audiencePreview(params: { audience_type: 'manual' | 'classification'; guest_classification_id?: number | null; client_ids?: number[] }): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/promo-campaigns/audience-preview`, {
      headers: this.dataService.getHeaders(),
      params: params as any
    });
  }

  fetchCampaigns(page: number = 1): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/promo-campaigns?page=${page}`, {
      headers: this.dataService.getHeaders()
    });
  }

  createCampaign(payload: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/promo-campaigns`, payload, {
      headers: this.dataService.getHeaders()
    });
  }
}
