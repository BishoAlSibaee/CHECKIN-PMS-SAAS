import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { DataService } from './Api/data.service';

@Injectable({ providedIn: 'root' })
export class PosChargeService {
  constructor(private http: HttpClient, private dataService: DataService) {}

  lookupRoom(roomNumber: string): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/pos-charges/lookup-room`, {
      headers: this.dataService.getHeaders(),
      params: { room_number: roomNumber }
    });
  }

  create(payload: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/pos-charges`, payload, {
      headers: this.dataService.getHeaders()
    });
  }

  list(page: number = 1, filters: any = {}): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/pos-charges`, {
      headers: this.dataService.getHeaders(),
      params: { page, ...filters }
    });
  }

  void(id: number): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/pos-charges/${id}/void`, {}, {
      headers: this.dataService.getHeaders()
    });
  }

  listCategories(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/pos-categories`, {
      headers: this.dataService.getHeaders()
    });
  }

  listRevenueAccounts(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/pos-categories/accounts`, {
      headers: this.dataService.getHeaders()
    });
  }

  createCategory(payload: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/pos-categories`, payload, {
      headers: this.dataService.getHeaders()
    });
  }

  updateCategory(id: number, payload: any): Observable<any> {
    return this.http.put<any>(`${this.dataService.Url}users/pos-categories/${id}`, payload, {
      headers: this.dataService.getHeaders()
    });
  }
}
