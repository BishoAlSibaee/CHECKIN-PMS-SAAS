import { Component, OnDestroy, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslateService } from '@ngx-translate/core';
import { Subscription } from 'rxjs';
import { HotelService } from '../../services/hotel.service';
import { DataService } from '../../services/Api/data.service';
import { AuthPermissionService } from '../../services/auth-permission.service';
import { ConfirmDialogService } from '../../services/confirm-dialog.service';
import { RealtimeService } from '../../services/realtime.service';

export type OccupancyStatus =
  | 'vacant'
  | 'reserved'
  | 'in_house'
  | 'pending'
  | 'check_in_today'
  | 'check_out_today'
  | 'preparation'
  | 'out_of_service'
  | 'all';

export interface RoomBoardItem {
  id: number;
  number: string;
  occupancy_status: string;
  room_type_name: string;
  room_type_id?: number;
  building_id?: number;
  building_name?: string;
  floor_id?: number;
  floor_name?: string;
  floor_number?: string;
  suite_id?: number | null;
  suite_name?: string | null;
  suite_floor_id?: number | null;
  suite_floor_number?: string | null;
  reservation?: {
    reservation_id: number;
    client_name: string;
    client_phone?: string;
    start_date: string;
    expire_date: string;
    nights_remaining: number;
    total: number;
    paid_amount: number;
    balance_due: number;
  };
}

export interface RoomBoardSuiteGroup {
  suiteId: number;
  suiteName: string;
  rooms: RoomBoardItem[];
  aggregateStatus: string;
}

export interface RoomBoardFloorGroup {
  floorId: number;
  floorName: string;
  floorNumber: string;
  suites: RoomBoardSuiteGroup[];
  standaloneRooms: RoomBoardItem[];
  rooms: RoomBoardItem[];
}

export interface RoomBoardBuildingGroup {
  buildingId: number;
  buildingName: string;
  floors: RoomBoardFloorGroup[];
  roomCount: number;
}

@Component({
    selector: 'app-room-board',
    templateUrl: './room-board.component.html',
    styleUrls: ['./room-board.component.css'],
    standalone: false
})
export class RoomBoardComponent implements OnInit, OnDestroy {
  isLoading = true;
  errorMessage = '';
  viewMode: 'grid' | 'list' = 'grid';
  /** Collapsed filter panel on narrow screens. */
  filtersExpanded = false;
  private realtimeOff?: () => void;
  private langSub?: Subscription;

  selectedDate = '';
  search = '';
  selectedBuildingId: number | null = null;
  selectedFloorId: number | null = null;
  statusFilter: OccupancyStatus = 'all';

  buildings: { id: number; label: string }[] = [];
  floors: { id: number; label: string }[] = [];

  summary: Record<string, number> = {};
  rooms: RoomBoardItem[] = [];
  groupedRooms: RoomBoardBuildingGroup[] = [];
  markingReadyRoomId: number | null = null;
  checkingOutReservationId: number | null = null;
  checkingInReservationId: number | null = null;

  statusChips: { key: OccupancyStatus; label: string }[] = [
    { key: 'all', label: 'ROOM_BOARD.FILTER_ALL' },
    { key: 'vacant', label: 'ROOM_BOARD.STATUS_VACANT' },
    { key: 'reserved', label: 'ROOM_BOARD.STATUS_RESERVED' },
    { key: 'in_house', label: 'ROOM_BOARD.STATUS_IN_HOUSE' },
    { key: 'check_in_today', label: 'ROOM_BOARD.STATUS_CHECK_IN' },
    { key: 'check_out_today', label: 'ROOM_BOARD.STATUS_CHECK_OUT' },
    { key: 'pending', label: 'ROOM_BOARD.STATUS_PENDING' },
    { key: 'preparation', label: 'ROOM_BOARD.STATUS_PREPARATION' },
    { key: 'out_of_service', label: 'ROOM_BOARD.STATUS_OUT_OF_SERVICE' }
  ];

