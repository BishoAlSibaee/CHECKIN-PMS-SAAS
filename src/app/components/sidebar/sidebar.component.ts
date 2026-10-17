import { Component, Renderer2, Inject, HostBinding, OnInit, OnDestroy, HostListener, ChangeDetectorRef } from '@angular/core';
import { DOCUMENT } from '@angular/core';
import { Router } from '@angular/router';
import { Subscription } from 'rxjs';
import { AuthPermissionService } from '../../services/auth-permission.service';
import { DataService } from '../../services/Api/data.service';
import { LanguageService } from '../../services/language.service';
import { AirbnbNotificationService } from '../../services/airbnb-notification.service';

export interface NavItem {
  path: string;
  icon: string;
  label: string;
  permissions?: string[];
  requireAllPermissions?: boolean;
  /** Match route exactly (avoids /dashboard highlighting on /dashboard/room-board). */
  exact?: boolean;
}

export interface NavSection {
  id: string;
  labelKey: string;
  items: NavItem[];
}

const navSections: NavSection[] = [
  {
    id: 'operations',
    labelKey: 'SIDEBAR.SECTION_OPERATIONS',
    items: [
      { path: '/dashboard', icon: 'bi-house-door', label: 'SIDEBAR.DASHBOARD', exact: true },
      { path: '/dashboard/room-board', icon: 'bi-grid-3x3-gap', label: 'SIDEBAR.ROOM_BOARD', permissions: ['view reservations'] },
      { path: '/admin/property', icon: 'bi-building', label: 'SIDEBAR.PROPERTY', permissions: ['view buildings', 'view floors', 'view suites', 'view rooms', 'view room types'] },
      { path: '/booking/reservations', icon: 'bi-calendar-range', label: 'SIDEBAR.RESERVATIONS', permissions: ['view reservations'] },
      { path: '/booking/group-bookings', icon: 'bi-people', label: 'SIDEBAR.GROUP_BOOKINGS', permissions: ['view group bookings'] },
      { path: '/booking/collections', icon: 'bi-cash-coin', label: 'SIDEBAR.COLLECTIONS', permissions: ['view payments'] },
      { path: '/admin/pos-quick-entry', icon: 'bi-shop', label: 'SIDEBAR.POS_QUICK_ENTRY', permissions: ['create pos charges'] },
      { path: '/admin/airbnb-requests', icon: 'bi-house-heart', label: 'SIDEBAR.AIRBNB_REQUESTS', permissions: ['manage airbnb requests'] },
    ],
  },
  {
    id: 'commercial',
    labelKey: 'SIDEBAR.SECTION_COMMERCIAL',
    items: [
      { path: '/admin/pricing', icon: 'bi-tag', label: 'SIDEBAR.PRICING', permissions: ['manage pricing plans', 'view room types'] },
      { path: '/booking/peak-periods', icon: 'bi-graph-up-arrow', label: 'SIDEBAR.PEAK_PERIODS', permissions: ['manage peak days'] },
      { path: '/financials', icon: 'bi-wallet2', label: 'SIDEBAR.FINANCIALS', permissions: ['view revenue', 'view earnings'], requireAllPermissions: true },
      { path: '/reports', icon: 'bi-file-earmark-bar-graph', label: 'SIDEBAR.REPORTS', permissions: ['view reports', 'view revenue', 'view earnings', 'view financial reports', 'view accounting reports'] },
      { path: '/admin/night-audit', icon: 'bi-moon-stars', label: 'SIDEBAR.NIGHT_AUDIT', permissions: ['manage night audit'] },
      { path: '/admin/ota-allocations', icon: 'bi-globe2', label: 'SIDEBAR.OTA_ALLOCATIONS', permissions: ['manage ota allocation'] },
      { path: '/admin/platform-ledger', icon: 'bi-diagram-3', label: 'SIDEBAR.PLATFORM_LEDGER', permissions: ['view platform ledger'] },
      { path: '/admin/companies', icon: 'bi-building', label: 'SIDEBAR.COMPANIES', permissions: ['view companies'] },
      { path: '/admin/companies-ledger', icon: 'bi-journal-text', label: 'SIDEBAR.COMPANY_LEDGER', permissions: ['view company ledger'] },
      { path: '/admin/pos-charges', icon: 'bi-receipt', label: 'SIDEBAR.POS_CHARGES', permissions: ['view pos charges'] },
      { path: '/admin/pos-categories', icon: 'bi-tags', label: 'SIDEBAR.POS_CATEGORIES', permissions: ['view pos categories'] },
      { path: '/admin/external-subscriptions', icon: 'bi-plug', label: 'SIDEBAR.EXTERNAL_SUBSCRIPTIONS', permissions: ['manage external subscriptions'] },
      { path: '/admin/channex-connection', icon: 'bi-plug', label: 'SIDEBAR.CHANNEX_CONNECTION', permissions: ['manage ota allocation'] },
      { path: '/admin/airbnb-settings', icon: 'bi-house-gear', label: 'SIDEBAR.AIRBNB_SETTINGS', permissions: ['manage airbnb settings'] },
      { path: '/admin/channex-reviews', icon: 'bi-star', label: 'SIDEBAR.CHANNEX_REVIEWS', permissions: ['manage channex reviews'] },
      { path: '/admin/channex-messages', icon: 'bi-chat-dots', label: 'SIDEBAR.CHANNEX_MESSAGES', permissions: ['manage channex messages'] },
    ],
  },
  {
    id: 'admin',
    labelKey: 'SIDEBAR.SECTION_ADMIN',
    items: [
      { path: '/admin/hotels', icon: 'bi-building', label: 'SIDEBAR.HOTELS', permissions: ['manage hotels'] },
      { path: '/admin/users', icon: 'bi-people', label: 'SIDEBAR.USERS', permissions: ['view users'] },
      { path: '/admin/clients', icon: 'bi-person-badge', label: 'SIDEBAR.CLIENTS', permissions: ['view clients'] },
      { path: '/admin/guests', icon: 'bi-star', label: 'SIDEBAR.GUESTS', permissions: ['manage guest classifications'] },
      { path: '/admin/departments', icon: 'bi-diagram-3', label: 'SIDEBAR.DEPARTMENTS', permissions: ['manage departments'] },
      { path: '/admin/job-titles', icon: 'bi-briefcase', label: 'SIDEBAR.JOB_TITLES', permissions: ['manage job titles'] },
      { path: '/admin/penalties', icon: 'bi-exclamation-octagon', label: 'SIDEBAR.PENALTIES', permissions: ['manage penalties'] },
      { path: '/admin/refund-policies', icon: 'bi-arrow-counterclockwise', label: 'SIDEBAR.REFUND_POLICIES', permissions: ['manage refund policies'] },
      { path: '/admin/discounts', icon: 'bi-percent', label: 'SIDEBAR.DISCOUNTS', permissions: ['manage discounts'] },
    ],
  },
];

