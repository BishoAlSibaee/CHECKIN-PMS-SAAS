import { Component, OnInit, OnDestroy } from '@angular/core';
import { HotelService } from '../../services/hotel.service';
import { DataService } from '../../services/Api/data.service';
import { Booking } from '../../models/booking.interface';
import { Router } from '@angular/router';
import { forkJoin, Subscription } from 'rxjs';
import { TranslateService } from '@ngx-translate/core';
import { AuthPermissionService } from '../../services/auth-permission.service';
import { ConfirmDialogService } from '../../services/confirm-dialog.service';
import { RealtimeService } from '../../services/realtime.service';

type RoomStatus = 'available' | 'occupied' | 'preparation' | 'out_of_service';
type ViewMode = 'grid' | 'list';

interface RoomView {
  id: string;
  number: string;
  type: string;
  roomTypeId: string;
  roomStatusCode: number;
  suiteId: string | null;
  status: RoomStatus;
  guest?: string;
  beds: number;
  rate: number;
}

interface SuiteView {
  id: string;
  name: string;
  number: string;
  rooms: RoomView[];
}

interface FloorView {
  id: string;
  name: string;
  rooms: RoomView[];
  suites: SuiteView[];
}

interface BuildingView {
  id: string;
  name: string;
  floors: FloorView[];
}

type PropertyModalType = 'building' | 'floor' | 'suite' | 'room' | null;

@Component({
    selector: 'app-property',
    templateUrl: './property.component.html',
    styleUrls: ['./property.component.css'],
    standalone: false
})
export class PropertyComponent implements OnInit, OnDestroy {
  isLoading = true;
  isLoadingFloors = false;
  isLoadingRooms = false;

  buildings: BuildingView[] = [];
  floors: FloorView[] = [];
  suites: SuiteView[] = [];
  rooms: RoomView[] = [];
  private occupancyByRoomId: Record<string, string> = {};

  private subscription = new Subscription();
  private floorsSubscription?: Subscription;
  private roomsSubscription?: Subscription;
  private suitesSubscription?: Subscription;
  private realtimeOff?: () => void;

  constructor(
    private hotelService: HotelService,
    private router: Router,
    private translate: TranslateService,
    private dataService: DataService,
    public authPerm: AuthPermissionService,
    private confirmDialog: ConfirmDialogService,
    private realtime: RealtimeService
  ) {}

  get canManageSuites(): boolean {
    return this.authPerm.has('manage suites');
  }

  get canManageBuildings(): boolean {
    return this.authPerm.has('manage buildings');
  }

  get canManageFloors(): boolean {
    return this.authPerm.has('manage floors');
  }

  get canManageRooms(): boolean {
    return this.authPerm.has('manage rooms');
  }

  get canViewBuildings(): boolean {
    return this.authPerm.has('view buildings');
  }

  get canViewFloors(): boolean {
    return this.authPerm.has('view floors');
  }

  get canViewSuites(): boolean {
    return this.authPerm.has('view suites');
  }

  get canViewRooms(): boolean {
    return this.authPerm.has('view rooms');
  }

  get canViewRoomTypes(): boolean {
    return this.authPerm.has('view room types');
  }

  get canViewOccupancyBoard(): boolean {
    return this.authPerm.has('view reservations');
  }

  ngOnInit(): void {
    this.isLoading = true;

    this.subscription.add(
      this.hotelService.buildings$.subscribe(data => {
        if (Array.isArray(data) && data.length > 0) {
          this.buildings = data.map((b: any) => ({
            id: b.id.toString(),
            name: b.name || `Building ${b.number}`,
            floors: b.floors || []
          }));

          this.isLoading = false;

          if (!this.selectedBuilding) {
            this.selectBuilding(this.buildings[0]);
          }
        } else if (this.hotelService.isBuildingsLoaded()) {
          // If loaded but empty, stop loading
          this.isLoading = false;
        }
      })
    );

    if (this.canViewBuildings && !this.hotelService.isBuildingsLoaded()) {
      this.loadBuildings();
    } else if (!this.canViewBuildings) {
      this.isLoading = false;
    } else {
      this.isLoading = false;
    }

    this.realtimeOff = this.realtime.onScope('property', () => {
      if (this.selectedBuilding && this.selectedFloor) {
        this.syncOccupancyFromBoard(this.selectedBuilding, this.selectedFloor);
      }
    });
  }