  constructor(
    private hotelService: HotelService,
    private router: Router,
    private route: ActivatedRoute,
    private translate: TranslateService,
    private dataService: DataService,
    private confirm: ConfirmDialogService,
    public authPerm: AuthPermissionService,
    private realtime: RealtimeService
  ) {}

  get canUpdateReservations(): boolean {
    return this.authPerm.has('update reservations');
  }

  canCheckOut(room: RoomBoardItem): boolean {
    return !!(
      this.canUpdateReservations
      && room.reservation
      && (room.occupancy_status === 'check_out_today' || room.occupancy_status === 'in_house')
      && room.reservation.expire_date <= this.selectedDate
    );
  }

  canCheckIn(room: RoomBoardItem): boolean {
    return !!(
      this.canUpdateReservations
      && room.reservation
      && room.occupancy_status === 'check_in_today'
    );
  }

  get canManageRooms(): boolean {
    return this.authPerm.has('manage rooms');
  }

  ngOnInit(): void {
    // Ops-first on phones/tablets: list is easier to scan and act on than a dense grid.
    if (typeof window !== 'undefined' && window.matchMedia('(max-width: 991.98px)').matches) {
      this.viewMode = 'list';
    }
    this.selectedDate = new Date().toISOString().split('T')[0];
    const qp = this.route.snapshot.queryParamMap;
    const status = qp.get('status');
    if (status && this.statusChips.some((chip) => chip.key === status)) {
      this.statusFilter = status as OccupancyStatus;
    }
    const search = qp.get('search');
    if (search) {
      this.search = search;
    }
    this.hotelService.fetchBuildings().subscribe(() => {
      this.buildings = this.hotelService.getBuildingsSync().map((b: any) => ({
        id: Number(b.id),
        label: b.name || `Building ${b.number ?? b.id}`
      }));
    });
    this.loadBoard();
    this.realtimeOff = this.realtime.onScope('occupancy_board', () => this.loadBoard({ soft: true }));
    this.langSub = this.translate.onLangChange.subscribe(() => this.refreshLocalizedBoardLabels());
  }

  private refreshLocalizedBoardLabels(): void {
    if (this.rooms.length) {
      this.groupedRooms = this.buildGroupedRooms(this.rooms);
    }
    if (this.selectedBuildingId) {
      const bid = String(this.selectedBuildingId);
      this.floors = this.hotelService.getFloorsSync(bid).map((f: any) => ({
        id: Number(f.id),
        label: `${this.translate.instant('ROOM_BOARD.FLOOR')} ${f.number ?? f.id}`,
      }));
    }
  }

  ngOnDestroy(): void {
    this.langSub?.unsubscribe();
    this.realtimeOff?.();
  }

  onDateChange(): void {
    this.loadBoard();
  }

  onBuildingChange(): void {
    this.selectedFloorId = null;
    this.floors = [];
    if (this.selectedBuildingId) {
      const bid = String(this.selectedBuildingId);
      this.hotelService.fetchFloors(bid).subscribe(() => {
        this.floors = this.hotelService.getFloorsSync(bid).map((f: any) => ({
          id: Number(f.id),
          label: `${this.translate.instant('ROOM_BOARD.FLOOR')} ${f.number ?? f.id}`
        }));
      });
    }
    this.loadBoard();
  }

  onFilterChange(): void {
    this.loadBoard();
  }

  refresh(): void {
    this.loadBoard();
  }

  setToday(): void {
    this.selectedDate = new Date().toISOString().split('T')[0];
    this.loadBoard();
  }

  get hasActiveFilters(): boolean {
    return !!(
      this.search.trim()
      || this.selectedBuildingId
      || this.selectedFloorId
      || this.statusFilter !== 'all'
    );
  }

  clearFilters(): void {
    this.search = '';
    this.selectedBuildingId = null;
    this.selectedFloorId = null;
    this.floors = [];
    this.statusFilter = 'all';
    this.loadBoard();
  }

  setStatusFilter(status: OccupancyStatus): void {
    this.statusFilter = status;
    this.loadBoard();
  }

