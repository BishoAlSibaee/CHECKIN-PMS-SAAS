import { Component, OnInit, ChangeDetectorRef, OnDestroy } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { NavigationEnd, Router } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from './services/Api/data.service';
import { RoleService } from './services/role.service';
import { RealtimeService } from './services/realtime.service';
import { LanguageService } from './services/language.service';
import { HotelContextService } from './services/hotel-context.service';
import { HotelOption } from './models/Auth/login-response';
import { NotificationCenterService } from './services/notification-center.service';
import { AppNotification } from './models/notification.interface';
import { filter, Subscription } from 'rxjs';

interface TopbarPageRoute {
  prefix: string;
  titleKey: string;
  sectionKey: string;
}

@Component({
    selector: 'app-root',
    templateUrl: './app.component.html',
    styleUrls: ['./app.component.css'],
    standalone: false
})
export class AppComponent implements OnInit, OnDestroy {
  title = 'Hotel Management System';
  user: any;
  langEpoch = 0;
  pageTitleKey = 'SIDEBAR.DASHBOARD';
  pageSectionKey = 'SIDEBAR.SECTION_OPERATIONS';
  todayLabel = '';
  topbarElevated = false;
  private langSub?: Subscription;
  private routeSub?: Subscription;

  /** Longest prefix wins — keep create/detail-friendly paths before list parents. */
  private readonly pageRoutes: TopbarPageRoute[] = [
    { prefix: '/booking/reservations/create', titleKey: 'RESERVATIONS.NEW_BOOKING', sectionKey: 'SIDEBAR.SECTION_OPERATIONS' },
    { prefix: '/booking/reservations', titleKey: 'SIDEBAR.RESERVATIONS', sectionKey: 'SIDEBAR.SECTION_OPERATIONS' },
    { prefix: '/booking/collections', titleKey: 'SIDEBAR.COLLECTIONS', sectionKey: 'SIDEBAR.SECTION_OPERATIONS' },
    { prefix: '/booking/peak-periods', titleKey: 'SIDEBAR.PEAK_PERIODS', sectionKey: 'SIDEBAR.SECTION_COMMERCIAL' },
    { prefix: '/dashboard/room-board', titleKey: 'SIDEBAR.ROOM_BOARD', sectionKey: 'SIDEBAR.SECTION_OPERATIONS' },
    { prefix: '/dashboard', titleKey: 'SIDEBAR.DASHBOARD', sectionKey: 'SIDEBAR.SECTION_OPERATIONS' },
    { prefix: '/admin/property', titleKey: 'SIDEBAR.PROPERTY', sectionKey: 'SIDEBAR.SECTION_OPERATIONS' },
    { prefix: '/admin/pricing', titleKey: 'SIDEBAR.PRICING', sectionKey: 'SIDEBAR.SECTION_COMMERCIAL' },
    { prefix: '/admin/users', titleKey: 'SIDEBAR.USERS', sectionKey: 'SIDEBAR.SECTION_ADMIN' },
    { prefix: '/admin/clients', titleKey: 'SIDEBAR.CLIENTS', sectionKey: 'SIDEBAR.SECTION_ADMIN' },
    { prefix: '/admin/guests', titleKey: 'SIDEBAR.GUESTS', sectionKey: 'SIDEBAR.SECTION_ADMIN' },
    { prefix: '/admin/departments', titleKey: 'SIDEBAR.DEPARTMENTS', sectionKey: 'SIDEBAR.SECTION_ADMIN' },
    { prefix: '/admin/job-titles', titleKey: 'SIDEBAR.JOB_TITLES', sectionKey: 'SIDEBAR.SECTION_ADMIN' },
    { prefix: '/admin/penalties', titleKey: 'SIDEBAR.PENALTIES', sectionKey: 'SIDEBAR.SECTION_ADMIN' },
    { prefix: '/admin/refund-policies', titleKey: 'SIDEBAR.REFUND_POLICIES', sectionKey: 'SIDEBAR.SECTION_ADMIN' },
    { prefix: '/admin/discounts', titleKey: 'SIDEBAR.DISCOUNTS', sectionKey: 'SIDEBAR.SECTION_ADMIN' },
    { prefix: '/financials', titleKey: 'SIDEBAR.FINANCIALS', sectionKey: 'SIDEBAR.SECTION_COMMERCIAL' },
    { prefix: '/reports', titleKey: 'SIDEBAR.REPORTS', sectionKey: 'SIDEBAR.SECTION_COMMERCIAL' },
  ];