  ngOnDestroy(): void {
    this.subscription.unsubscribe();
    this.floorsSubscription?.unsubscribe();
    this.roomsSubscription?.unsubscribe();
    this.suitesSubscription?.unsubscribe();
    this.realtimeOff?.();
  }

  loadBuildings(): void {
    if (!this.canViewBuildings) {
      this.isLoading = false;
      return;
    }
    this.isLoading = true;
    this.hotelService.fetchBuildings().subscribe({
      next: () => this.isLoading = false,
      error: () => this.isLoading = false
    });
  }

  loadFloors(buildingId: string): void {
    if (!this.canViewFloors) {
      return;
    }
    this.floorsSubscription?.unsubscribe();
    this.floorsSubscription = this.hotelService.getFloors(buildingId).subscribe(floorsData => {
      const mappedFloors = floorsData.map((f: any) => ({
        id: f.id.toString(),
        name: `Floor ${f.number}`,
        rooms: f.rooms || [],
        suites: f.suites || []
      }));

      const building = this.buildings.find(b => b.id === buildingId);
      if (building) {
        building.floors = mappedFloors;
      }

      // If this is the currently selected building, also update the active floors list
      if (this.selectedBuilding === buildingId) {
        this.floors = mappedFloors;
        if (this.floors.length > 0 && !this.selectedFloor) {
          this.selectFloor(this.floors[0]);
        }
      }
    });

    if (!this.hotelService.isFloorsLoaded(buildingId)) {
      this.isLoadingFloors = true;
      this.hotelService.fetchFloors(buildingId).subscribe({
        next: () => this.isLoadingFloors = false,
        error: () => this.isLoadingFloors = false
      });
    }
  }

  loadRoomsAndSuites(buildingId: string, floorId: string): void {
    const params = { building_id: buildingId, floor_id: floorId };
    const loadRooms = this.canViewRooms;
    const loadSuites = this.canViewSuites;

    if (!loadRooms && !loadSuites) {
      return;
    }

    // Subscribe to Rooms
    this.roomsSubscription?.unsubscribe();
    if (loadRooms) {
    this.roomsSubscription = this.hotelService.getRooms(params).subscribe(roomsData => {
      const mappedRooms = roomsData.map((r: any) => ({
        id: r.id.toString(),
        number: r.number.toString(),
        type: r.room_type?.name_en || 'Standard',
        roomTypeId: (r.room_type_id ?? r.room_type?.id)?.toString() || '',
        roomStatusCode: Number(r.roomStatus ?? 1),
        suiteId: r.suite_id != null ? String(r.suite_id) : null,
        status: this.mapRoomStatus(Number(r.roomStatus ?? 1)),
        beds: r.capacity || 1,
        rate: r.base_price || 0
      }));

      const building = this.buildings.find(b => b.id === buildingId);
      if (building) {
        const floor = building.floors.find(f => f.id === floorId);
        if (floor) {
          floor.rooms = mappedRooms;
        }
      }

      // If this is the currently selected floor, also update the active rooms list
      if (this.selectedFloor === floorId) {
        this.rooms = mappedRooms;
      }
    });
    }

    // Subscribe to Suites
    this.suitesSubscription?.unsubscribe();
    if (loadSuites) {
    this.suitesSubscription = this.hotelService.getSuites(params).subscribe(suitesData => {
      const mappedSuites = suitesData.map((s: any) => ({
        id: s.id.toString(),
        number: s.number?.toString() ?? '',
        name: `Suite ${s.number}`,
        rooms: (s.rooms || []).map((r: any) => ({
          id: r.id.toString(),
          number: r.number.toString(),
          type: r.room_type?.name_en || 'Standard',
          roomTypeId: (r.room_type_id ?? r.room_type?.id)?.toString() || '',
          roomStatusCode: Number(r.roomStatus ?? 1),
          suiteId: r.suite_id != null ? String(r.suite_id) : null,
          status: this.mapRoomStatus(Number(r.roomStatus ?? 1)),
          beds: r.capacity || 1,
          rate: r.base_price || 0
        }))
      }));

      const building = this.buildings.find(b => b.id === buildingId);
      if (building) {
        const floor = building.floors.find(f => f.id === floorId);
        if (floor) {
          floor.suites = mappedSuites;
        }
      }

      // If this is the currently selected floor, also update the active suites list
      if (this.selectedFloor === floorId) {
        this.suites = mappedSuites;
        this.refreshSuiteDrawer();
      }
    });
    }

    // Fetch only when not yet loaded for this floor (avoid retry loops on throttle/errors).
    const requests = [];
    if (loadRooms && !this.hotelService.isRoomsLoaded(params)) {
      requests.push(this.hotelService.fetchRooms(params));
    }
    if (loadSuites && !this.hotelService.isSuitesLoaded(params)) {
      requests.push(this.hotelService.fetchSuites(params));
    }

    if (requests.length) {
      this.isLoadingRooms = true;
      forkJoin(requests).subscribe({
        next: () => {
          this.isLoadingRooms = false;
          if (this.canViewOccupancyBoard) {
            this.syncOccupancyFromBoard(buildingId, floorId);
          }
        },
        error: () => this.isLoadingRooms = false
      });
    } else if (this.canViewOccupancyBoard) {
      this.syncOccupancyFromBoard(buildingId, floorId);
    }
  }

