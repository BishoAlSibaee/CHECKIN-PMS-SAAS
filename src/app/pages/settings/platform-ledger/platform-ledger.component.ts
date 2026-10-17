import { Component, OnInit } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { PlatformLedgerRow, PlatformStatement } from '../../../models/channex.interface';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';

@Component({
    selector: 'app-platform-ledger',
    templateUrl: './platform-ledger.component.html',
    styleUrls: ['./platform-ledger.component.css'],
    standalone: false
})
export class PlatformLedgerComponent implements OnInit {
  platforms: PlatformLedgerRow[] = [];
  isLoading = false;

  selectedPlatform: PlatformLedgerRow | null = null;
  statement: PlatformStatement | null = null;
  isLoadingStatement = false;

  constructor(
    private hotelService: HotelService,
    private translate: TranslateService,
    private dataService: DataService,
  ) {}

  ngOnInit(): void {
    this.loadPlatforms();
  }

  loadPlatforms(): void {
    this.isLoading = true;
    this.hotelService.fetchPlatformLedger().subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.platforms = res.data?.platforms ?? [];
        } else {
          this.dataService.showToast(res.message || this.translate.instant('PLATFORM_LEDGER.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoading = false;
        this.dataService.showToast(this.translate.instant('PLATFORM_LEDGER.LOAD_ERROR'), 'error');
      }
    });
  }

  openStatement(platform: PlatformLedgerRow): void {
    this.selectedPlatform = platform;
    this.isLoadingStatement = true;
    this.statement = null;
    this.hotelService.fetchPlatformStatement(platform.reservation_source_id).subscribe({
      next: (res) => {
        this.isLoadingStatement = false;
        if (res.success) {
          this.statement = res.data ?? null;
        } else {
          this.dataService.showToast(res.message || this.translate.instant('PLATFORM_LEDGER.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoadingStatement = false;
        this.dataService.showToast(this.translate.instant('PLATFORM_LEDGER.LOAD_ERROR'), 'error');
      }
    });
  }

  closeStatement(): void {
    this.selectedPlatform = null;
    this.statement = null;
  }
}
