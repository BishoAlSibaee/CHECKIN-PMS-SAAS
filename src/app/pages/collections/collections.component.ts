import { Component, OnDestroy, OnInit } from '@angular/core';
import { Router, ActivatedRoute, NavigationEnd } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { filter } from 'rxjs/operators';
import { Subscription } from 'rxjs';
import { HotelService, CollectionItem, CollectionsSummary } from '../../services/hotel.service';
import { DataService } from '../../services/Api/data.service';
import { AuthPermissionService } from '../../services/auth-permission.service';
import { ConfirmDialogService } from '../../services/confirm-dialog.service';
import { RealtimeService } from '../../services/realtime.service';

export type CollectionTab = 'all' | 'checkout_today' | 'in_house' | 'overdue' | 'upcoming';

@Component({
  selector: 'app-collections',
  templateUrl: './collections.component.html',
  styleUrls: ['./collections.component.css'],
  standalone: false,
})
export class CollectionsComponent implements OnInit, OnDestroy {
  isLoading = true;
  isLoadingSummary = true;
  isCollectingAll = false;
  collectAllMessage = '';
  listError = '';
  summary: CollectionsSummary | null = null;
  items: CollectionItem[] = [];
  activeTab: CollectionTab = 'checkout_today';
  search = '';
  currentPage = 1;
  lastPage = 1;
  totalItems = 0;
  pageSize = 15;

  private searchTimer: ReturnType<typeof setTimeout> | null = null;
  private routerSub?: Subscription;
  private realtimeOff?: () => void;

  readonly tabs: { id: CollectionTab; labelKey: string }[] = [
    { id: 'checkout_today', labelKey: 'COLLECTIONS.TAB_CHECKOUT_TODAY' },
    { id: 'in_house', labelKey: 'COLLECTIONS.TAB_IN_HOUSE' },
    { id: 'overdue', labelKey: 'COLLECTIONS.TAB_OVERDUE' },
    { id: 'upcoming', labelKey: 'COLLECTIONS.TAB_UPCOMING' },
    { id: 'all', labelKey: 'COLLECTIONS.TAB_ALL' },
  ];

  constructor(
    private hotelService: HotelService,
    private dataService: DataService,
    private router: Router,
    private route: ActivatedRoute,
    public translate: TranslateService,
    public authPerm: AuthPermissionService,
    private confirmDialog: ConfirmDialogService,
    private realtime: RealtimeService
  ) {}

  get canCollect(): boolean {
    return this.authPerm.has('update reservations');
  }

