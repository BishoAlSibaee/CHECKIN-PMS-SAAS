import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { DataService } from './Api/data.service';

@Injectable({ providedIn: 'root' })
export class CompanyService {
  constructor(private http: HttpClient, private dataService: DataService) {}

  list(page: number = 1): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/companies?page=${page}`, {
      headers: this.dataService.getHeaders()
    });
  }

  get(id: number): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/companies/${id}`, {
      headers: this.dataService.getHeaders()
    });
  }

  create(payload: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/companies`, payload, {
      headers: this.dataService.getHeaders()
    });
  }

  update(id: number, payload: any): Observable<any> {
    return this.http.put<any>(`${this.dataService.Url}users/companies/${id}`, payload, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchLedger(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/companies-ledger`, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchStatement(companyId: number): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/companies-ledger/${companyId}`, {
      headers: this.dataService.getHeaders()
    });
  }

  settle(companyId: number, amount: number): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/companies-ledger/${companyId}/settle`, { amount }, {
      headers: this.dataService.getHeaders()
    });
  }

  createCompanyBilledReservation(payload: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/reservations/company-billed`, payload, {
      headers: this.dataService.getHeaders()
    });
  }
}