  setStatusFromKpi(key: string): void {
    if (key === 'total') {
      this.setStatusFilter('all');
      return;
    }
    if (this.statusChips.some((chip) => chip.key === key)) {
      this.setStatusFilter(key as OccupancyStatus);
    }
  }

  isKpiActive(key: string): boolean {
    if (key === 'total') {
      return this.statusFilter === 'all';
    }
    return this.statusFilter === key;
  }

  loadBoard(options?: { soft?: boolean }): void {
    if (!options?.soft) {
      this.isLoading = true;
    }
    this.errorMessage = '';

    this.hotelService.getRoomOccupancyBoard({
      date: this.selectedDate,
      building_id: this.selectedBuildingId ?? undefined,
      floor_id: this.selectedFloorId ?? undefined,
      occupancy_status: this.statusFilter === 'all' ? undefined : this.statusFilter,
      search: this.search.trim() || undefined
    }).subscribe({
      next: (res) => {
        if (!res?.success || !res?.data) {
          this.errorMessage = res?.message || 'Failed to load room board';
          this.isLoading = false;
          return;
        }
        this.summary = res.data.summary ?? {};
        this.rooms = res.data.rooms ?? [];
        this.groupedRooms = this.buildGroupedRooms(this.rooms);
        this.isLoading = false;
      },
      error: (err) => {
        this.errorMessage = err.error?.message || err.message || 'Request failed';
        this.isLoading = false;
      }
    });
  }

  private buildGroupedRooms(rooms: RoomBoardItem[]): RoomBoardBuildingGroup[] {
    const unknownBuilding = this.translate.instant('ROOM_BOARD.UNKNOWN_BUILDING');
    const unknownFloor = this.translate.instant('ROOM_BOARD.UNKNOWN_FLOOR');

    const buildingMap = new Map<number, {
      name: string;
      floors: Map<number, {
        name: string;
        number: string;
        sortKey: number;
        suiteMap: Map<number, { name: string; rooms: RoomBoardItem[] }>;
        standaloneRooms: RoomBoardItem[];
      }>;
    }>();

    for (const room of rooms) {
      const buildingId = room.building_id ?? 0;
      const buildingName = room.building_name?.trim() || unknownBuilding;
      const floorId = this.groupingFloorId(room);
      const floorName = this.groupingFloorName(room, unknownFloor);
      const floorNumber = this.groupingFloorNumber(room, floorId);
      const floorSortKey = this.parseFloorSortKey(floorName, floorId, floorNumber);

      if (!buildingMap.has(buildingId)) {
        buildingMap.set(buildingId, { name: buildingName, floors: new Map() });
      }

      const building = buildingMap.get(buildingId)!;

      if (!building.floors.has(floorId)) {
        building.floors.set(floorId, {
          name: floorName,
          number: floorNumber,
          sortKey: floorSortKey,
          suiteMap: new Map(),
          standaloneRooms: []
        });
      }

      const floor = building.floors.get(floorId)!;
      if (!floor.number && floorNumber) {
        floor.number = floorNumber;
      }
      if (room.suite_id) {
        const suiteId = Number(room.suite_id);
        if (!floor.suiteMap.has(suiteId)) {
          floor.suiteMap.set(suiteId, {
            name: room.suite_name?.trim() || `${this.translate.instant('ROOM_BOARD.SUITE')} ${suiteId}`,
            rooms: []
          });
        }
        floor.suiteMap.get(suiteId)!.rooms.push(room);
      } else {
        floor.standaloneRooms.push(room);
      }
    }

    return Array.from(buildingMap.entries())
      .sort((a, b) => a[1].name.localeCompare(b[1].name, undefined, { numeric: true }))
      .map(([buildingId, building]) => {
        const floors = Array.from(building.floors.entries())
          .sort((a, b) => a[1].sortKey - b[1].sortKey)
          .map(([floorId, floor]) => {
            const suites = Array.from(floor.suiteMap.entries())
              .sort((a, b) => a[1].name.localeCompare(b[1].name, undefined, { numeric: true }))
              .map(([suiteId, suite]) => ({
                suiteId,
                suiteName: suite.name,
                rooms: suite.rooms.sort((r1, r2) => this.compareRoomNumbers(r1.number, r2.number)),
                aggregateStatus: this.aggregateSuiteStatus(suite.rooms)
              }));

            const standaloneRooms = floor.standaloneRooms.sort((r1, r2) =>
              this.compareRoomNumbers(r1.number, r2.number)
            );

            const allRooms = [
              ...suites.flatMap((s) => s.rooms),
              ...standaloneRooms
            ];

            return {
              floorId,
              floorName: floor.name,
              floorNumber: floor.number,
              suites,
              standaloneRooms,
              rooms: allRooms
            };
          });

        return {
          buildingId,
          buildingName: building.name,
          floors,
          roomCount: floors.reduce((sum, floor) => sum + floor.rooms.length, 0)
        };
      });
  }