  showChangePasswordModal = false;
  passwordForm!: FormGroup;
  isChangingPassword = false;

  showToast = false;
  toastMessage = '';
  toastType: 'success' | 'error' | 'warning' = 'success';

  notifications: AppNotification[] = [];
  unreadNotificationCount = 0;
  private notificationsSub?: Subscription;
  private unreadCountSub?: Subscription;

  constructor(
    public router: Router,
    private translate: TranslateService,
    private dataService: DataService,
    private roleService: RoleService,
    private realtime: RealtimeService,
    private language: LanguageService,
    public hotelContext: HotelContextService,
    private fb: FormBuilder,
    private cdr: ChangeDetectorRef,
    private notificationCenter: NotificationCenterService,
  ) {}

  ngOnInit() {
    this.language.init();
    this.refreshTodayLabel();
    this.resolvePageContext(this.router.url);

    this.langSub = this.language.changes$.subscribe(() => {
      this.langEpoch++;
      this.refreshTodayLabel();
      this.cdr.markForCheck();
    });

    this.routeSub = this.router.events
      .pipe(filter((event): event is NavigationEnd => event instanceof NavigationEnd))
      .subscribe((event) => {
        this.resolvePageContext(event.urlAfterRedirects || event.url);
        this.topbarElevated = false;
        this.cdr.markForCheck();
      });

    this.dataService.user$.subscribe(user => {
      this.user = user;
      if (user?.token) {
        this.realtime.reconnect();
      } else {
        this.realtime.disconnect();
      }
      this.cdr.markForCheck();
    });

    if (this.dataService.userToken$) {
      this.dataService.syncCurrentUser().subscribe({
        next: () => {
          this.realtime.reconnect();
        },
        error: () => {
          // Keep existing session if refresh fails (offline server).
        },
      });
    }

    // Listen for global toast notifications
    this.dataService.toast$.subscribe(toast => {
      this.showToastMessage(toast.message, toast.type);
    });

    this.notificationsSub = this.notificationCenter.notifications$.subscribe((items) => {
      this.notifications = items;
      this.cdr.markForCheck();
    });
    this.unreadCountSub = this.notificationCenter.unreadCount$.subscribe((count) => {
      this.unreadNotificationCount = count;
      this.cdr.markForCheck();
    });

    this.initPasswordForm();
  }

  ngOnDestroy(): void {
    this.langSub?.unsubscribe();
    this.routeSub?.unsubscribe();
    this.notificationsSub?.unsubscribe();
    this.unreadCountSub?.unsubscribe();
  }

  notificationTitle(notification: AppNotification): string {
    return this.translate.instant(notification.title_key, notification.params ?? undefined);
  }

  notificationIcon(type: string): string {
    switch (type) {
      case 'airbnb_request': return 'bi-house-heart';
      case 'new_booking': return 'bi-calendar-plus';
      case 'pricing_changed': return 'bi-tag';
      case 'sync_error':
      case 'rate_error': return 'bi-exclamation-triangle';
      case 'sync_warning': return 'bi-exclamation-circle';
      case 'booking_unmapped_room':
      case 'booking_unmapped_rate': return 'bi-signpost-split';
      case 'guest_message': return 'bi-chat-dots';
      case 'review':
      case 'updated_review': return 'bi-star';
      default: return 'bi-bell';
    }
  }

  onNotificationsToggle(open: boolean): void {
    if (open) {
      this.notificationCenter.markSeen();
    }
  }

  openNotification(notification: AppNotification): void {
    if (notification.link) {
      this.router.navigateByUrl(notification.link);
    }
  }

