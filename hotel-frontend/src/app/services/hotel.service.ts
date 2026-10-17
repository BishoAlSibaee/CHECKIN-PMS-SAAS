import { Injectable } from '@angular/core';
import { BehaviorSubject, Observable, of, forkJoin, throwError } from 'rxjs';
import { delay, map, tap, catchError, switchMap } from 'rxjs/operators';
import { RoomType } from '../models/room-type.interface';
import { PricingPlan } from '../models/pricing-plan.interface';
import { PeakConfig } from '../models/peak-config.interface';
import { Client } from '../models/client.interface';
import { ClientNote } from '../models/client-note.interface';
import { Booking, BookingStatus } from '../models/booking.interface';
import { Department } from '../models/department.interface';
import { JobTitle } from '../models/job-title.interface';
import { Penalty } from '../models/penalty.interface';
import { Discount } from '../models/discount.interface';
import { GuestClassification, GuestFeature, GuestClassificationFeature, ClientClassification } from '../models/guest.interface';
import { startOfDay } from 'date-fns';
import { parseApiDateOnly } from '../utils/reservation-calendar-dates';
import { HttpClient } from '@angular/common/http';
import { DataService } from './Api/data.service';
import { HotelTaxFormPayload } from '../models/hotel.interface';

export interface CollectionsSummary {
  total_balance: number;
  count: number;
  checkout_today_count: number;
  checkout_today_balance: number;
  in_house_count: number;
  overdue_count: number;
  overdue_balance: number;
  bucket_0_30: number;
  bucket_31_60: number;
  bucket_61_90: number;
  bucket_90_plus: number;
}

export interface CollectionItem {
  reservation_id: number;
  guest: string;
  client_id: number;
  room: string;
  start_date: string;
  expire_date: string;
  logedin: number;
  reservation_status: number;
  total: number;
  paid_net: number;
  balance_due: number;
  paid_percent: number;
  days_overdue: number;
  aging_bucket: string;
  urgency: string;
  guest_phone: string;
}

@Injectable({
  providedIn: 'root'
})
export class HotelService {
  private roomTypesSubject = new BehaviorSubject<RoomType[]>([]);
  private pricingPlansSubject = new BehaviorSubject<PricingPlan[]>([]);
  private peakConfigSubject = new BehaviorSubject<PeakConfig>(this.getDefaultPeakConfig());
  private clientsSubject = new BehaviorSubject<Client[]>([]);
  private bookingsSubject = new BehaviorSubject<Booking[]>([]);
  private departmentsSubject = new BehaviorSubject<Department[]>([]);
  private jobTitlesSubject = new BehaviorSubject<any[]>([]);
  private penaltiesSubject = new BehaviorSubject<any[]>([]);
  private discountsSubject = new BehaviorSubject<any[]>([]);
  private buildingsSubject = new BehaviorSubject<any[]>([]);
  private guestClassificationsSubject = new BehaviorSubject<GuestClassification[]>([]);
  private guestFeaturesSubject = new BehaviorSubject<GuestFeature[]>([]);
  private clientClassificationsSubject = new BehaviorSubject<ClientClassification[]>([]);
  private floorsCache = new Map<string, BehaviorSubject<any[]>>();
  private roomsCache = new Map<string, BehaviorSubject<any[]>>();
  private suitesCache = new Map<string, BehaviorSubject<any[]>>();

  roomTypes$ = this.roomTypesSubject.asObservable();
  pricingPlans$ = this.pricingPlansSubject.asObservable();
  peakConfig$ = this.peakConfigSubject.asObservable();
  clients$ = this.clientsSubject.asObservable();
  bookings$ = this.bookingsSubject.asObservable();
  departments$ = this.departmentsSubject.asObservable();
  jobTitles$ = this.jobTitlesSubject.asObservable();
  penalties$ = this.penaltiesSubject.asObservable();
  discounts$ = this.discountsSubject.asObservable();
  buildings$ = this.buildingsSubject.asObservable();
  guestClassifications$ = this.guestClassificationsSubject.asObservable();
  guestFeatures$ = this.guestFeaturesSubject.asObservable();
  clientClassifications$ = this.clientClassificationsSubject.asObservable();

  private loadedStates = {
    roomTypes: false,
    pricingPlans: false,
    peakConfig: false,
    clients: false,
    bookings: false,
    departments: false,
    jobTitles: false,
    penalties: false,
    discounts: false,
    buildings: false,
    guestClassifications: false,
    guestFeatures: false,
    clientClassifications: false,
    floors: new Set<string>(),
    rooms: new Set<string>(),
    suites: new Set<string>()
  };

  constructor(
    private http: HttpClient,
    private dataService: DataService
  ) {
    // No automatic refresh in constructor to optimize API calls
  }

  private failLoad(context: string, err: any): Observable<never> {
    const msg = err?.error?.message || err?.message || `Failed to load ${context}`;
    this.dataService.showToast(msg, 'error');
    return throwError(() => err);
  }

  /** Do not mark caches loaded on throttle/server errors (prevents retry loops). */
  private swallowFetchError<T>(err: any, markLoaded: () => void, fallback: T): Observable<T> {
    if (err?.status === 429 || err?.status === 503) {
      return throwError(() => err);
    }
    markLoaded();
    return of(fallback);
  }

  refreshData() {
    this.fetchRoomTypes().subscribe();
    this.fetchPricingPlans().subscribe();
    this.fetchPeakConfig().subscribe();
    this.fetchClients().subscribe();
    this.fetchBookings().subscribe();
    this.fetchDepartments().subscribe();
    this.fetchJobTitles().subscribe();
    this.fetchPenalties().subscribe();
    this.fetchDiscounts().subscribe();
  }

  fetchRoomTypes(): Observable<RoomType[]> {
    return this.http.get<any>(`${this.dataService.Url}users/getRoomType`, {
      headers: this.dataService.getHeaders()
    }).pipe(
      map(res => {
        this.loadedStates.roomTypes = true;
        if (res.success) {
          return res.data.map((rt: any) => ({
            id: rt.id.toString(),
            name: rt.name_en || rt.name_ar,
            name_ar: rt.name_ar,
            name_en: rt.name_en,
            description: rt.description || '',
            baseRate: rt.Min_daily_price,
            maxOccupancy: 2,
            amenities: [],
            color: this.getRandomColor(rt.id),
            minDailyRate: rt.Min_daily_price,
            maxDailyRate: rt.Max_daily_price,
            minMonthlyRate: rt.Min_monthly_price,
            maxMonthlyRate: rt.Max_monthly_price,
            active_type: Number(rt.active_type ?? 1),
            dynamic_pricing_enabled: !!rt.dynamic_pricing_enabled
          }));
        }
        return [];
      }),
      tap(roomTypes => this.roomTypesSubject.next(roomTypes)),
      catchError(() => {
        this.loadedStates.roomTypes = true;
        return of([]);
      })
    );
  }