  private syncOccupancyFromBoard(buildingId: string, floorId: string): void {
    if (!this.canViewOccupancyBoard) {
      return;
    }
    const today = new Date().toISOString().split('T')[0];
    this.hotelService.getRoomOccupancyBoard({
      date: today,
      building_id: Number(buildingId),
      floor_id: Number(floorId)
    }).subscribe({
      next: (res) => {
        const boardRooms = res.data?.rooms ?? res.data?.data?.rooms ?? [];
        this.occupancyByRoomId = {};
        for (const r of boardRooms) {
          if (r.id != null) {
            this.occupancyByRoomId[String(r.id)] = r.occupancy_status;
          }
        }
        this.applyOccupancyToCurrentFloor(buildingId, floorId);
      }
    });
  }

  private applyOccupancyToCurrentFloor(buildingId: string, floorId: string): void {
    const patchRooms = (list: RoomView[]) =>
      list.map(room => ({
        ...room,
        status: this.resolveDisplayStatus(room)
      }));

    if (this.selectedFloor === floorId) {
      this.rooms = patchRooms(this.rooms);
      this.suites = this.suites.map(s => ({
        ...s,
        rooms: patchRooms(s.rooms)
      }));
    }

    const building = this.buildings.find(b => b.id === buildingId);
    const floor = building?.floors.find(f => f.id === floorId);
    if (floor) {
      floor.rooms = patchRooms(floor.rooms);
      floor.suites = floor.suites.map(s => ({
        ...s,
        rooms: patchRooms(s.rooms)
      }));
    }
  }

  private mapOccupancyStatus(occupancy: string): RoomStatus {
    if (occupancy === 'vacant') return 'available';
    if (occupancy === 'preparation') return 'preparation';
    if (occupancy === 'out_of_service') return 'out_of_service';
    return 'occupied';
  }

  private mapRoomStatus(status: number): RoomStatus {
    switch (status) {
      case 1: return 'available';
      case 2: return 'occupied';
      case 3: return 'preparation';
      case 4: return 'out_of_service';
      default: return 'available';
    }
  }

  private resolveDisplayStatus(room: RoomView): RoomStatus {
    if (room.roomStatusCode === 3) return 'preparation';
    if (room.roomStatusCode === 4) return 'out_of_service';

    const occ = this.occupancyByRoomId[room.id];
    if (occ === 'preparation') return 'preparation';
    if (occ === 'out_of_service') return 'out_of_service';
    if (occ && occ !== 'vacant') {
      return 'occupied';
    }
    if (room.roomStatusCode === 2) return 'occupied';
    return 'available';
  }

  readonly operationalStatusOptions = [
    { value: 1, labelKey: 'PROPERTY.STATUS.AVAILABLE' },
    { value: 2, labelKey: 'PROPERTY.STATUS.OCCUPIED' },
    { value: 3, labelKey: 'PROPERTY.STATUS.PREPARATION' },
    { value: 4, labelKey: 'PROPERTY.STATUS.OUT_OF_SERVICE' },
  ];

