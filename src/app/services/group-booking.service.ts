import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { DataService } from './Api/data.service';

@Injectable({ providedIn: 'root' })
export class GroupBookingService {
  constructor(private http: HttpClient, private dataService: DataService) {}

  list(page: number = 1, status?: number): Observable<any> {
    let url = `${this.dataService.Url}users/group-bookings?page=${page}`;
    if (status !== undefined) {
      url += `&status=${status}`;
    }
    return this.http.get<any>(url, { headers: this.dataService.getHeaders() });
  }

  get(id: number): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/group-bookings/${id}`, {
      headers: this.dataService.getHeaders()
    });
  }

  financials(id: number): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/group-bookings/${id}/financials`, {
      headers: this.dataService.getHeaders()
    });
  }

  create(payload: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/group-bookings`, payload, {
      headers: this.dataService.getHeaders()
    });
  }

  cancel(id: number, reason?: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/group-bookings/${id}/cancel`, { reason }, {
      headers: this.dataService.getHeaders()
    });
  }
}