@Component({
    selector: 'app-sidebar',
    templateUrl: './sidebar.component.html',
    styleUrls: ['./sidebar.component.css'],
    standalone: false
})
export class SidebarComponent implements OnInit, OnDestroy {
  collapsed = false;
  mobileOpen = false;
  mobileViewport = false;
  /** Cached — never rebuild from a getter during change detection. */
  visibleNavSections: NavSection[] = [];
  airbnbPendingCount = 0;
  private userSub?: Subscription;
  private langSub?: Subscription;
  private airbnbPendingSub?: Subscription;

  @HostBinding('class.sidebar-host-collapsed')
  get sidebarHostCollapsed(): boolean {
    return this.collapsed && !this.mobileOpen && !this.mobileViewport;
  }

  @HostBinding('class.sidebar-host-expanded')
  get sidebarHostExpanded(): boolean {
    return (!this.collapsed || this.mobileOpen) && !this.mobileViewport;
  }

  @HostBinding('class.sidebar-host-mobile')
  get sidebarHostMobile(): boolean {
    return this.mobileViewport;
  }

  constructor(
    private renderer: Renderer2,
    @Inject(DOCUMENT) private document: Document,
    private authPerm: AuthPermissionService,
    private dataService: DataService,
    private language: LanguageService,
    private cdr: ChangeDetectorRef,
    private router: Router,
    private airbnbNotifications: AirbnbNotificationService
  ) {}