  expandedBuildings: string[] = [];
  expandedFloors: string[] = [];
  selectedBuilding = '';
  selectedFloor = '';
  selectedSuite: string | null = null;
  selectedSuiteDrawer: SuiteView | null = null;
  selectedRoom: RoomView | null = null;
  search = '';
  filterStatus: RoomStatus | 'all' = 'all';
  viewMode: ViewMode = 'grid';
  modalType: PropertyModalType = null;
  isEditMode = false;
  editingRoomId = '';
  editingSuiteId = '';
  modalName = '';
  roomNumber = '';
  roomType = 'Standard';
  roomBeds = 1;
  roomRate = 120;

  // For Add Modal
  numberOfFloors = 1;
  buildingNumber = '';
  floorNumber = '';
  roomCapacity = 2;
  roomTypeId = '';
  roomStatusCode = 1;
  roomtypeList: any[] = [];
  selectedRoomIds: Record<string, boolean> = {};

  // Pagination
  currentPage = 1;
  pageSize = 12;
  Math = Math;

  get currentBuilding(): BuildingView | undefined {
    return this.buildings.find((b) => b.id === this.selectedBuilding);
  }

  get currentFloor(): FloorView | undefined {
    return this.floors.find((f) => f.id === this.selectedFloor);
  }

  get currentSuite(): SuiteView | undefined {
    return this.selectedSuite ? this.resolveSuiteById(this.selectedSuite) : undefined;
  }

  get breadcrumbParts(): string[] {
    return [this.currentBuilding?.name, this.currentFloor?.name, this.currentSuite?.name].filter((v): v is string => !!v);
  }

  get displayRooms(): RoomView[] {
    if (this.selectedSuite) {
      return this.currentSuite?.rooms || [];
    }
    if (this.selectedFloor) {
      return this.rooms.filter((room) => !room.suiteId);
    }
    return [];
  }

  get allRooms(): RoomView[] {
    return this.rooms; // Simplification for now, as we load per floor
  }

  get totalRoomsCount(): number {
    return this.rooms.length;
  }

  get availableRoomsCount(): number {
    return this.rooms.filter((room) => room.status === 'available').length;
  }

  get occupiedRoomsCount(): number {
    return this.rooms.filter((room) => room.status === 'occupied').length;
  }

  get maintenanceRoomsCount(): number {
    return this.rooms.filter((room) => room.status === 'preparation' || room.status === 'out_of_service').length;
  }

  get filteredRooms(): RoomView[] {
    const q = this.search.toLowerCase();
    return this.displayRooms.filter((room) => {
      const statusOk = this.filterStatus === 'all' || room.status === this.filterStatus;
      const searchOk =
        !q ||
        room.number.toLowerCase().includes(q) ||
        room.type.toLowerCase().includes(q) ||
        (room.guest ?? '').toLowerCase().includes(q);
      return statusOk && searchOk;
    });
  }

  get paginatedRooms(): RoomView[] {
    const start = (this.currentPage - 1) * this.pageSize;
    return this.filteredRooms.slice(start, start + this.pageSize);
  }

  get totalPages(): number {
    return Math.ceil(this.filteredRooms.length / this.pageSize);
  }

  get pages(): number[] {
    return Array.from({ length: this.totalPages }, (_, i) => i + 1);
  }

  setPage(page: number): void {
    if (page >= 1 && page <= this.totalPages) {
      this.currentPage = page;
    }
  }

  getRoomCountForBuilding(building: BuildingView): number {
    return 0; // Need additional API or recursive count
  }

  getRoomCountForFloor(floor: FloorView): number {
    return this.rooms.length;
  }

  getRoomBooking(roomNumber: string): Booking | undefined {
    const bookings = this.hotelService.getBookingsSync();
    return bookings.find(b => b.room.includes(roomNumber) && (b.status === 'checked-in' || b.status === 'confirmed'));
  }

  getBookingTotalPaid(booking: Booking): number {
    return (booking.payments || []).reduce((sum, p) => sum + p.amount, 0);
  }

  getBookingRemaining(booking: Booking): number {
    return booking.amount - this.getBookingTotalPaid(booking);
  }

  getRoomRevenue(roomNumber: string): number {
    const bookings = this.hotelService.getBookingsSync();
    return bookings
      .filter(b => b.room.includes(roomNumber))
      .reduce((sum, b) => sum + this.getBookingTotalPaid(b), 0);
  }

  viewBooking(bookingId: string): void {
    this.router.navigate(['/booking/reservations', bookingId]);
  }

