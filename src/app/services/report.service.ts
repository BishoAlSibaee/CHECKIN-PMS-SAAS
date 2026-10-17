import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { DataService } from './Api/data.service';

@Injectable({ providedIn: 'root' })
export class ReportService {
  constructor(
    private http: HttpClient,
    private dataService: DataService
  ) {}

  getCatalog(): Observable<any> {
    return this.http.get(`${this.dataService.Url}users/reports/catalog`, {
      headers: this.dataService.getHeaders()
    });
  }

  runReport(slug: string, params: Record<string, string | number | boolean | undefined>): Observable<any> {
    const clean: Record<string, string> = {};
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') {
        clean[k] = String(v);
      }
    });
    return this.http.get(`${this.dataService.Url}users/reports/${slug}`, {
      headers: this.dataService.getHeaders(),
      params: clean
    });
  }

  downloadReport(slug: string, params: Record<string, string>): Observable<Blob> {
    return this.http.get(`${this.dataService.Url}users/reports/${slug}/download`, {
      headers: this.dataService.getHeaders(),
      params,
      responseType: 'blob'
    });
  }

  requestEmailExport(
    slug: string,
    payload: {
      email: string;
      format?: 'excel' | 'pdf';
      start_date?: string;
      end_date?: string;
      compare_start_date?: string;
      compare_end_date?: string;
    }
  ): Observable<any> {
    return this.http.post(`${this.dataService.Url}users/reports/${slug}/email-request`, payload, {
      headers: this.dataService.getHeaders()
    });
  }

  getEmailExports(): Observable<any> {
    return this.http.get(`${this.dataService.Url}users/reports/exports`, {
      headers: this.dataService.getHeaders()
    });
  }

  getChartOfAccounts(startDate?: string, endDate?: string): Observable<any> {
    const params: Record<string, string> = {};
    if (startDate) {
      params['start_date'] = startDate;
    }
    if (endDate) {
      params['end_date'] = endDate;
    }
    return this.http.get(`${this.dataService.Url}users/accounting/chart-of-accounts`, {
      headers: this.dataService.getHeaders(),
      params
    });
  }

  getJournalEntries(params: Record<string, string>): Observable<any> {
    return this.http.get(`${this.dataService.Url}users/accounting/journal-entries`, {
      headers: this.dataService.getHeaders(),
      params
    });
  }

  createJournalEntry(payload: any): Observable<any> {
    return this.http.post(`${this.dataService.Url}users/accounting/journal-entries`, payload, {
      headers: this.dataService.getHeaders()
    });
  }

  closePeriod(payload: { year: number; month: number }): Observable<any> {
    return this.http.post(`${this.dataService.Url}users/accounting/periods/close`, payload, {
      headers: this.dataService.getHeaders()
    });
  }
}