  get canCreateReservation(): boolean {
    return this.authPerm.has('create reservations');
  }

  goCreateReservation(): void {
    this.closeMobileNav();
    this.router.navigate(['/booking/reservations/create']);
  }

  ngOnInit(): void {
    this.updateViewportFlag();
    this.syncLayoutVars();
    this.refreshVisibleNav();
    this.userSub = this.dataService.user$.subscribe(() => {
      this.refreshVisibleNav();
      this.cdr.markForCheck();
    });
    this.langSub = this.language.changes$.subscribe(() => {
      this.cdr.markForCheck();
    });
    this.airbnbPendingSub = this.airbnbNotifications.pendingCount$.subscribe((count) => {
      this.airbnbPendingCount = count;
      this.cdr.markForCheck();
    });
  }

  ngOnDestroy(): void {
    this.userSub?.unsubscribe();
    this.langSub?.unsubscribe();
    this.airbnbPendingSub?.unsubscribe();
  }

  @HostListener('window:resize')
  onWindowResize(): void {
    this.updateViewportFlag();
    this.syncLayoutVars();
    this.cdr.markForCheck();
  }

  private updateViewportFlag(): void {
    this.mobileViewport = typeof window !== 'undefined' && window.matchMedia('(max-width: 991.98px)').matches;
  }

  private itemVisible(item: NavItem): boolean {
    if (this.authPerm.hasFullAccess()) {
      return true;
    }
    if (!item.permissions?.length) {
      return true;
    }
    if (item.requireAllPermissions) {
      return item.permissions.every((p) => this.authPerm.has(p));
    }
    return this.authPerm.hasAny(...item.permissions);
  }

  private refreshVisibleNav(): void {
    this.visibleNavSections = navSections
      .map((section) => ({
        id: section.id,
        labelKey: section.labelKey,
        items: section.items.filter((item) => this.itemVisible(item)),
      }))
      .filter((section) => section.items.length > 0);
  }

  trackSectionById(_index: number, section: NavSection): string {
    return section.id;
  }

  trackItemByPath(_index: number, item: NavItem): string {
    return item.path;
  }

  get currentLang(): string {
    return this.language.current;
  }

  get isRtl(): boolean {
    return this.currentLang === 'ar';
  }

  get collapseIcon(): string {
    if (this.isRtl) {
      return this.collapsed ? 'bi-chevron-left' : 'bi-chevron-right';
    }
    return this.collapsed ? 'bi-chevron-right' : 'bi-chevron-left';
  }

  toggleCollapse() {
    this.collapsed = !this.collapsed;
    this.syncLayoutVars();
  }

  toggleMobileNav(): void {
    this.mobileOpen = !this.mobileOpen;
    if (this.mobileOpen) {
      this.collapsed = false;
    }
    this.syncLayoutVars();
  }

  closeMobileNav(): void {
    this.mobileOpen = false;
    this.syncLayoutVars();
  }

  private syncLayoutVars(): void {
    const root = this.document.documentElement;
    let width = '16.75rem';
    if (this.mobileViewport) {
      width = '0px';
    } else if (this.collapsed) {
      width = '4.5rem';
    } else if (typeof window !== 'undefined' && window.matchMedia('(min-width: 992px) and (max-width: 1199.98px)').matches) {
      width = '14rem';
    }
    this.renderer.setStyle(root, '--app-sidebar-width', width);
    this.renderer.setStyle(
      root,
      '--app-sidebar-collapsed',
      !this.mobileViewport && this.collapsed && !this.mobileOpen ? '1' : '0'
    );
  }

  switchLanguage(): void {
    this.language.toggle().subscribe();
  }

  onLogout(): void {
    this.dataService.logout();
    window.location.hash = '#/auth';
  }
}