  viewRoomHistory(roomNumber: string): void {
    this.router.navigate(['/admin/property/room', roomNumber, 'history']);
  }

  getOccupiedCountForSuite(suite: SuiteView): number {
    return suite.rooms.filter((room) => room.status === 'occupied').length;
  }

  getAvailableRoomsForFloor(): RoomView[] {
    return this.rooms.filter((room) => !room.suiteId);
  }

  getRoomsForSuiteModal(): RoomView[] {
    const suiteId = this.editingSuiteId;
    return this.rooms.filter((room) => !room.suiteId || room.suiteId === suiteId);
  }

  getStatusLabel(status: RoomStatus): string {
    if (status === 'available') return 'PROPERTY.STATUS.AVAILABLE';
    if (status === 'occupied') return 'PROPERTY.STATUS.OCCUPIED';
    if (status === 'preparation') return 'PROPERTY.STATUS.PREPARATION';
    return 'PROPERTY.STATUS.OUT_OF_SERVICE';
  }

  getStatusDotClass(status: RoomStatus): string {
    if (status === 'available') return 'dot-available';
    if (status === 'occupied') return 'dot-occupied';
    if (status === 'preparation') return 'dot-preparation';
    return 'dot-out-of-service';
  }

  getStatusCardClass(status: RoomStatus): string {
    if (status === 'available') return 'room-available';
    if (status === 'occupied') return 'room-occupied';
    if (status === 'preparation') return 'room-preparation';
    return 'room-out-of-service';
  }

  toggleBuilding(buildingId: string, event?: Event): void {
    if (event) event.stopPropagation();

    if (this.expandedBuildings.includes(buildingId)) {
      this.expandedBuildings = this.expandedBuildings.filter((id) => id !== buildingId);
    } else {
      this.expandedBuildings = [...this.expandedBuildings, buildingId];
      if (this.selectedBuilding !== buildingId) {
        this.selectBuilding(this.buildings.find(b => b.id === buildingId)!);
      } else if (this.floors.length === 0) {
        this.loadFloors(buildingId);
      }
    }
  }

  toggleFloor(floorId: string, event?: Event): void {
    if (event) event.stopPropagation();

    if (this.expandedFloors.includes(floorId)) {
      this.expandedFloors = this.expandedFloors.filter((id) => id !== floorId);
    } else {
      this.expandedFloors = [...this.expandedFloors, floorId];
      if (this.selectedFloor !== floorId) {
        this.selectFloor(this.floors.find(f => f.id === floorId)!);
      } else if (this.rooms.length === 0) {
        this.loadRoomsAndSuites(this.selectedBuilding, floorId);
      }
    }
  }

  selectBuilding(building: BuildingView): void {
    const sameBuilding = this.selectedBuilding === building.id;

    if (sameBuilding && this.selectedSuite) {
      this.clearSuiteSelection();
      return;
    }

    if (sameBuilding && this.floors.length > 0) return;

    this.selectedBuilding = building.id;
    this.selectedFloor = '';
    this.selectedSuite = null;
    this.selectedSuiteDrawer = null;
    this.currentPage = 1;
    this.floors = [];
    this.suites = [];
    this.rooms = [];
    this.loadFloors(building.id);

    if (!this.expandedBuildings.includes(building.id)) {
      this.expandedBuildings = [...this.expandedBuildings, building.id];
    }
  }

  selectFloor(floor: FloorView): void {
    const sameFloor = this.selectedFloor === floor.id;

    if (sameFloor && this.selectedSuite) {
      this.clearSuiteSelection();
      return;
    }

    if (sameFloor && this.rooms.length > 0) return;

    this.selectedFloor = floor.id;
    this.selectedSuite = null;
    this.selectedSuiteDrawer = null;
    this.currentPage = 1;
    this.suites = [];
    this.rooms = [];
    this.loadRoomsAndSuites(this.selectedBuilding, floor.id);

    if (!this.expandedFloors.includes(floor.id)) {
      this.expandedFloors = [...this.expandedFloors, floor.id];
    }
  }

  selectSuite(suite: SuiteView): void {
    const resolved = this.resolveSuiteById(suite.id) ?? suite;
    this.selectedSuite = resolved.id;
    this.selectedSuiteDrawer = resolved;
    this.selectedRoom = null;
    this.currentPage = 1;
  }

