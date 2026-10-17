import { Component, OnInit } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { AirbnbRequestSettings, AirbnbDeclineReason, AirbnbFallbackAction, AirbnbRequestMode } from '../../../models/airbnb.interface';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';

@Component({
    selector: 'app-airbnb-settings',
    templateUrl: './airbnb-settings.component.html',
    styleUrls: ['./airbnb-settings.component.css'],
    standalone: false
})
export class AirbnbSettingsComponent implements OnInit {
  settings: AirbnbRequestSettings | null = null;
  isLoading = false;
  isSaving = false;

  readonly modes: AirbnbRequestMode[] = ['auto_accept', 'manual_review', 'auto_decline'];
  readonly declineReasons: AirbnbDeclineReason[] = [
    'dates_not_available',
    'not_a_good_fit',
    'waiting_for_better_reservation',
    'not_comfortable',
  ];

  constructor(
    private hotelService: HotelService,
    private translate: TranslateService,
    private dataService: DataService,
  ) {}

  ngOnInit(): void {
    this.loadSettings();
  }

  loadSettings(): void {
    this.isLoading = true;
    this.hotelService.fetchAirbnbSettings().subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.settings = res.data ?? null;
        } else {
          this.dataService.showToast(res.message || this.translate.instant('AIRBNB_SETTINGS.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoading = false;
        this.dataService.showToast(this.translate.instant('AIRBNB_SETTINGS.LOAD_ERROR'), 'error');
      }
    });
  }

  setMode(mode: AirbnbRequestMode): void {
    if (this.settings) {
      this.settings.mode = mode;
    }
  }

  setFallbackAction(action: AirbnbFallbackAction): void {
    if (this.settings) {
      this.settings.fallback_action = action;
    }
  }

  modeLabelKey(mode: AirbnbRequestMode): string {
    return 'AIRBNB_SETTINGS.MODE_' + mode.toUpperCase();
  }

  modeHintKey(mode: AirbnbRequestMode): string {
    return 'AIRBNB_SETTINGS.MODE_' + mode.toUpperCase() + '_HINT';
  }

  reasonLabelKey(reason: AirbnbDeclineReason): string {
    return 'AIRBNB_SETTINGS.REASON_' + reason.toUpperCase();
  }

  save(): void {
    if (!this.settings || this.isSaving) return;
    this.isSaving = true;

    this.hotelService.updateAirbnbSettings({
      mode: this.settings.mode,
      reminder_interval_minutes: this.settings.reminder_interval_minutes,
      fallback_action: this.settings.fallback_action,
      decline_reason: this.settings.decline_reason,
    }).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('AIRBNB_SETTINGS.SAVE_SUCCESS'), 'success');
          this.settings = res.data ?? this.settings;
        } else {
          this.dataService.showToast(res.message || this.translate.instant('AIRBNB_SETTINGS.SAVE_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('AIRBNB_SETTINGS.SAVE_ERROR'), 'error');
      }
    });
  }
}
