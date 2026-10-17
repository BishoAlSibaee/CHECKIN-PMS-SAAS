import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { TranslateModule } from '@ngx-translate/core';
import { of, Subject } from 'rxjs';
import { CreateReservationComponent } from './create-reservation.component';
import { HotelService } from '../../../services/hotel.service';
import { DataService } from '../../../services/Api/data.service';
import { AuthPermissionService } from '../../../services/auth-permission.service';
import { RealtimeService } from '../../../services/realtime.service';
import { LanguageService } from '../../../services/language.service';
import { HotelLivePayload } from '../../../models/realtime.models';
import { formatLocalDate, addCalendarDays, addMonthsNoOverflow } from '../../../utils/reservation-calendar-dates';

describe('CreateReservationComponent realtime', () => {
  let component: CreateReservationComponent;
  let fixture: ComponentFixture<CreateReservationComponent>;
  const scopeHandlers = new Map<string, (payload?: HotelLivePayload) => void>();
  let loadBookingRoomsSpy: jasmine.Spy;

  beforeEach(async () => {
    scopeHandlers.clear();

    await TestBed.configureTestingModule({
      declarations: [CreateReservationComponent],
      imports: [TranslateModule.forRoot(), FormsModule],
      providers: [
        { provide: ActivatedRoute, useValue: { queryParams: of({}), params: of({}) } },
        { provide: Router, useValue: { navigate: jasmine.createSpy('navigate') } },
        {
          provide: HotelService,
          useValue: {
            fetchBookingRoomAvailability: () => of({ success: true, data: [] }),
          },
        },
        { provide: DataService, useValue: {} },
        { provide: AuthPermissionService, useValue: { has: () => true } },
        {
          provide: RealtimeService,
          useValue: {
            onScope: (scope: string, handler: (payload?: HotelLivePayload) => void) => {
              scopeHandlers.set(scope, handler);
              return () => scopeHandlers.delete(scope);
            },
          },
        },
        {
          provide: LanguageService,
          useValue: { changes$: new Subject<void>().asObservable() },
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(CreateReservationComponent);
    component = fixture.componentInstance;
    component.selectedBuildingId = 1;
    component.reservation.start_date = '2026-08-20';
    component.reservation.expire_date = '2026-08-23';
    loadBookingRoomsSpy = spyOn(component, 'loadBookingRooms').and.stub();
    (component as any).bindRealtimeRoomRefresh();
  });

  it('subscribes to occupancy_board, reservations, and property scopes', () => {
    expect(scopeHandlers.has('occupancy_board')).toBeTrue();
    expect(scopeHandlers.has('reservations')).toBeTrue();
    expect(scopeHandlers.has('property')).toBeTrue();
  });

  it('reloads room availability after reservation_created with debounce', (done) => {
    scopeHandlers.get('reservations')!({ action: 'reservation_created', scopes: ['reservations'], board_version: 2 });
    expect(loadBookingRoomsSpy).not.toHaveBeenCalled();
    setTimeout(() => {
      expect(loadBookingRoomsSpy).toHaveBeenCalledWith({ soft: true });
      done();
    }, 500);
  });
});

describe('CreateReservationComponent default dates', () => {
  let component: CreateReservationComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [CreateReservationComponent],
      imports: [TranslateModule.forRoot(), FormsModule],
      providers: [
        { provide: ActivatedRoute, useValue: { queryParams: of({}), params: of({}) } },
        { provide: Router, useValue: { navigate: jasmine.createSpy('navigate') } },
        { provide: HotelService, useValue: {} },
        { provide: DataService, useValue: {} },
        { provide: AuthPermissionService, useValue: { has: () => true } },
        { provide: RealtimeService, useValue: { onScope: () => () => undefined } },
        { provide: LanguageService, useValue: { changes$: new Subject<void>().asObservable() } },
      ],
    }).compileComponents();

    component = TestBed.createComponent(CreateReservationComponent).componentInstance;
  });

  it('sets local check-in/out defaults so room availability can load', () => {
    component.reservation.start_date = '';
    component.reservation.expire_date = '';
    (component as any).normalizeDefaultDates();
    const today = formatLocalDate(new Date());
    expect(component.reservation.start_date).toBe(today);
    expect(component.reservation.expire_date).toBe(addCalendarDays(today, 1));
    expect(component.datesValid).toBeTrue();
  });

  it('rejects pay amount above reservation total', () => {
    component.calculatedPrice = 1000;
    component.reservation.extras = 0;
    component.reservation.penalties = 0;
    component.discountValue = 0;
    component.reservation.pay_amount = component.grandTotal + 50;
    expect(component.payAmountError).toBeTrue();
    expect(component.validatePayAmount()).toBeFalse();
  });

  it('fills pay amount with full reservation total', () => {
    component.calculatedPrice = 500;
    component.setFullPayAmount();
    expect(component.reservation.pay_amount).toBe(component.grandTotal);
    expect(component.payAmountError).toBeFalse();
  });

  it('disables check-in now when start date is not today', () => {
    const today = formatLocalDate(new Date());
    component.reservation.start_date = addCalendarDays(today, 2);
    component.reservation.logedin = 1;

    expect(component.checkInTodayAllowed).toBeFalse();
    component.onStartDateChange();
    expect(component.reservation.logedin).toBe(0);
  });

  it('allows check-in now when start date is today', () => {
    const today = formatLocalDate(new Date());
    component.reservation.start_date = today;

    expect(component.checkInTodayAllowed).toBeTrue();
  });

  it('sets monthly checkout to last day of next month when check-in is month-end', () => {
    component.reservation.rent_type = 1;
    component.reservation.start_date = '2026-09-30';
    component.reservation.expire_date = '';
    (component as any).normalizeDefaultDates();

    expect(component.reservation.expire_date).toBe('2026-10-31');
    expect(component.monthlyStayError).toBeFalse();
  });

  it('bumps monthly checkout when switching rent type from daily minimum', () => {
    component.reservation.start_date = '2026-08-31';
    component.reservation.expire_date = '2026-09-01';

    component.onRentTypeChange(1);

    expect(component.reservation.expire_date).toBe('2026-09-30');
    expect(component.minExpireDateForStay).toBe('2026-09-30');
  });
});