  clearSuiteSelection(): void {
    this.selectedSuite = null;
    this.selectedSuiteDrawer = null;
    this.selectedRoom = null;
    this.currentPage = 1;
  }

  private resolveSuiteById(suiteId: string): SuiteView | undefined {
    const fromActive = this.suites.find((s) => s.id === suiteId);
    if (fromActive) {
      return fromActive;
    }

    for (const building of this.buildings) {
      for (const floor of building.floors) {
        const suite = floor.suites?.find((s) => s.id === suiteId);
        if (suite) {
          return suite;
        }
      }
    }

    return undefined;
  }

  closeSuiteDrawer(): void {
    this.selectedSuiteDrawer = null;
  }

  private refreshSuiteDrawer(): void {
    if (!this.selectedSuite) {
      this.selectedSuiteDrawer = null;
      return;
    }
    const suite = this.resolveSuiteById(this.selectedSuite);
    this.selectedSuiteDrawer = suite ?? null;
    if (!suite) {
      this.selectedSuite = null;
    }
  }

  openEditSuiteModal(): void {
    const suite = this.currentSuite;
    if (!suite || !this.canManageSuites) return;

    this.isEditMode = true;
    this.editingSuiteId = suite.id;
    this.modalType = 'suite';
    this.modalName = suite.number || suite.name.replace(/^Suite\s*/i, '');
    this.selectedRoomIds = {};
    for (const room of suite.rooms) {
      this.selectedRoomIds[room.id] = true;
    }
  }

  async deactivateSuite(): Promise<void> {
    const suite = this.currentSuite;
    if (!suite || !this.canManageSuites) return;

    const ok = await this.confirmDialog.confirm(
      this.translate.instant('PROPERTY.SUITE_DRAWER.CONFIRM_DEACTIVATE')
    );
    if (!ok) return;

    const payload = {
      id: Number(suite.id),
      building_id: Number(this.selectedBuilding),
      floor_id: Number(this.selectedFloor),
      active: false
    };

    this.hotelService.updateSuite(payload).subscribe({
      next: (res) => {
        if (res.success) {
          this.selectedSuite = null;
          this.selectedSuiteDrawer = null;
          this.loadRoomsAndSuites(this.selectedBuilding, this.selectedFloor);
          this.dataService.showToast(
            res.message || this.translate.instant('PROPERTY.MODAL.SUITE_DEACTIVATED'),
            'success'
          );
        } else {
          this.dataService.showToast(
            res.message || this.translate.instant('PROPERTY.MODAL.SUITE_UPDATE_FAILED'),
            'error'
          );
        }
      },
      error: (err) => {
        this.dataService.showToast(
          err?.error?.message || this.translate.instant('PROPERTY.MODAL.SUITE_UPDATE_FAILED'),
          'error'
        );
      }
    });
  }

  async deleteSuite(): Promise<void> {
    const suite = this.currentSuite;
    if (!suite || !this.canManageSuites) return;

    const ok = await this.confirmDialog.confirm(
      this.translate.instant('PROPERTY.SUITE_DRAWER.CONFIRM_DELETE')
    );
    if (!ok) return;

    this.hotelService.deleteSuite(suite.id, this.selectedBuilding, this.selectedFloor, false).subscribe({
      next: (res) => {
        if (res.success) {
          this.selectedSuite = null;
          this.selectedSuiteDrawer = null;
          this.loadRoomsAndSuites(this.selectedBuilding, this.selectedFloor);
          this.dataService.showToast(
            res.message || this.translate.instant('PROPERTY.MODAL.SUITE_DELETED'),
            'success'
          );
        } else {
          this.dataService.showToast(
            res.message || this.translate.instant('PROPERTY.MODAL.SUITE_DELETE_FAILED'),
            'error'
          );
        }
      },
      error: (err) => {
        this.dataService.showToast(
          err?.error?.message || this.translate.instant('PROPERTY.MODAL.SUITE_DELETE_FAILED'),
          'error'
        );
      }
    });
  }

  openAddModal(type: PropertyModalType): void {
    this.isEditMode = false;
    this.editingRoomId = '';
    this.modalType = type;
    this.modalName = '';
    this.roomNumber = '';
    this.numberOfFloors = 1;
    this.buildingNumber = '';
    this.floorNumber = '';
    this.roomCapacity = 2;
    this.roomStatusCode = 1;
    this.selectedRoomIds = {};
    this.ensureRoomTypesLoaded();
  }