  private aggregateSuiteStatus(rooms: RoomBoardItem[]): string {
    const priority = [
      'in_house',
      'check_out_today',
      'check_in_today',
      'reserved',
      'pending',
      'preparation',
      'out_of_service',
      'vacant'
    ];

    for (const status of priority) {
      if (rooms.some((room) => room.occupancy_status === status)) {
        return status;
      }
    }

    return rooms[0]?.occupancy_status ?? 'vacant';
  }

  private groupingFloorId(room: RoomBoardItem): number {
    if (room.suite_id && room.suite_floor_id != null) {
      return Number(room.suite_floor_id);
    }
    return room.floor_id ?? 0;
  }

  private groupingFloorName(room: RoomBoardItem, fallback: string): string {
    if (room.suite_id && room.suite_floor_id != null && room.suite_floor_number) {
      return `Floor ${room.suite_floor_number}`;
    }
    return room.floor_name?.trim() || fallback;
  }

  private groupingFloorNumber(room: RoomBoardItem, floorId: number): string {
    if (room.suite_id && room.suite_floor_id != null && room.suite_floor_number) {
      return String(room.suite_floor_number);
    }
    return room.floor_number ?? this.extractFloorNumber(room.floor_name, floorId);
  }

  private parseFloorSortKey(floorName: string | undefined, floorId: number, floorNumber?: string): number {
    const extracted = floorNumber ?? this.extractFloorNumber(floorName, floorId);
    const parsed = Number(extracted);
    if (!Number.isNaN(parsed) && extracted !== '') {
      return parsed;
    }
    return floorId;
  }

  private extractFloorNumber(floorName: string | undefined, floorId: number): string {
    if (floorName) {
      const match = floorName.match(/\d+/);
      if (match) {
        return match[0];
      }
      const trimmed = floorName.trim();
      if (trimmed) {
        return trimmed;
      }
    }
    return String(floorId);
  }

  private compareRoomNumbers(a: string, b: string): number {
    const numA = Number(a);
    const numB = Number(b);
    if (!Number.isNaN(numA) && !Number.isNaN(numB)) {
      return numA - numB;
    }
    return a.localeCompare(b, undefined, { numeric: true });
  }

  statusClass(status: string): string {
    return `room-card--${status}`;
  }

  /** Width tier so small suites (e.g. 2 rooms) can sit side-by-side on the board. */
  suiteBlockClass(suite: RoomBoardSuiteGroup): string {
    const count = suite.rooms.length;
    if (count <= 2) {
      return 'suite-block--xs';
    }
    if (count <= 4) {
      return 'suite-block--sm';
    }
    if (count <= 6) {
      return 'suite-block--md';
    }
    return 'suite-block--lg';
  }

  openCreateReservation(room: RoomBoardItem): void {
    const queryParams: Record<string, string | number> = {
      roomId: room.id
    };
    if (room.building_id) {
      queryParams['buildingId'] = room.building_id;
    }
    if (room.room_type_id) {
      queryParams['roomTypeId'] = room.room_type_id;
    }
    if (this.selectedDate) {
      queryParams['startDate'] = this.selectedDate;
    }
    this.router.navigate(['/booking/reservations/create'], { queryParams });
  }

