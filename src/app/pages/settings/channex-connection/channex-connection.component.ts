import { Component, OnInit } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { ChannexConnectionStatus } from '../../../models/channex.interface';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';

@Component({
    selector: 'app-channex-connection',
    templateUrl: './channex-connection.component.html',
    styleUrls: ['./channex-connection.component.css'],
    standalone: false
})
export class ChannexConnectionComponent implements OnInit {
  status: ChannexConnectionStatus | null = null;
  isLoading = false;
  isSyncing = false;

  constructor(
    private hotelService: HotelService,
    private translate: TranslateService,
    private dataService: DataService,
  ) {}

  ngOnInit(): void {
    this.loadStatus();
  }

  loadStatus(): void {
    this.isLoading = true;
    this.hotelService.fetchChannexConnectionStatus().subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.status = res.data ?? null;
        } else {
          this.dataService.showToast(res.message || this.translate.instant('CHANNEX_CONNECTION.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoading = false;
        this.dataService.showToast(this.translate.instant('CHANNEX_CONNECTION.LOAD_ERROR'), 'error');
      }
    });
  }

  roomTypeName(mapping: { room_type?: { name_ar: string; name_en: string } | null }): string {
    if (mapping.room_type) {
      return this.translate.currentLang === 'ar' ? mapping.room_type.name_ar : mapping.room_type.name_en;
    }
    return '—';
  }

  syncNow(): void {
    if (this.isSyncing) return;
    this.isSyncing = true;
    this.hotelService.syncChannexNow().subscribe({
      next: (res) => {
        this.isSyncing = false;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('CHANNEX_CONNECTION.SYNC_SUCCESS'), 'success');
          this.loadStatus();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('CHANNEX_CONNECTION.SYNC_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSyncing = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('CHANNEX_CONNECTION.SYNC_ERROR'), 'error');
      }
    });
  }
}