  ngOnInit(): void {
    this.route.queryParams.subscribe((params) => {
      const tab = params['tab'];
      if (tab && this.tabs.some((t) => t.id === tab)) {
        this.activeTab = tab as CollectionTab;
      }
    });
    this.loadSummary();
    this.loadList();
    this.routerSub = this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd)
    ).subscribe((event) => {
      if (event.urlAfterRedirects.includes('/booking/collections')) {
        this.loadSummary();
        this.loadList();
      }
    });
    this.realtimeOff = this.realtime.onScope('collections', () => {
      this.loadSummary(true);
      this.loadList(true);
    });
  }

  ngOnDestroy(): void {
    this.routerSub?.unsubscribe();
    if (this.searchTimer) {
      clearTimeout(this.searchTimer);
    }
    this.realtimeOff?.();
  }

  setTab(tab: CollectionTab): void {
    if (this.activeTab === tab) {
      return;
    }
    this.activeTab = tab;
    this.currentPage = 1;
    this.loadList();
  }

  refresh(): void {
    this.loadSummary();
    this.loadList();
  }

  get isBusy(): boolean {
    return this.isLoading || this.isLoadingSummary || this.isCollectingAll;
  }

  get hasSearch(): boolean {
    return this.search.trim().length > 0;
  }

  onSearchChange(value: string): void {
    this.search = value;
    if (this.searchTimer) {
      clearTimeout(this.searchTimer);
    }
    this.searchTimer = setTimeout(() => {
      this.currentPage = 1;
      this.loadList();
    }, 350);
  }

  clearSearch(): void {
    if (!this.hasSearch) {
      return;
    }
    this.onSearchChange('');
  }

  dismissCollectMessage(): void {
    this.collectAllMessage = '';
  }

  tabCount(tab: CollectionTab): number | null {
    if (!this.summary) {
      return null;
    }
    switch (tab) {
      case 'checkout_today':
        return this.summary.checkout_today_count;
      case 'in_house':
        return this.summary.in_house_count;
      case 'overdue':
        return this.summary.overdue_count;
      case 'all':
        return this.summary.count;
      default:
        return null;
    }
  }

  goToPage(page: number): void {
    if (page < 1 || page > this.lastPage || page === this.currentPage) {
      return;
    }
    this.currentPage = page;
    this.loadList();
  }

  openReservation(item: CollectionItem): void {
    this.router.navigate(['/booking/reservations', item.reservation_id]);
  }

  collectPayment(item: CollectionItem, event?: Event): void {
    event?.stopPropagation();
    this.router.navigate(['/booking/reservations', item.reservation_id], {
      queryParams: { collect: '1' },
    });
  }

  async collectAllOutstanding(): Promise<void> {
    if (!this.canCollect || this.isCollectingAll || !(this.summary?.count ?? 0)) {
      return;
    }

    const ok = await this.confirmDialog.confirm(
      this.translate.instant('COLLECTIONS.COLLECT_ALL_CONFIRM', {
        count: this.summary!.count,
        total: this.formatMoney(this.summary!.total_balance),
      }),
      {
        title: this.translate.instant('COLLECTIONS.COLLECT_ALL_CONFIRM_TITLE'),
        confirmText: this.translate.instant('COLLECTIONS.COLLECT_ALL'),
        cancelText: this.translate.instant('COMMON.CANCEL'),
        variant: 'primary',
      }
    );
    if (!ok) {
      return;
    }

    this.isCollectingAll = true;
    this.collectAllMessage = '';
    this.hotelService.collectAllOutstandingBalances().subscribe({
      next: (res) => {
        this.isCollectingAll = false;
        if (!res?.success) {
          this.collectAllMessage = res?.message || this.translate.instant('COLLECTIONS.COLLECT_ALL_FAILED');
          return;
        }
        const count = Number(res?.data?.collected_count ?? 0);
        const total = Number(res?.data?.collected_total ?? 0);
        const remaining = Number(res?.data?.remaining_count ?? 0);
        this.collectAllMessage = this.translate.instant('COLLECTIONS.COLLECT_ALL_OK', {
          count,
          total: this.formatMoney(total)
        });
        if (remaining > 0) {
          this.collectAllMessage += ' ' + this.translate.instant('COLLECTIONS.COLLECT_ALL_REMAINING', {
            count: remaining
          });
        }
        this.dataService.showToast(this.collectAllMessage, 'success');
        this.loadSummary();
        this.loadList();
      },
      error: () => {
        this.isCollectingAll = false;
        this.collectAllMessage = this.translate.instant('COLLECTIONS.COLLECT_ALL_FAILED');
      }
    });
  }

  urgencyClass(urgency: string): string {
    switch (urgency) {
      case 'checkout_today': return 'urgency-checkout';
      case 'overdue': return 'urgency-overdue';
      case 'in_house': return 'urgency-in-house';
      case 'upcoming': return 'urgency-upcoming';
      default: return 'urgency-open';
    }
  }

  urgencyLabel(urgency: string): string {
    const key = `COLLECTIONS.URGENCY_${urgency.toUpperCase()}`;
    const translated = this.translate.instant(key);
    return translated !== key ? translated : urgency;
  }

  formatMoney(value: number): string {
    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  private loadSummary(soft = false): void {
    if (!soft) {
      this.isLoadingSummary = true;
    }
    this.hotelService.fetchCollectionsSummary().subscribe({
      next: (res) => {
        this.summary = res?.success ? res.data : null;
        this.isLoadingSummary = false;
      },
      error: () => {
        this.summary = null;
        this.isLoadingSummary = false;
      },
    });
  }

  private loadList(soft = false): void {
    if (!soft) {
      this.isLoading = true;
    }
    this.listError = '';
    this.hotelService.fetchCollections({
      tab: this.activeTab,
      search: this.search.trim() || undefined,
      page: this.currentPage,
      perPage: this.pageSize,
      sort: 'balance_due',
    }).subscribe({
      next: (res) => {
        if (res?.success && res?.data) {
          this.items = res.data.items ?? [];
          this.totalItems = res.data.total ?? 0;
          this.currentPage = res.data.current_page ?? 1;
          this.lastPage = res.data.last_page ?? 1;
        } else {
          this.items = [];
          this.totalItems = 0;
          this.lastPage = 1;
        }
        this.isLoading = false;
      },
      error: () => {
        this.items = [];
        this.listError = this.translate.instant('COLLECTIONS.LOAD_FAILED');
        this.isLoading = false;
      },
    });
  }
}
