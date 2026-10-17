import { Component, OnInit } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { NightAuditRun } from '../../../models/night-audit.interface';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';

@Component({
    selector: 'app-night-audit',
    templateUrl: './night-audit.component.html',
    styleUrls: ['./night-audit.component.css'],
    standalone: false
})
export class NightAuditComponent implements OnInit {
  runs: NightAuditRun[] = [];
  isLoading = false;
  isRunning = false;
  expandedRunId: number | null = null;

  constructor(
    private hotelService: HotelService,
    private translate: TranslateService,
    private dataService: DataService,
  ) {}

  ngOnInit(): void {
    this.loadRuns();
  }

  loadRuns(): void {
    this.isLoading = true;
    this.hotelService.fetchNightAuditRuns().subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.runs = res.data?.runs ?? [];
        } else {
          this.dataService.showToast(res.message || this.translate.instant('NIGHT_AUDIT.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoading = false;
        this.dataService.showToast(this.translate.instant('NIGHT_AUDIT.LOAD_ERROR'), 'error');
      }
    });
  }

  runNow(): void {
    this.isRunning = true;
    this.hotelService.runNightAudit().subscribe({
      next: (res) => {
        this.isRunning = false;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('NIGHT_AUDIT.RUN_SUCCESS'), 'success');
          this.loadRuns();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('NIGHT_AUDIT.RUN_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isRunning = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('NIGHT_AUDIT.RUN_ERROR'), 'error');
      }
    });
  }

  toggleExpand(run: NightAuditRun): void {
    this.expandedRunId = this.expandedRunId === run.id ? null : run.id;
  }
}