  get currentLang(): string {
    return this.language.current;
  }

  get userInitials(): string {
    const name = String(this.user?.name ?? '').trim();
    if (!name) {
      return 'U';
    }
    const parts = name.split(/\s+/).filter(Boolean);
    if (parts.length === 1) {
      return parts[0].slice(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }

  get userRoleLabel(): string {
    const role = String(this.user?.role ?? '').trim();
    if (role) {
      return role;
    }
    const roles = Array.isArray(this.user?.roles) ? this.user.roles.filter(Boolean) : [];
    if (roles.length) {
      return String(roles[0]);
    }
    return this.translate.instant('TOPBAR.GUEST_USER');
  }

  onMainScroll(event: Event): void {
    const target = event.target as HTMLElement | null;
    this.topbarElevated = !!target && target.scrollTop > 4;
  }

  switchLanguage(): void {
    this.language.toggle().subscribe();
  }

  switchHotel(hotelId: number): void {
    this.hotelContext.switch(hotelId);
  }

  hotelName(hotel: HotelOption): string {
    return this.currentLang === 'ar' ? hotel.name_ar : hotel.name_en;
  }

  get currentHotelName(): string {
    const hotel = this.hotelContext.availableHotels.find((h) => h.id === this.hotelContext.current);
    return hotel ? this.hotelName(hotel) : '';
  }

  private resolvePageContext(url: string): void {
    const path = (url || '').split('?')[0].split('#')[0] || '/dashboard';
    const match = this.pageRoutes.find((route) => path === route.prefix || path.startsWith(route.prefix + '/'));
    if (match) {
      this.pageTitleKey = match.titleKey;
      this.pageSectionKey = match.sectionKey;
      return;
    }
    this.pageTitleKey = 'SIDEBAR.DASHBOARD';
    this.pageSectionKey = 'SIDEBAR.SECTION_OPERATIONS';
  }

  private refreshTodayLabel(): void {
    const locale = this.language.current === 'ar' ? 'ar-SA' : 'en-GB';
    try {
      this.todayLabel = new Intl.DateTimeFormat(locale, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
      }).format(new Date());
    } catch {
      this.todayLabel = new Date().toLocaleDateString();
    }
  }

  private initPasswordForm(): void {
    this.passwordForm = this.fb.group({
      old_password: ['', [Validators.required]],
      new_password: ['', [Validators.required, Validators.minLength(6)]],
      new_password_confirmation: ['', [Validators.required]]
    }, {
      validator: this.passwordMatchValidator
    });
  }

  private passwordMatchValidator(g: FormGroup) {
    return g.get('new_password')?.value === g.get('new_password_confirmation')?.value
      ? null : { mismatch: true };
  }

  openChangePassword(): void {
    this.showChangePasswordModal = true;
    this.passwordForm.reset();
  }

  closeChangePassword(): void {
    this.showChangePasswordModal = false;
  }

  onChangePassword(): void {
    if (this.passwordForm.invalid) return;

    this.isChangingPassword = true;
    this.roleService.changePassword(this.passwordForm.value).subscribe({
      next: (res) => {
        this.isChangingPassword = false;
        if (res.success) {
          this.showToastMessage(this.translate.instant('AUTH.SUCCESS'), 'success');
          this.closeChangePassword();
        } else {
          this.showToastMessage(res.message || this.translate.instant('AUTH.FAILED'), 'error');
        }
      },
      error: () => {
        this.isChangingPassword = false;
        this.showToastMessage(this.translate.instant('AUTH.ERROR'), 'error');
      }
    });
  }

  private showToastMessage(message: string, type: 'success' | 'error' | 'warning'): void {
    this.toastMessage = message;
    this.toastType = type;
    this.showToast = true;
    setTimeout(() => {
      this.showToast = false;
    }, 3000);
  }

  get isLoginRoute(): boolean {
    return this.router.url.startsWith('/auth');
  }

  logout(): void {
    this.realtime.disconnect();
    this.dataService.logout();
    this.user = null;
    this.router.navigate(['/auth']);
  }
}
