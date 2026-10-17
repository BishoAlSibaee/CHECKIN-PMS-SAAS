import { Component, HostListener, OnInit } from '@angular/core';
import { forkJoin, from, Observable } from 'rxjs';
import { TranslateService } from '@ngx-translate/core';
import { PeakConfig, PeakDay, PeakMonth } from '../../models/peak-config.interface';
import { DataService } from '../../services/Api/data.service';
import { HotelService } from '../../services/hotel.service';
import { ConfirmDialogService } from '../../services/confirm-dialog.service';
import { ComponentCanDeactivate } from '../../guards/pending-changes.guard';

@Component({
    selector: 'app-peak-periods',
    templateUrl: './peak-periods.component.html',
    styleUrls: ['./peak-periods.component.css'],
    standalone: false
})
export class PeakPeriodsComponent implements OnInit, ComponentCanDeactivate {
  peakConfig!: PeakConfig;
  originalConfig!: string;
  isLoading = true;
  isSaving = false;
  errorMessage = '';

  private readonly weekendDays = new Set(['Friday', 'Saturday']);

  constructor(
    private hotelService: HotelService,
    private dataService: DataService,
    private translate: TranslateService,
    private confirmDialog: ConfirmDialogService
  ) {}

  ngOnInit(): void {
    this.loadConfig();
  }

  @HostListener('window:beforeunload', ['$event'])
  onBeforeUnload(event: BeforeUnloadEvent): void {
    if (!this.hasChanges) {
      return;
    }
    event.preventDefault();
    event.returnValue = true;
  }

  loadConfig(): void {
    this.isLoading = true;
    this.errorMessage = '';
    this.hotelService.fetchPeakConfig().subscribe({
      next: (config) => {
        this.peakConfig = JSON.parse(JSON.stringify(config));
        this.originalConfig = JSON.stringify(this.peakConfig);
        this.isLoading = false;
      },
      error: () => {
        this.errorMessage = this.translate.instant('PEAK_PERIODS.ERR_LOAD');
        this.isLoading = false;
      }
    });
  }

  refresh(): void {
    if (this.hasChanges) {
      void this.confirmLeave().then((ok) => {
        if (ok) {
          this.loadConfig();
        }
      });
      return;
    }
    this.loadConfig();
  }

  get hasChanges(): boolean {
    if (!this.peakConfig || this.originalConfig == null) {
      return false;
    }
    return this.originalConfig !== JSON.stringify(this.peakConfig);
  }

  get enabledDaysCount(): number {
    return this.peakConfig?.peakDays?.filter((d) => d.enabled).length ?? 0;
  }

  get enabledMonthsCount(): number {
    return this.peakConfig?.peakMonths?.filter((m) => m.enabled).length ?? 0;
  }

  canDeactivate(): boolean | Observable<boolean> {
    if (!this.hasChanges) {
      return true;
    }
    return from(this.confirmLeave());
  }

  private confirmLeave(): Promise<boolean> {
    return this.confirmDialog.confirm(
      this.translate.instant('PEAK_PERIODS.LEAVE_MESSAGE'),
      {
        title: this.translate.instant('PEAK_PERIODS.LEAVE_TITLE'),
        confirmText: this.translate.instant('PEAK_PERIODS.LEAVE_CONFIRM'),
        cancelText: this.translate.instant('PEAK_PERIODS.LEAVE_CANCEL'),
        variant: 'danger',
      }
    );
  }

  toggleDay(day: PeakDay): void {
    day.enabled = !day.enabled;
  }

  toggleMonth(month: PeakMonth): void {
    month.enabled = !month.enabled;
  }

  enableWeekend(): void {
    if (!this.peakConfig) {
      return;
    }
    this.peakConfig.peakDays.forEach((day) => {
      day.enabled = this.weekendDays.has(day.day);
    });
  }

  clearDays(): void {
    this.peakConfig?.peakDays.forEach((day) => {
      day.enabled = false;
    });
  }

  selectAllMonths(): void {
    this.peakConfig?.peakMonths.forEach((month) => {
      month.enabled = true;
    });
  }

  clearMonths(): void {
    this.peakConfig?.peakMonths.forEach((month) => {
      month.enabled = false;
    });
  }

  saveConfig(): void {
    if (!this.peakConfig || !this.hasChanges || this.isSaving) {
      return;
    }

    this.isSaving = true;
    this.errorMessage = '';

    const days = this.peakConfig.peakDays.map((d) => ({ id: d.id, check: d.enabled ? 1 : 0 }));
    const months = this.peakConfig.peakMonths.map((m) => ({ id: m.id, check: m.enabled ? 1 : 0 }));

    forkJoin({
      days: this.hotelService.updatePeakDays(days),
      months: this.hotelService.updatePeakMonths(months)
    }).subscribe({
      next: () => {
        this.originalConfig = JSON.stringify(this.peakConfig);
        this.isSaving = false;
        this.dataService.showToast(
          this.translate.instant('PEAK_PERIODS.SAVE_SUCCESS'),
          'success'
        );
      },
      error: () => {
        this.isSaving = false;
        this.errorMessage = this.translate.instant('PEAK_PERIODS.ERR_SAVE');
        this.dataService.showToast(
          this.translate.instant('PEAK_PERIODS.ERR_SAVE'),
          'error'
        );
      }
    });
  }
}