  openEditRoomModal(): void {
    if (!this.selectedRoom) return;

    this.isEditMode = true;
    this.editingRoomId = this.selectedRoom.id;
    this.modalType = 'room';
    this.roomNumber = this.selectedRoom.number;
    this.roomCapacity = this.selectedRoom.beds;
    this.roomTypeId = this.selectedRoom.roomTypeId;
    this.roomStatusCode = this.selectedRoom.roomStatusCode;

    this.ensureRoomTypesLoaded(() => {
      if (!this.roomTypeId && this.roomtypeList.length > 0) {
        const match = this.roomtypeList.find(
          t => t.name === this.selectedRoom?.type || t.name_en === this.selectedRoom?.type
        );
        this.roomTypeId = match?.id ?? this.roomtypeList[0].id;
      }
    });
  }

  private ensureRoomTypesLoaded(onLoaded?: () => void): void {
    if (!this.canViewRoomTypes) {
      onLoaded?.();
      return;
    }
    if (this.roomtypeList.length > 0) {
      if (!this.isEditMode && !this.roomTypeId) {
        this.roomTypeId = this.roomtypeList[0].id;
      }
      onLoaded?.();
      return;
    }

    this.hotelService.fetchRoomTypes().subscribe(types => {
      this.roomtypeList = types;
      if (!this.isEditMode && this.roomtypeList.length > 0 && !this.roomTypeId) {
        this.roomTypeId = this.roomtypeList[0].id;
      }
      onLoaded?.();
    });
  }

  closeAddModal(): void {
    this.modalType = null;
    this.isEditMode = false;
    this.editingRoomId = '';
    this.editingSuiteId = '';
  }

  submitAddModal(): void {
    if (this.modalType === 'building') {
      this.addBuilding();
    } else if (this.modalType === 'floor') {
      this.addFloor();
    } else if (this.modalType === 'suite') {
      if (this.isEditMode) {
        this.saveSuite();
      } else {
        this.addSuite();
      }
    } else if (this.modalType === 'room') {
      if (this.isEditMode) {
        this.updateRoom();
      } else {
        this.addRoom();
      }
    }
  }

  get availableRoomTypes(): string[] {
    return this.roomtypeList.map(t => t.name);
  }

  private addBuilding(): void {
    if (!this.modalName.trim() || !this.buildingNumber) return;

    const payload = {
      name: this.modalName.trim(),
      number: this.buildingNumber,
      numberOfFloor: this.numberOfFloors,
      numberFloor: 1 // Start from floor 1
    };

    this.hotelService.addBuilding(payload).subscribe(res => {
      if (res.success) {
        this.loadBuildings();
        this.closeAddModal();
      } else {
        this.dataService.showToast(res.message || 'Error adding building', 'error');
      }
    });
  }

  private addFloor(): void {
    if (!this.floorNumber || !this.selectedBuilding) return;

    const payload = {
      building_id: this.selectedBuilding,
      number: this.floorNumber
    };

    this.hotelService.addFloor(payload).subscribe(res => {
      if (res.success) {
        this.loadFloors(this.selectedBuilding);
        this.closeAddModal();
      } else {
        this.dataService.showToast(res.message || 'Error adding floor', 'error');
      }
    });
  }

  private addSuite(): void {
    if (!this.modalName.trim() || !this.selectedBuilding || !this.selectedFloor) {
      this.dataService.showToast(this.translate.instant('PROPERTY.MODAL.SUITE_NAME_REQUIRED'), 'warning');
      return;
    }

    const roomIds = Object.entries(this.selectedRoomIds)
      .filter(([, checked]) => checked)
      .map(([id]) => id);

    if (roomIds.length === 0) {
      this.dataService.showToast(this.translate.instant('PROPERTY.MODAL.SELECT_ROOMS_REQUIRED'), 'warning');
      return;
    }

    const payload = {
      building_id: Number(this.selectedBuilding),
      floor_id: Number(this.selectedFloor),
      number: this.modalName.trim(),
      rooms: roomIds.map(id => ({ id: Number(id) }))
    };

    this.hotelService.addSuite(payload).subscribe({
      next: (res) => {
        if (res.success) {
          this.loadRoomsAndSuites(this.selectedBuilding, this.selectedFloor);
          this.closeAddModal();
          this.dataService.showToast(res.message || this.translate.instant('PROPERTY.MODAL.SUITE_ADDED'), 'success');
        } else {
          this.dataService.showToast(res.message || this.translate.instant('PROPERTY.MODAL.SUITE_ADD_FAILED'), 'error');
        }
      },
      error: (err) => {
        this.dataService.showToast(err?.error?.message || this.translate.instant('PROPERTY.MODAL.SUITE_ADD_FAILED'), 'error');
      }
    });
  }