  private getRandomColor(id: number): string {
    const colors = ['#64748b', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444', '#10b981', '#ec4899'];
    return colors[id % colors.length];
  }

  private resolvePricingPlanStatus(pp: {
    pricingplan_StartDate?: string;
    pricingplan_EndDate?: string;
  }): PricingPlan['status'] {
    const today = new Date().toISOString().split('T')[0];
    const start = pp.pricingplan_StartDate?.slice(0, 10) ?? '';
    const end = pp.pricingplan_EndDate?.slice(0, 10) ?? '';
    if (end && end < today) {
      return 'expired';
    }
    if (start && start > today) {
      return 'draft';
    }
    return 'active';
  }

  fetchPricingPlans(): Observable<PricingPlan[]> {
    return this.http.get<any>(`${this.dataService.Url}users/getRoomtypePricing`, {
      headers: this.dataService.getHeaders()
    }).pipe(
      map(res => {
        this.loadedStates.pricingPlans = true;
        if (res.success) {
          return res.data.map((pp: any) => ({
            id: pp.id.toString(),
            name: pp.pricingplan_nameEn || pp.pricingplan_nameAr,
            name_ar: pp.pricingplan_nameAr,
            name_en: pp.pricingplan_nameEn,
            description: `Pricing for ${pp.roomtype_nameEn || pp.roomtype_nameAr}`,
            discount: 0,
            startDate: pp.pricingplan_StartDate,
            endDate: pp.pricingplan_EndDate,
            status: this.resolvePricingPlanStatus(pp),
            linkedRoomTypes: [pp.roomtype_id.toString()],
            conditions: `Daily: ${pp.DailyPrice}, Monthly: ${pp.MonthlyPrice}`,
            pricingplan_id: pp.pricingplan_id.toString(),
            roomtype_id: pp.roomtype_id.toString(),
            dailyPrice: pp.DailyPrice,
            monthlyPrice: pp.MonthlyPrice,
            activeType: Number(pp.pricingplan_ActiveType ?? 1)
          }));
        }
        return [];
      }),
      tap(plans => this.pricingPlansSubject.next(plans)),
      catchError(() => {
        this.loadedStates.pricingPlans = true;
        return of([]);
      })
    );
  }

  fetchPeakConfig(): Observable<PeakConfig> {
    return forkJoin({
      days: this.http.get<any>(`${this.dataService.Url}users/getPeakDays`, { headers: this.dataService.getHeaders() }),
      months: this.http.get<any>(`${this.dataService.Url}users/getPeakMonths`, { headers: this.dataService.getHeaders() })
    }).pipe(
      map(res => {
        this.loadedStates.peakConfig = true;
        const peakDays = res.days.success ? res.days.data.map((d: any) => ({
          id: d.id.toString(),
          day: d.day_name_en,
          enabled: d.check === 1,
          multiplier: 1.0
        })) : [];

        const peakMonths = res.months.success ? res.months.data.map((m: any) => ({
          id: m.id.toString(),
          month: m.month_name_en,
          enabled: m.check === 1,
          multiplier: 1.0
        })) : [];

        return { peakDays, peakMonths };
      }),
      tap(config => this.peakConfigSubject.next(config)),
      catchError(() => of(this.getDefaultPeakConfig()))
    );
  }

  updatePeakDays(days: any[]): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updatePeakDaysCheck`, { days }, {
      headers: this.dataService.getHeaders()
    });
  }

  updatePeakMonths(months: any[]): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updatePeakMonthsCheck`, { months }, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchClients(search?: string, perPage: string = '1000', excludeAssigned: boolean = false): Observable<Client[]> {
    let url = `${this.dataService.Url}users/getClient`;
    const params: string[] = [];

    if (search) params.push(`search=${encodeURIComponent(search)}`);
    if (excludeAssigned) params.push(`exclude_assigned=1`);

    if (params.length > 0) {
      url += `?${params.join('&')}`;
    }

    return this.http.get<any>(url, {
      headers: {
        ...this.dataService.getHeaders(),
        'perPage': perPage
      }
    }).pipe(
      map(res => {
        if (!search) this.loadedStates.clients = true;
        if (res.success) {
          // Handle paginated response
          const clientsData = res.data?.data || res.data || [];
          return clientsData.map((c: any) => ({
            id: c.id.toString(),
            first_name: c.first_name,
            last_name: c.last_name,
            email: c.email || '',
            mobile: c.mobile || '',
            international_code: c.international_code || '',
            IdType: c.IdType,
            IdNumber: c.IdNumber,
            birth_date: c.birth_date,
            gender: c.gender,
            guest_type: c.guest_type,
            nationality: c.nationality || '',
            loyaltyPoints: Number(c.loyalty_points_balance ?? 0),
            totalBookings: 0,
            status: 'active',
            created_at: c.created_at
          }));
        }
        return [];
      }),
      tap(clients => {
        if (!search) this.clientsSubject.next(clients);
      }),
      catchError(() => of([]))
    );
  }

  fetchClientById(id: string): Observable<Client | null> {
    return this.http.get<any>(`${this.dataService.Url}users/getClientById/${id}`, {
      headers: this.dataService.getHeaders()
    }).pipe(
      map(res => {
        if (res.success) {
          const client = res.data;
          if (client) {
            const mappedClient = {
              id: client.id.toString(),
              first_name: client.first_name,
              last_name: client.last_name,
              email: client.email || '',
              mobile: client.mobile || '',
              international_code: client.international_code || '',
              IdType: client.IdType,
              IdNumber: client.IdNumber,
              birth_date: client.birth_date,
              gender: client.gender,
              guest_type: client.guest_type,
              nationality: client.nationality || '',
              loyaltyPoints: Number(client.loyalty_points_balance ?? 0),
              totalBookings: Number(client.reservations_count ?? 0),
              totalSpent: Number(client.total_spent ?? 0),
              lastVisit: client.last_visit ?? undefined,
              status: 'active',
              created_at: client.created_at
            } as Client;

            // Optionally update the clients subject if it's not already there
            const currentClients = this.getClientsSync();
            if (!currentClients.find(c => c.id === id)) {
              this.clientsSubject.next([...currentClients, mappedClient]);
            }

            return mappedClient;
          }
        }
        return null;
      }),
      catchError(() => of(null))
    );
  }

  fetchBookings(page: number = 1, perPage: number = 10, clientId?: string): Observable<any> {
    const params: any = { page };
    if (clientId) params.client_id = clientId;

    const url = clientId ? `${this.dataService.Url}users/reservations/client` : `${this.dataService.Url}users/reservations`;

    return this.http.get<any>(url, {
      headers: {
        ...this.dataService.getHeaders(),
        perPage: perPage.toString()
      },
      params
    }).pipe(
      map(res => {
        this.loadedStates.bookings = true;
        if (res.success) {
          const bookingsData = res.data?.data || res.data || [];
          const mappedBookings = bookingsData.map((b: any) => this.mapReservationRecord(b));

          if (res.per_page) {
            return {
              data: mappedBookings,
              total: res.total,
              currentPage: res.current_page,
              lastPage: res.last_page
            };
          }
          return mappedBookings;
        }
        return [];
      }),
      tap(result => {
        if (Array.isArray(result)) {
          this.bookingsSubject.next(result);
        } else if (result.data) {
          this.bookingsSubject.next(result.data);
        }
      }),
      catchError(() => of([]))
    );
  }

  private computePaidNet(payments: any[] | undefined): number {
    if (!payments?.length) return 0;
    const paid = payments
      .filter((p) => Number(p.type) === 0)
      .reduce((sum, p) => sum + Number(p.pay), 0);
    const refunded = payments
      .filter((p) => Number(p.type) !== 0)
      .reduce((sum, p) => sum + Number(p.pay), 0);
    return Math.round((paid - refunded) * 100) / 100;
  }

  private mapPaymentStatus(total: number, paidNet: number): 'pending' | 'paid' | 'partially-paid' {
    const balance = Math.max(0, total - paidNet);
    if (balance <= 0.005) return 'paid';
    if (paidNet > 0) return 'partially-paid';
    return 'pending';
  }

  /** Normalize reservation room rows from API (snake_case or camelCase). */
  extractReservationRooms(reservation: any): any[] {
    if (!reservation) return [];
    const rows = reservation.reservation_rooms ?? reservation.reservationRooms ?? [];
    return Array.isArray(rows) ? rows : [];
  }

  formatRoomNumbers(reservation: any, fallback = '—'): string {
    const numbers = this.extractReservationRooms(reservation)
      .map((row) => row?.room?.number)
      .filter((n) => n !== null && n !== undefined && String(n).trim() !== '');
    return numbers.length ? numbers.map(String).join(', ') : fallback;
  }

  localizeRoomTypeName(roomType: any, lang = 'en'): string {
    if (!roomType) return '';
    if (lang === 'ar') {
      return roomType.name_ar || roomType.name_en || roomType.name || '';
    }
    return roomType.name_en || roomType.name_ar || roomType.name || '';
  }

  private mapReservationRecord(b: any): Booking {
    const room = this.extractReservationRooms(b)[0]?.room;
    const total = Number(b.total);
    const paidNet = this.computePaidNet(b.payments);
    const balanceDue = Math.max(0, Math.round((total - paidNet) * 100) / 100);
    return {
      id: b.id.toString(),
      guest: b.client ? `${b.client.first_name ?? ''} ${b.client.last_name ?? ''}`.trim() : 'Unknown',
      room: this.formatRoomNumbers(b),
      checkIn: b.start_date,
      checkOut: b.expire_date,
      status: this.mapReservationStatus(b.reservation_status, b.logedin, b.expire_date),
      amount: total,
      paidAmount: paidNet,
      balanceDue,
      phone: b.client?.mobile || '',
      email: b.client?.email || '',
      startDate: parseApiDateOnly(b.start_date),
      endDate: parseApiDateOnly(b.expire_date),
      clientId: b.client_id?.toString(),
      roomTypeId: room?.room_type_id?.toString() || '',
      paymentStatus: this.mapPaymentStatus(total, paidNet),
      paymentMethod: 'Other',
      roomPrice: b.base_price,
      tax: b.taxes,
      discount: b.discount,
      totalDays: b.nights,
      payments: b.payments?.map((p: any) => ({
        id: p.id.toString(),
        amount: p.pay,
        date: p.created_at,
        method: p.type === 0 ? 'Cash' : 'Credit Card',
        note: ''
      })) || []
    };
  }

  private mapReservationStatus(status: number, logedin?: number, expireDate?: string): BookingStatus {
    if (status === 3) return 'cancelled';
    if (status === 0) return 'unconfirmed';
    if (status === 2) return 'pending-payment';
    if (status === 1 && logedin === 1) return 'checked-in';
    if (status === 1 && logedin === 0 && expireDate) {
      const expire = parseApiDateOnly(expireDate);
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      if (expire && expire < today) return 'checked-out';
    }
    return 'confirmed';
  }

  getRoomTypes(): Observable<RoomType[]> {
    return this.roomTypes$;
  }

  getRoomTypesSync(): RoomType[] {
    return this.roomTypesSubject.value;
  }

  isRoomTypesLoaded(): boolean {
    return this.loadedStates.roomTypes;
  }

  setRoomTypes(roomTypes: RoomType[]) {
    this.roomTypesSubject.next(roomTypes);
  }

  getPricingPlans(): Observable<PricingPlan[]> {
    return this.pricingPlans$;
  }

  getPricingPlansSync(): PricingPlan[] {
    return this.pricingPlansSubject.value;
  }

  isPricingPlansLoaded(): boolean {
    return this.loadedStates.pricingPlans;
  }

  setPricingPlans(pricingPlans: PricingPlan[]) {
    this.pricingPlansSubject.next(pricingPlans);
  }

  getPeakConfig(): Observable<PeakConfig> {
    return this.peakConfig$;
  }

  getPeakConfigSync(): PeakConfig {
    return this.peakConfigSubject.value;
  }

  isPeakConfigLoaded(): boolean {
    return this.loadedStates.peakConfig;
  }

  setPeakConfig(peakConfig: PeakConfig) {
    this.peakConfigSubject.next(peakConfig);
  }

  getClients(): Observable<Client[]> {
    return this.clients$;
  }

  getClientsSync(): Client[] {
    return this.clientsSubject.value;
  }

  isClientsLoaded(): boolean {
    return this.loadedStates.clients;
  }

  setClients(clients: Client[]) {
    this.clientsSubject.next(clients);
  }

  addClient(client: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addClient`, client, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchClients().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  updateClient(client: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateClient`, client, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchClients().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  getStayReasons(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/getStayReasons`, {
      headers: this.dataService.getHeaders()
    });
  }

  getReservationSources(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/getReservationSource`, {
      headers: this.dataService.getHeaders()
    });
  }

  getAvailableRooms(params?: any): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/rooms`, {
      headers: this.dataService.getHeaders(),
      params
    });
  }

  checkAvailableRooms(params: {
    building_id: number;
    room_type: number;
    start_date: string;
    expire_date: string;
    floor_id?: number;
    suite_id?: number;
  }): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/checkReservation`, {
      headers: this.dataService.getHeaders(),
      params: { ...params, type_search: 1 }
    });
  }

  getBookingRoomAvailability(params: {
    building_id: number;
    start_date: string;
    expire_date: string;
    floor_id?: number;
    room_type_id?: number;
    search?: string;
  }): Observable<any> {
    const httpParams: Record<string, string> = {
      building_id: String(params.building_id),
      start_date: params.start_date,
      expire_date: params.expire_date
    };
    if (params.floor_id != null) {
      httpParams['floor_id'] = String(params.floor_id);
    }
    if (params.room_type_id != null) {
      httpParams['room_type_id'] = String(params.room_type_id);
    }
    if (params.search?.trim()) {
      httpParams['search'] = params.search.trim();
    }
    return this.http.get<any>(`${this.dataService.Url}users/booking-room-availability`, {
      headers: this.dataService.getHeaders(),
      params: httpParams
    });
  }

  getRoomPriceCalculation(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/getRoomPrice`, data, {
      headers: this.dataService.getHeaders()
    });
  }

  makeReservation(data: any): Observable<any> {
    const payload = {
      price_calculation_mode: data?.price_calculation_mode ?? 0,
      ...data
    };
    return this.http.post<any>(`${this.dataService.Url}users/makeReservation`, payload, {
      headers: this.dataService.getHeaders()
    });
  }

  /** ID from makeReservation — supports legacy `data.id` and detail `data.reservation.id`. */
  extractCreatedReservationId(res: { data?: { id?: number | string; reservation?: { id?: number | string } } }): string | null {
    const raw = res?.data?.reservation?.id ?? res?.data?.id;
    if (raw === null || raw === undefined || raw === '') {
      return null;
    }
    return String(raw);
  }

  getReservation(id: string | number): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/reservations/${id}`, {
      headers: this.dataService.getHeaders()
    });
  }

  getReservationFolio(id: string | number): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/reservations/${id}/folio`, {
      headers: this.dataService.getHeaders()
    });
  }

  previewDateChange(
    reservationId: string | number,
    start_date: string,
    expire_date: string
  ): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/reservations/${reservationId}/date-change-preview`, {
      headers: this.dataService.getHeaders(),
      params: { start_date, expire_date }
    });
  }

  updateReservation(id: string | number, data: Record<string, unknown>): Observable<any> {
    return this.http.patch<any>(`${this.dataService.Url}users/reservations/${id}`, data, {
      headers: this.dataService.getHeaders()
    });
  }

  addReservationPayment(id: string | number, pay: number, type: 0 | 1 = 0): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/reservations/${id}/payments`, { pay, type }, {
      headers: this.dataService.getHeaders()
    });
  }

  redeemLoyaltyPoints(id: string | number, points: number): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/reservations/${id}/redeem-points`, { points }, {
      headers: this.dataService.getHeaders()
    });
  }

  // Hotels (branches) — admin-only, low-traffic, so no BehaviorSubject cache
  // layer like departments/discounts; components just call these directly.
  fetchHotels(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/getHotels`, {
      headers: this.dataService.getHeaders()
    });
  }

  addHotel(data: { name_ar: string; name_en: string } & HotelTaxFormPayload): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addHotel`, data, {
      headers: this.dataService.getHeaders()
    });
  }

  updateHotel(id: string | number, data: Partial<{ name_ar: string; name_en: string; is_active: boolean } & HotelTaxFormPayload>): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateHotel/${id}`, data, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchHotelUsers(id: string | number): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/hotels/${id}/users`, {
      headers: this.dataService.getHeaders()
    });
  }

  assignHotelUser(id: string | number, userId: string | number): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/hotels/${id}/users`, { user_id: userId }, {
      headers: this.dataService.getHeaders()
    });
  }

  removeHotelUser(id: string | number, userId: string | number): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/hotels/${id}/users/${userId}`, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchNightAuditRuns(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/night-audit/runs`, {
      headers: this.dataService.getHeaders()
    });
  }

  runNightAudit(date?: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/night-audit/run`, date ? { date } : {}, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchOtaAllocations(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/channex/ota-allocations`, {
      headers: this.dataService.getHeaders()
    });
  }

  updateOtaAllocation(roomTypeId: string | number, allocatedRooms: number): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/channex/ota-allocations`,
      { room_type_id: roomTypeId, allocated_rooms: allocatedRooms }, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchPlatformLedger(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/channex/platform-ledger`, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchPlatformStatement(reservationSourceId: string | number, startDate?: string, endDate?: string): Observable<any> {
    let params = '';
    if (startDate && endDate) {
      params = `?start_date=${startDate}&end_date=${endDate}`;
    }
    return this.http.get<any>(`${this.dataService.Url}users/channex/platform-ledger/${reservationSourceId}${params}`, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchOtaRatePlans(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/channex/rate-plans`, {
      headers: this.dataService.getHeaders()
    });
  }

  createOtaRatePlan(payload: {
    room_type_id: string | number;
    name_ar: string;
    name_en: string;
    price_adjustment_percent: number;
    is_refundable: boolean;
  }): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/channex/rate-plans`, payload, {
      headers: this.dataService.getHeaders()
    });
  }

  updateOtaRatePlan(id: string | number, payload: {
    name_ar?: string;
    name_en?: string;
    price_adjustment_percent?: number;
    is_refundable?: boolean;
    is_active?: boolean;
  }): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/channex/rate-plans/${id}`, payload, {
      headers: this.dataService.getHeaders()
    });
  }

  deleteOtaRatePlan(id: string | number): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/channex/rate-plans/${id}`, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchChannexConnectionStatus(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/channex/connection`, {
      headers: this.dataService.getHeaders()
    });
  }

  syncChannexNow(): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/channex/connection/sync`, {}, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchAirbnbSettings(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/channex/airbnb-settings`, {
      headers: this.dataService.getHeaders()
    });
  }

  updateAirbnbSettings(payload: {
    mode: string;
    reminder_interval_minutes?: number;
    fallback_action?: string;
    decline_reason?: string;
  }): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/channex/airbnb-settings`, payload, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchAirbnbRequests(pendingOnly: boolean = true): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/channex/airbnb-requests?pending_only=${pendingOnly ? '1' : '0'}`, {
      headers: this.dataService.getHeaders()
    });
  }

  acceptAirbnbRequest(id: string | number): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/channex/airbnb-requests/${id}/accept`, {}, {
      headers: this.dataService.getHeaders()
    });
  }

  declineAirbnbRequest(id: string | number, reason: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/channex/airbnb-requests/${id}/decline`, { reason }, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchNotifications(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/notifications`, {
      headers: this.dataService.getHeaders()
    });
  }

  markNotificationsSeen(): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/notifications/seen`, {}, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchChannexReviews(page: number = 1, limit: number = 20): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/channex/reviews?page=${page}&limit=${limit}`, {
      headers: this.dataService.getHeaders()
    });
  }

  replyToChannexReview(reviewId: string, reply: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/channex/reviews/${reviewId}/reply`, { reply }, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchChannexReviewScores(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/channex/reviews-scores`, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchChannexMessageThreads(page: number = 1, limit: number = 20): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/channex/messages?page=${page}&limit=${limit}`, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchChannexThreadMessages(threadId: string): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/channex/messages/${threadId}`, {
      headers: this.dataService.getHeaders()
    });
  }

  replyToChannexThread(threadId: string, message: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/channex/messages/${threadId}/reply`, { message }, {
      headers: this.dataService.getHeaders()
    });
  }

  markChannexThreadNoReplyNeeded(threadId: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/channex/messages/${threadId}/no-reply-needed`, {}, {
      headers: this.dataService.getHeaders()
    });
  }

  closeChannexThread(threadId: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/channex/messages/${threadId}/close`, {}, {
      headers: this.dataService.getHeaders()
    });
  }

  cancelReservation(id: string | number): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/reservations/${id}/cancel`, {}, {
      headers: this.dataService.getHeaders()
    });
  }

  cancelReservationRoom(reservationId: string | number, reservationRoomId: string | number): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/reservations/${reservationId}/rooms/${reservationRoomId}/cancel`, {}, {
      headers: this.dataService.getHeaders()
    });
  }

  extendReservation(id: string | number, expire_date: string): Observable<any> {
    return this.http.patch<any>(`${this.dataService.Url}users/reservations/${id}/extend`, { expire_date }, {
      headers: this.dataService.getHeaders()
    });
  }

  fetchReservationCalendar(dateFrom: string, dateTo: string): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/reservations/calendar`, {
      headers: this.dataService.getHeaders(),
      params: { date_from: dateFrom, date_to: dateTo }
    });
  }

  fetchReservationsFiltered(params?: {
    page?: number;
    perPage?: number;
    reservation_status?: number;
    date_from?: string;
    date_to?: string;
    search?: string;
    room_number?: string;
    has_balance_due?: boolean;
    sort?: string;
    pending_checkout?: boolean;
    checkout_today?: boolean;
  }): Observable<any> {
    const query: Record<string, string> = {};
    if (params?.page) query['page'] = String(params.page);
    if (params?.reservation_status != null) query['reservation_status'] = String(params.reservation_status);
    if (params?.date_from) query['date_from'] = params.date_from;
    if (params?.date_to) query['date_to'] = params.date_to;
    if (params?.search) query['search'] = params.search;
    if (params?.room_number) query['room_number'] = params.room_number;
    if (params?.has_balance_due) query['has_balance_due'] = '1';
    if (params?.sort) query['sort'] = params.sort;
    if (params?.pending_checkout) query['pending_checkout'] = '1';
    if (params?.checkout_today) query['checkout_today'] = '1';

    return this.http.get<any>(`${this.dataService.Url}users/reservations`, {
      headers: { ...this.dataService.getHeaders(), perPage: String(params?.perPage ?? 15) },
      params: query
    }).pipe(
      map(res => {
        if (!res?.success) {
          return { data: [], total: 0, currentPage: 1, lastPage: 1 };
        }
        const bookingsData = res.data?.data || res.data || [];
        const mappedBookings = Array.isArray(bookingsData)
          ? bookingsData.map((b: any) => this.mapReservationRecord(b))
          : [];
        if (res.per_page) {
          return {
            data: mappedBookings,
            total: res.total ?? mappedBookings.length,
            currentPage: res.current_page ?? 1,
            lastPage: res.last_page ?? 1
          };
        }
        return {
          data: mappedBookings,
          total: mappedBookings.length,
          currentPage: 1,
          lastPage: 1
        };
      }),
      catchError(err => this.failLoad('reservations', err))
    );
  }

  fetchRoomHistory(roomNumber: string, page = 1, perPage = 100): Observable<{ data: Booking[]; total: number; currentPage: number; lastPage: number }> {
    return this.fetchReservationsFiltered({ room_number: roomNumber, page, perPage });
  }

  // Buildings
  fetchBuildings(page: number = 1): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/buildings`, {
      headers: this.dataService.getHeaders(),
      params: { page }
    }).pipe(
      tap(res => {
        this.loadedStates.buildings = true;
        const buildingsData = res.data?.data || res.data || [];
        this.buildingsSubject.next(buildingsData);
      }),
      catchError((err) => this.swallowFetchError(err, () => {
        this.loadedStates.buildings = true;
      }, { success: false, data: [] })),
    );
  }

  getBuildings(page: number = 1): Observable<any> {
    return this.buildings$;
  }

  getBuildingsSync(): any[] {
    return this.buildingsSubject.value;
  }

  isBuildingsLoaded(): boolean {
    return this.loadedStates.buildings;
  }

  addBuilding(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/building`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchBuildings().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  // Pricing
  addRoomTypePricing(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addRoomtypePricing`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchPricingPlans().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  addRoomTypePricingPlan(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addRoomtypePricingPlan`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchPricingPlans().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  updateRoomTypePricing(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateRoomtypePricing`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchPricingPlans().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  deleteRoomTypePricing(id: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/deleteRoomtypePricing`, { id }, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchPricingPlans().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  addRoomType(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addRoomType`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchRoomTypes().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  updateRoomType(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateRoomType`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchRoomTypes().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  deleteRoomType(id: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/deleteRoomType`, { id }, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchRoomTypes().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  fetchDynamicPricingRules(roomTypeId: string | number): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/room-types/${roomTypeId}/dynamic-pricing-rules`, {
      headers: this.dataService.getHeaders()
    });
  }

  addDynamicPricingRule(roomTypeId: string | number, data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/room-types/${roomTypeId}/dynamic-pricing-rules`, data, {
      headers: this.dataService.getHeaders()
    });
  }

  deleteDynamicPricingRule(id: number): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/dynamic-pricing-rules/${id}`, {
      headers: this.dataService.getHeaders()
    });
  }

  updateBuilding(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateBuilding`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchBuildings().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  deleteBuilding(id: string): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/building`, {
      headers: this.dataService.getHeaders(),
      body: { id_building: id }
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchBuildings().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  // Floors
  private getFloorsSubject(buildingId: string): BehaviorSubject<any[]> {
    if (!this.floorsCache.has(buildingId)) {
      this.floorsCache.set(buildingId, new BehaviorSubject<any[]>([]));
    }
    return this.floorsCache.get(buildingId)!;
  }

  fetchFloors(buildingId: string): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/floors`, {
      headers: this.dataService.getHeaders(),
      params: { building_id: buildingId }
    }).pipe(
      tap(res => {
        this.loadedStates.floors.add(buildingId);
        const floorsData = res.data || [];
        this.getFloorsSubject(buildingId).next(floorsData);
      }),
      catchError((err) => this.swallowFetchError(err, () => {
        this.loadedStates.floors.add(buildingId);
      }, { success: false, data: [] })),
    );
  }

  getFloors(buildingId: string): Observable<any> {
    return this.getFloorsSubject(buildingId).asObservable();
  }

  getFloorsSync(buildingId: string): any[] {
    return this.getFloorsSubject(buildingId).value;
  }

  isFloorsLoaded(buildingId: string): boolean {
    return this.loadedStates.floors.has(buildingId);
  }

  addFloor(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addFloor`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchFloors(data.building_id).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  updateFloor(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateFloor`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchFloors(data.building_id).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  deleteFloor(id: string, buildingId: string): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/deleteFloor`, {
      headers: this.dataService.getHeaders(),
      body: { id_floor: id }
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchFloors(buildingId).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  // Rooms
  private getRoomsKey(params: any): string {
    return `${params.building_id}_${params.floor_id}`;
  }

  private getRoomsSubject(params: any): BehaviorSubject<any[]> {
    const key = this.getRoomsKey(params);
    if (!this.roomsCache.has(key)) {
      this.roomsCache.set(key, new BehaviorSubject<any[]>([]));
    }
    return this.roomsCache.get(key)!;
  }

  fetchRooms(params: any): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/rooms`, {
      headers: this.dataService.getHeaders(),
      params
    }).pipe(
      tap(res => {
        const key = this.getRoomsKey(params);
        this.loadedStates.rooms.add(key);
        const roomsData = res.data?.data || res.data || [];
        this.getRoomsSubject(params).next(roomsData);
      }),
      catchError((err) => this.swallowFetchError(err, () => {
        const key = this.getRoomsKey(params);
        this.loadedStates.rooms.add(key);
      }, { success: false, data: [] })),
    );
  }

  getRooms(params: any): Observable<any> {
    return this.getRoomsSubject(params).asObservable();
  }

  getRoomsSync(params: any): any[] {
    return this.getRoomsSubject(params).value;
  }

  isRoomsLoaded(params: any): boolean {
    return this.loadedStates.rooms.has(this.getRoomsKey(params));
  }

  addRoom(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addRoom`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchRooms({ building_id: data.building_id, floor_id: data.floor_id }).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  addMultiRoom(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addMultiRoom`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchRooms({ building_id: data.building_id, floor_id: data.floor_id }).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  updateRoom(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateRoom`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchRooms({ building_id: data.building_id, floor_id: data.floor_id }).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  deleteRoom(id: string, buildingId: string, floorId: string): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/deleteRoom`, {
      headers: this.dataService.getHeaders(),
      body: { id }
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchRooms({ building_id: buildingId, floor_id: floorId }).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  // Suites
  private getSuitesKey(params: any): string {
    return `${params.building_id}_${params.floor_id}`;
  }

  private getSuitesSubject(params: any): BehaviorSubject<any[]> {
    const key = this.getSuitesKey(params);
    if (!this.suitesCache.has(key)) {
      this.suitesCache.set(key, new BehaviorSubject<any[]>([]));
    }
    return this.suitesCache.get(key)!;
  }

  fetchSuites(params: any): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/suites`, {
      headers: this.dataService.getHeaders(),
      params
    }).pipe(
      tap(res => {
        const key = this.getSuitesKey(params);
        this.loadedStates.suites.add(key);
        const suitesData = res.data?.data || res.data || [];
        this.getSuitesSubject(params).next(suitesData);
      }),
      catchError((err) => this.swallowFetchError(err, () => {
        const key = this.getSuitesKey(params);
        this.loadedStates.suites.add(key);
      }, { success: false, data: [] })),
    );
  }

  getSuites(params: any): Observable<any> {
    return this.getSuitesSubject(params).asObservable();
  }

  getSuitesSync(params: any): any[] {
    return this.getSuitesSubject(params).value;
  }

  isSuitesLoaded(params: any): boolean {
    return this.loadedStates.suites.has(this.getSuitesKey(params));
  }

  private refreshRoomsAndSuites(params: any): Observable<any[]> {
    return forkJoin([
      this.fetchSuites(params),
      this.fetchRooms(params),
    ]);
  }

  addSuite(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addSuite`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.refreshRoomsAndSuites({ building_id: data.building_id, floor_id: data.floor_id }).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  updateSuite(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateSuite`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.refreshRoomsAndSuites({ building_id: data.building_id, floor_id: data.floor_id }).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  deleteSuite(id: string, buildingId: string, floorId: string, deleteRooms = false): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/deleteSuite`, {
      headers: this.dataService.getHeaders(),
      body: { id_suite: id, delete_rooms: deleteRooms }
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.refreshRoomsAndSuites({ building_id: buildingId, floor_id: floorId }).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  // Job Titles
  fetchJobTitles(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/getJobTitle`, {
      headers: this.dataService.getHeaders()
    }).pipe(
      tap(res => {
        this.loadedStates.jobTitles = true;
        if (res.success) {
          this.jobTitlesSubject.next(res.data?.data || res.data || []);
        }
      }),
      catchError(() => {
        this.loadedStates.jobTitles = true;
        return of([]);
      })
    );
  }

  getJobTitles(): Observable<any> {
    return this.jobTitles$;
  }

  getJobTitlesSync(): JobTitle[] {
    return this.jobTitlesSubject.value;
  }

  isJobTitlesLoaded(): boolean {
    return this.loadedStates.jobTitles;
  }

  addJobTitle(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addJobTitle`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchJobTitles().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  updateJobTitle(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateJobTitle`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchJobTitles().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  deleteJobTitle(id: string): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/deleteJobTitle`, {
      headers: this.dataService.getHeaders(),
      body: { id }
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchJobTitles().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  // Penalties
  fetchPenalties(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/getPenalties`, {
      headers: this.dataService.getHeaders()
    }).pipe(
      tap(res => {
        this.loadedStates.penalties = true;
        if (res.success) {
          this.penaltiesSubject.next(res.data?.data || res.data || []);
        }
      }),
      catchError(() => {
        this.loadedStates.penalties = true;
        return of([]);
      })
    );
  }

  getPenalties(): Observable<any> {
    return this.penalties$;
  }

  getPenaltiesSync(): Penalty[] {
    return this.penaltiesSubject.value;
  }

  isPenaltiesLoaded(): boolean {
    return this.loadedStates.penalties;
  }

  addPenalty(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addPenaltie`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchPenalties().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  updatePenalty(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updatePenaltie`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchPenalties().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  deletePenalty(id: string): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/deletePenaltie`, {
      headers: this.dataService.getHeaders(),
      body: { id }
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchPenalties().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  // Departments
  fetchDepartments(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/getDepartment`, {
      headers: this.dataService.getHeaders()
    }).pipe(
      tap(res => {
        this.loadedStates.departments = true;
        if (res.success) {
          this.departmentsSubject.next(res.data?.data || res.data || []);
        }
      }),
      catchError(() => {
        this.loadedStates.departments = true;
        return of([]);
      })
    );
  }

  getDepartments(): Observable<any> {
    return this.departments$;
  }

  getDepartmentsSync(): Department[] {
    return this.departmentsSubject.value;
  }

  isDepartmentsLoaded(): boolean {
    return this.loadedStates.departments;
  }

  addDepartment(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addDepartment`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchDepartments().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  updateDepartment(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateDepartment`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchDepartments().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  deleteDepartment(id: string): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/deleteDepartment`, {
      headers: this.dataService.getHeaders(),
      body: { id }
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchDepartments().pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  // Discounts
  fetchDiscounts(page: number = 1, perPage: number = 10): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/getDiscounts`, {
      headers: {
        ...this.dataService.getHeaders(),
        'perPage': String(perPage)
      },
      params: { page }
    }).pipe(
      tap(res => {
        this.loadedStates.discounts = true;
        if (res.success) {
          this.discountsSubject.next(res.data?.data || res.data || []);
        }
      }),
      catchError(() => {
        this.loadedStates.discounts = true;
        return of([]);
      })
    );
  }

  getDiscounts(): Observable<any> {
    return this.discounts$;
  }

  getDiscountsSync(): Discount[] {
    return this.discountsSubject.value;
  }

  isDiscountsLoaded(): boolean {
    return this.loadedStates.discounts;
  }

  addDiscount(data: any, refreshPage: number = 1, perPage: number = 10): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addDiscount`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchDiscounts(refreshPage, perPage).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  updateDiscount(data: any, refreshPage: number = 1, perPage: number = 10): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateDiscount`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchDiscounts(refreshPage, perPage).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  deleteDiscount(id: string, refreshPage: number = 1, perPage: number = 10): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/deleteDiscount`, {
      headers: this.dataService.getHeaders(),
      body: { id }
    }).pipe(
      switchMap(res => {
        if (res.success) {
          return this.fetchDiscounts(refreshPage, perPage).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  fetchClientBy(mobile?: string, email?: string, idNumber?: string): Observable<Client | null> {
    let params: any = {};
    if (mobile) params.mobile = mobile;
    if (email) params.email = email;
    if (idNumber) params.IdNumber = idNumber;

    return this.http.get<any>(`${this.dataService.Url}users/getClientBy`, {
      headers: this.dataService.getHeaders(),
      params
    }).pipe(
      map(res => {
        if (res.success) {
          const c = res.data;
          return {
            id: c.id.toString(),
            first_name: c.first_name,
            last_name: c.last_name,
            email: c.email || '',
            mobile: c.mobile || '',
            international_code: c.international_code || '',
            IdType: c.IdType,
            IdNumber: c.IdNumber,
            birth_date: c.birth_date,
            gender: c.gender,
            guest_type: c.guest_type,
            nationality: c.nationality || '',
            loyaltyPoints: Number(c.loyalty_points_balance ?? 0),
            totalBookings: 0,
            status: 'active',
            created_at: c.created_at
          } as Client;
        }
        return null;
      }),
      catchError(() => of(null))
    );
  }

  fetchClientNotes(clientId: string): Observable<ClientNote[]> {
    return this.http.get<any>(`${this.dataService.Url}users/client-notes`, {
      headers: this.dataService.getHeaders(),
      params: { client_id: clientId }
    }).pipe(
      map(res => res.success ? res.data : []),
      catchError(() => of([]))
    );
  }

  addClientNote(note: Partial<ClientNote>): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/client-notes`, note, {
      headers: this.dataService.getHeaders()
    });
  }

  updateClientNote(note: Partial<ClientNote>): Observable<any> {
    return this.http.put<any>(`${this.dataService.Url}users/client-notes`, note, {
      headers: this.dataService.getHeaders()
    });
  }

  deleteClientNote(id: string): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/client-notes`, {
      headers: this.dataService.getHeaders(),
      body: { id }
    });
  }

  getClientById(id: string): Client | undefined {
    return this.getClientsSync().find(c => c.id === id);
  }

  getBookings(): Observable<Booking[]> {
    return this.bookings$;
  }

  getBookingsSync(): Booking[] {
    return this.bookingsSubject.value;
  }

  isBookingsLoaded(): boolean {
    return this.loadedStates.bookings;
  }

  setBookings(bookings: Booking[]) {
    this.bookingsSubject.next(bookings);
  }

  getBookingById(id: string): Booking | undefined {
    return this.getBookingsSync().find(b => b.id === id);
  }

  getRoomTypeName(id: string): string {
    return this.getRoomTypesSync().find(rt => rt.id === id)?.name || 'Unknown';
  }

  getRoomTypeById(id: string): RoomType | undefined {
    return this.getRoomTypesSync().find(rt => rt.id === id);
  }

  getPlansForRoomType(roomTypeId: string): PricingPlan[] {
    return this.getPricingPlansSync().filter(p => p.status === 'active' && p.linkedRoomTypes.includes(roomTypeId));
  }

  getEffectiveRate(roomTypeId: string): { base: number; best: number; planName?: string } {
    const rt = this.getRoomTypeById(roomTypeId);
    if (!rt) return { base: 0, best: 0 };
    const plans = this.getPlansForRoomType(roomTypeId);
    if (plans.length === 0) return { base: rt.baseRate, best: rt.baseRate };
    const bestPlan = plans.reduce((best, p) => p.discount > best.discount ? p : best, plans[0]);
    const best = Math.round(rt.baseRate * (1 - bestPlan.discount / 100));
    return { base: rt.baseRate, best, planName: bestPlan.name };
  }


  private getDefaultBookings(): Booking[] {
    return [];
  }

  getBookingsByClientId(clientId: string): Booking[] {
    return this.getBookingsSync().filter(b => b.clientId === clientId);
  }

  private getDefaultRoomTypes(): RoomType[] {
    return [];
  }

  private getDefaultPricingPlans(): PricingPlan[] {
    return [];
  }

  private getDefaultPeakConfig(): PeakConfig {
    const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    return {
      peakDays: DAYS.map((d, index) => ({
        id: `day-${index}`,
        day: d,
        enabled: false,
        multiplier: 1.0,
      })),
      peakMonths: MONTHS.map((m, index) => ({
        id: `month-${index}`,
        month: m,
        enabled: false,
        multiplier: 1.0,
      })),
    };
  }

  private getDefaultClients(): Client[] {
    return [];
  }

  // Guest Classification
  fetchGuestClassifications(page: number = 1, perPage: number = 10): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/getGuestClassification`, {
      headers: {
        ...this.dataService.getHeaders(),
        'X-Per-Page': perPage.toString()
      },
      params: { page: page.toString() }
    }).pipe(
      map(res => {
        this.loadedStates.guestClassifications = true;
        const data = res.data?.data || res.data || [];
        if (res.success || res.result === 'success') {
          const mapped = data.map((gc: any) => ({
            id: gc.id.toString(),
            name: gc.name_en || gc.name_ar,
            name_ar: gc.name_ar,
            name_en: gc.name_en,
            description: gc.description,
            color: gc.color || this.getRandomColor(gc.id),
            discount_id: gc.discount_id?.toString(),
            active: gc.active
          }));

          if (res.per_page) {
            return {
              data: mapped,
              total: res.total,
              currentPage: res.current_page,
              lastPage: res.last_page
            };
          }
          return mapped;
        }
        return [];
      }),
      tap(result => {
        if (Array.isArray(result)) {
          this.guestClassificationsSubject.next(result);
        } else if (result.data) {
          this.guestClassificationsSubject.next(result.data);
        }
      }),
      catchError(() => {
        this.loadedStates.guestClassifications = true;
        return of([]);
      })
    );
  }

  getGuestClassifications(): Observable<GuestClassification[]> {
    return this.guestClassifications$;
  }

  getGuestClassificationsSync(): GuestClassification[] {
    return this.guestClassificationsSubject.value;
  }

  addGuestClassification(data: any, refreshPage: number = 1, perPage: number = 10): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addGuestClassification`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchGuestClassifications(refreshPage, perPage).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  updateGuestClassification(data: any, refreshPage: number = 1, perPage: number = 10): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateGuestClassification`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchGuestClassifications(refreshPage, perPage).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  deleteGuestClassification(id: string, refreshPage: number = 1, perPage: number = 10): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/deleteGuestClassification`, { id }, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchGuestClassifications(refreshPage, perPage).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  // Guest Feature
  fetchGuestFeatures(page: number = 1, perPage: number = 10): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/getGuestFeature`, {
      headers: {
        ...this.dataService.getHeaders(),
        'X-Per-Page': perPage.toString()
      },
      params: { page: page.toString() }
    }).pipe(
      map(res => {
        this.loadedStates.guestFeatures = true;
        const data = res.data?.data || res.data || [];
        if (res.success || res.result === 'success') {
          const mapped = data.map((gf: any) => ({
            id: gf.id.toString(),
            name: gf.name_en || gf.name_ar,
            name_ar: gf.name_ar,
            name_en: gf.name_en,
            feature_description: gf.feature_description,
            description: gf.feature_description,
            icon: gf.icon || 'bi-star'
          }));

          if (res.per_page) {
            return {
              data: mapped,
              total: res.total,
              currentPage: res.current_page,
              lastPage: res.last_page
            };
          }
          return mapped;
        }
        return [];
      }),
      tap(result => {
        if (Array.isArray(result)) {
          this.guestFeaturesSubject.next(result);
        } else if (result.data) {
          this.guestFeaturesSubject.next(result.data);
        }
      }),
      catchError(() => {
        this.loadedStates.guestFeatures = true;
        return of([]);
      })
    );
  }

  getGuestFeatures(): Observable<GuestFeature[]> {
    return this.guestFeatures$;
  }

  getGuestFeaturesSync(): GuestFeature[] {
    return this.guestFeaturesSubject.value;
  }

  addGuestFeature(data: any, refreshPage: number = 1, perPage: number = 10): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addGuestFeature`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchGuestFeatures(refreshPage, perPage).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  updateGuestFeature(data: any, refreshPage: number = 1, perPage: number = 10): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/updateGuestFeature`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchGuestFeatures(refreshPage, perPage).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  deleteGuestFeature(id: string, refreshPage: number = 1, perPage: number = 10): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/deleteGuestFeature`, { id }, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchGuestFeatures(refreshPage, perPage).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  // Guest Classification Feature
  getGuestClassificationFeatures(classificationId: string): Observable<GuestClassificationFeature[]> {
    return this.http.get<any>(`${this.dataService.Url}users/getGuestClassificationFeatureByClassification`, {
      headers: this.dataService.getHeaders(),
      params: { guest_classification_id: classificationId }
    }).pipe(
      map(res => {
        const data = res.data?.data || res.data || [];
        if (res.success || res.result === 'success') {
          return data.map((item: any) => ({
            id: item.id.toString(),
            guest_classification_id: item.guest_classification_id.toString(),
            guest_feature_id: item.guest_feature_id.toString(),
            feature: item.guest_feature ? {
              id: item.guest_feature.id.toString(),
              name: item.guest_feature.name_en || item.guest_feature.name_ar,
              name_en: item.guest_feature.name_en,
              name_ar: item.guest_feature.name_ar,
              icon: item.guest_feature.icon || 'bi-star'
            } : null
          }));
        }
        return [];
      }),
      catchError(() => of([]))
    );
  }

  addGuestClassificationFeature(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/addGuestClassificationFeature`, data, {
      headers: this.dataService.getHeaders()
    });
  }

  deleteGuestClassificationFeature(id: string): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/deleteGuestClassificationFeature`, { id }, {
      headers: this.dataService.getHeaders()
    });
  }

  // Client Classification
  fetchClientClassifications(page: number = 1, perPage: number = 10): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/getAllClientsWithClassification`, {
      headers: {
        ...this.dataService.getHeaders(),
        'X-Per-Page': perPage.toString()
      },
      params: { page: page.toString() }
    }).pipe(
      map(res => {
        this.loadedStates.clientClassifications = true;
        const data = res.data?.data || res.data || [];
        if (res.success || res.result === 'success') {
          const mapped = data.map((item: any) => {
            const guestClassification = item.guest_classification || item.guestClassification;
            const clientDetails = item.client_details || item.client;

            return {
              id: item.id?.toString() || '',
              client_id: item.client_id?.toString() || '',
              classifications_id: item.classifications_id?.toString() || '',
              created_at: item.created_at || item.updated_at || null,
              client: clientDetails ? {
                id: clientDetails.id?.toString() || '',
                first_name: clientDetails.first_name || '',
                last_name: clientDetails.last_name || '',
                mobile: clientDetails.mobile || '',
                email: clientDetails.email || ''
              } : null,
              classification: guestClassification ? {
                id: guestClassification.id?.toString() || '',
                name_en: guestClassification.name_en || 'Unknown',
                name_ar: guestClassification.name_ar || '',
                color: guestClassification.color || '#3b82f6'
              } : null
            };
          });

          if (res.per_page) {
            return {
              data: mapped,
              total: res.total,
              currentPage: res.current_page,
              lastPage: res.last_page
            };
          }
          return mapped;
        }
        return [];
      }),
      tap(result => {
        if (Array.isArray(result)) {
          this.clientClassificationsSubject.next(result);
        } else if (result.data) {
          this.clientClassificationsSubject.next(result.data);
        }
      }),
      catchError(() => {
        this.loadedStates.clientClassifications = true;
        return of([]);
      })
    );
  }

  getClientClassifications(): Observable<ClientClassification[]> {
    return this.clientClassifications$;
  }

  getClientClassificationsSync(): ClientClassification[] {
    return this.clientClassificationsSubject.value;
  }

  fetchClientClassification(clientId: number | string): Observable<{
    client: any;
    classification: {
      id: number;
      name_ar?: string;
      name_en?: string;
      discount_id?: number | null;
      discount?: Discount | null;
    } | null;
  } | null> {
    return this.http.get<any>(`${this.dataService.Url}users/getClientClassification`, {
      headers: this.dataService.getHeaders(),
      params: { client_id: String(clientId) },
    }).pipe(
      map(res => (res?.success ? res.data ?? null : null)),
      catchError(() => of(null))
    );
  }

  assignClientClassification(data: any, refreshPage: number = 1): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/assignClientClassification`, data, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchClientClassifications(refreshPage).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  removeClientClassification(clientId: string, refreshPage: number = 1, perPage: number = 10): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/removeClientClassification`, { client_id: clientId }, {
      headers: this.dataService.getHeaders()
    }).pipe(
      switchMap(res => {
        if (res.success || res.result === 'success') {
          return this.fetchClientClassifications(refreshPage, perPage).pipe(map(() => res));
        }
        return of(res);
      })
    );
  }

  // ——— Financial / accounting APIs ———

  getEarningsSummary(startDate: string, endDate: string): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/earnings-summary`, {
      headers: this.dataService.getHeaders(),
      params: { start_date: startDate, end_date: endDate }
    });
  }

  getAllEarnings(startDate: string, endDate: string, compareStart?: string, compareEnd?: string): Observable<any> {
    const params: Record<string, string> = { start_date: startDate, end_date: endDate };
    if (compareStart && compareEnd) {
      params['compare_start_date'] = compareStart;
      params['compare_end_date'] = compareEnd;
    }
    return this.http.get<any>(`${this.dataService.Url}users/all-earnings`, {
      headers: this.dataService.getHeaders(),
      params
    });
  }

  getEarningsList(startDate: string, endDate: string, type: 'all' | 'in' | 'out' = 'all', page = 1, perPage = 50): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/earnings-list`, {
      headers: this.dataService.getHeaders(),
      params: {
        start_date: startDate,
        end_date: endDate,
        type,
        page: String(page),
        per_page: String(perPage)
      }
    });
  }

  getRevenueReport(
    scope: 'total' | 'room' | 'suite' | 'floor' | 'building' | 'roomtype',
    startDate: string,
    endDate: string,
    entityId?: number,
    compareStart?: string,
    compareEnd?: string,
    includeDetails = true
  ): Observable<any> {
    const params: Record<string, string> = {
      start_date: startDate,
      end_date: endDate,
      include_details: includeDetails ? '1' : '0'
    };
    if (compareStart && compareEnd) {
      params['compare_start_date'] = compareStart;
      params['compare_end_date'] = compareEnd;
    }
    const base = scope === 'total'
      ? `${this.dataService.Url}users/revenue/total`
      : `${this.dataService.Url}users/revenue/${scope}/${entityId}`;
    return this.http.get<any>(base, {
      headers: this.dataService.getHeaders(),
      params
    });
  }

  getDashboardSummary(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/dashboard/summary`, {
      headers: this.dataService.getHeaders()
    }).pipe(
      catchError(err => this.failLoad('dashboard', err))
    );
  }

  fetchCollectionsSummary(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/collections/summary`, {
      headers: this.dataService.getHeaders()
    }).pipe(
      catchError(err => this.failLoad('collections summary', err))
    );
  }

  fetchCollections(params?: {
    tab?: string;
    search?: string;
    page?: number;
    perPage?: number;
    sort?: string;
  }): Observable<any> {
    const query: Record<string, string> = {};
    if (params?.tab) query['tab'] = params.tab;
    if (params?.search) query['search'] = params.search;
    if (params?.page) query['page'] = String(params.page);
    if (params?.sort) query['sort'] = params.sort;

    return this.http.get<any>(`${this.dataService.Url}users/collections`, {
      headers: { ...this.dataService.getHeaders(), perPage: String(params?.perPage ?? 15) },
      params: query
    }).pipe(
      catchError(err => this.failLoad('collections', err))
    );
  }

  collectAllOutstandingBalances(): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/collections/collect-all`, {}, {
      headers: this.dataService.getHeaders()
    }).pipe(
      catchError(err => this.failLoad('collect all outstanding', err))
    );
  }

  getFinancialBounds(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/financials/bounds`, {
      headers: this.dataService.getHeaders()
    }).pipe(
      catchError(err => this.failLoad('financial bounds', err))
    );
  }

  getFinancialDashboard(startDate: string, endDate: string): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/financials/dashboard`, {
      headers: this.dataService.getHeaders(),
      params: { start_date: startDate, end_date: endDate }
    }).pipe(
      catchError(err => this.failLoad('financial dashboard', err))
    );
  }

  getRoomOccupancyBoard(params: {
    date?: string;
    building_id?: number;
    floor_id?: number;
    suite_id?: number;
    occupancy_status?: string;
    search?: string;
  }): Observable<any> {
    const query: Record<string, string> = {};
    if (params.date) query['date'] = params.date;
    if (params.building_id != null) query['building_id'] = String(params.building_id);
    if (params.floor_id != null) query['floor_id'] = String(params.floor_id);
    if (params.suite_id != null) query['suite_id'] = String(params.suite_id);
    if (params.occupancy_status) query['occupancy_status'] = params.occupancy_status;
    if (params.search) query['search'] = params.search;

    return this.http.get<any>(`${this.dataService.Url}users/rooms/occupancy-board`, {
      headers: this.dataService.getHeaders(),
      params: query
    });
  }

  // Refund policies
  fetchRefundPolicies(): Observable<any> {
    return this.http.get<any>(`${this.dataService.Url}users/refund-policies`, {
      headers: this.dataService.getHeaders()
    });
  }

  createRefundPolicy(data: any): Observable<any> {
    return this.http.post<any>(`${this.dataService.Url}users/refund-policies`, data, {
      headers: this.dataService.getHeaders()
    });
  }

  updateRefundPolicy(data: any): Observable<any> {
    return this.http.put<any>(`${this.dataService.Url}users/refund-policies/${data.id}`, data, {
      headers: this.dataService.getHeaders()
    });
  }

  deleteRefundPolicy(id: string | number): Observable<any> {
    return this.http.delete<any>(`${this.dataService.Url}users/refund-policies`, {
      headers: this.dataService.getHeaders(),
      body: { id }
    });
  }

  previewRefund(reservationId: string | number, newExpireDate?: string): Observable<any> {
    const params: Record<string, string> = { reservation_id: String(reservationId) };
    if (newExpireDate) {
      params['new_expire_date'] = newExpireDate;
    }
    return this.http.get<any>(`${this.dataService.Url}users/refund-policies/preview`, {
      headers: this.dataService.getHeaders(),
      params
    });
  }

  processRefund(reservationId: string | number, newExpireDate?: string): Observable<any> {
    const body: Record<string, unknown> = { reservation_id: reservationId };
    if (newExpireDate) {
      body['new_expire_date'] = newExpireDate;
    }
    return this.http.post<any>(`${this.dataService.Url}users/refund`, body, {
      headers: this.dataService.getHeaders()
    });
  }

  shortenReservation(id: string | number, expire_date: string): Observable<any> {
    return this.http.patch<any>(`${this.dataService.Url}users/reservations/${id}/shorten`, { expire_date }, {
      headers: this.dataService.getHeaders()
    });
  }
}