  openReservation(room: RoomBoardItem): void {
    const id = room.reservation?.reservation_id;
    if (id) {
      this.router.navigate(['/booking/reservations', id]);
    }
  }

  openRoomHistory(room: RoomBoardItem): void {
    this.router.navigate(['/admin/property/room', room.number, 'history']);
  }

  async checkInGuest(room: RoomBoardItem, event: Event): Promise<void> {
    event.stopPropagation();
    const reservation = room.reservation;
    if (!reservation || !this.canCheckIn(room)) {
      return;
    }

    const ok = await this.confirm.confirm(
      this.translate.instant('DASHBOARD.CHECK_IN_CONFIRM', {
        guest: reservation.client_name,
        room: room.number
      })
    );
    if (!ok) {
      return;
    }

    this.checkingInReservationId = reservation.reservation_id;
    this.hotelService.updateReservation(reservation.reservation_id, {
      logedin: 1,
      login_time: this.selectedDate || new Date().toISOString().split('T')[0]
    }).subscribe({
      next: (res) => {
        this.checkingInReservationId = null;
        if (res?.success) {
          this.dataService.showToast(this.translate.instant('RESERVATIONS.UPDATED_OK'), 'success');
          this.loadBoard();
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('COMMON.ERROR'), 'error');
        }
      },
      error: (err) => {
        this.checkingInReservationId = null;
        this.dataService.showToast(err.error?.message || this.translate.instant('COMMON.ERROR'), 'error');
      }
    });
  }

  async checkOutGuest(room: RoomBoardItem, event: Event): Promise<void> {
    event.stopPropagation();
    const reservation = room.reservation;
    if (!reservation || !this.canCheckOut(room)) {
      return;
    }

    const balance = Number(reservation.balance_due ?? 0);
    if (balance > 0.005) {
      this.dataService.showToast(
        this.translate.instant('RESERVATIONS.CHECKOUT_REQUIRES_PAYMENT', { amount: balance.toFixed(2) }),
        'warning'
      );
      this.openReservation(room);
      return;
    }

    const ok = await this.confirm.confirm(
      this.translate.instant('DASHBOARD.CHECK_OUT_CONFIRM', {
        guest: reservation.client_name,
        room: room.number
      })
    );
    if (!ok) {
      return;
    }

    this.checkingOutReservationId = reservation.reservation_id;
    this.hotelService.updateReservation(reservation.reservation_id, { logedin: 0 }).subscribe({
      next: (res) => {
        this.checkingOutReservationId = null;
        if (res?.success) {
          this.dataService.showToast(this.translate.instant('RESERVATIONS.UPDATED_OK'), 'success');
          this.loadBoard();
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('COMMON.ERROR'), 'error');
        }
      },
      error: (err) => {
        this.checkingOutReservationId = null;
        this.dataService.showToast(err.error?.message || this.translate.instant('COMMON.ERROR'), 'error');
      }
    });
  }

  markRoomReady(room: RoomBoardItem, event: Event): void {
    event.stopPropagation();
    if (!this.canManageRooms || room.occupancy_status !== 'preparation') {
      return;
    }

    this.markingReadyRoomId = room.id;
    this.hotelService.updateRoom({ id: room.id, roomStatus: 1 }).subscribe({
      next: (res) => {
        this.markingReadyRoomId = null;
        if (res?.success) {
          this.dataService.showToast(this.translate.instant('ROOM_BOARD.MARK_READY_SUCCESS'), 'success');
          this.loadBoard();
        } else {
          this.dataService.showToast(res?.message || this.translate.instant('COMMON.ERROR'), 'error');
        }
      },
      error: (err) => {
        this.markingReadyRoomId = null;
        this.dataService.showToast(err.error?.message || this.translate.instant('COMMON.ERROR'), 'error');
      }
    });
  }

  goDashboard(): void {
    this.router.navigate(['/dashboard']);
  }

  formatMoney(n: number): string {
    return Number(n ?? 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
  }

  summaryCount(key: string): number {
    return this.summary[key] ?? 0;
  }
}