  private saveSuite(): void {
    if (!this.modalName.trim() || !this.editingSuiteId || !this.selectedBuilding || !this.selectedFloor) {
      this.dataService.showToast(this.translate.instant('PROPERTY.MODAL.SUITE_NAME_REQUIRED'), 'warning');
      return;
    }

    const suite = this.currentSuite;
    const currentIds = new Set((suite?.rooms ?? []).map((r) => r.id));
    const selectedIds = Object.entries(this.selectedRoomIds)
      .filter(([, checked]) => checked)
      .map(([id]) => id);

    if (selectedIds.length === 0) {
      this.dataService.showToast(this.translate.instant('PROPERTY.MODAL.SELECT_ROOMS_REQUIRED'), 'warning');
      return;
    }

    const roomIdsToAdd = selectedIds.filter((id) => !currentIds.has(id));
    const roomIdsToRemove = [...currentIds].filter((id) => !selectedIds.includes(id));

    const payload: Record<string, unknown> = {
      id: Number(this.editingSuiteId),
      building_id: Number(this.selectedBuilding),
      floor_id: Number(this.selectedFloor),
      number: this.modalName.trim()
    };

    if (roomIdsToAdd.length) {
      payload['room_ids_to_add'] = roomIdsToAdd.map((id) => Number(id));
    }
    if (roomIdsToRemove.length) {
      payload['room_ids_to_remove'] = roomIdsToRemove.map((id) => Number(id));
    }

    this.hotelService.updateSuite(payload).subscribe({
      next: (res) => {
        if (res.success) {
          this.loadRoomsAndSuites(this.selectedBuilding, this.selectedFloor);
          this.closeAddModal();
          this.dataService.showToast(
            res.message || this.translate.instant('PROPERTY.MODAL.SUITE_UPDATED'),
            'success'
          );
        } else {
          this.dataService.showToast(
            res.message || this.translate.instant('PROPERTY.MODAL.SUITE_UPDATE_FAILED'),
            'error'
          );
        }
      },
      error: (err) => {
        this.dataService.showToast(
          err?.error?.message || this.translate.instant('PROPERTY.MODAL.SUITE_UPDATE_FAILED'),
          'error'
        );
      }
    });
  }

  private addRoom(): void {
    if (!this.roomNumber || !this.selectedBuilding || !this.selectedFloor || !this.roomTypeId) return;

    const payload = {
      building_id: this.selectedBuilding,
      floor_id: this.selectedFloor,
      number: this.roomNumber,
      capacity: this.roomCapacity,
      room_type_id: this.roomTypeId
    };

    this.hotelService.addRoom(payload).subscribe(res => {
      if (res.success) {
        this.loadRoomsAndSuites(this.selectedBuilding, this.selectedFloor);
        this.closeAddModal();
      } else {
        this.dataService.showToast(res.message || 'Error adding room', 'error');
      }
    });
  }

  private updateRoom(): void {
    if (!this.editingRoomId || !this.roomNumber || !this.selectedBuilding || !this.selectedFloor || !this.roomTypeId) {
      return;
    }

    const payload = {
      id: this.editingRoomId,
      building_id: this.selectedBuilding,
      floor_id: this.selectedFloor,
      number: this.roomNumber,
      capacity: this.roomCapacity,
      room_type_id: this.roomTypeId,
      roomStatus: this.roomStatusCode
    };

    this.hotelService.updateRoom(payload).subscribe(res => {
      if (res.success || res.result === 'success') {
        this.loadRoomsAndSuites(this.selectedBuilding, this.selectedFloor);
        this.closeAddModal();
        this.selectedRoom = null;
        this.dataService.showToast(res.message || 'Room updated successfully', 'success');
      } else {
        this.dataService.showToast(res.message || 'Error updating room', 'error');
      }
    });
  }
}
