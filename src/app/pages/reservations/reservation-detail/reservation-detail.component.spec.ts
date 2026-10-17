import { ComponentFixture, TestBed, fakeAsync, tick } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, Router } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { of } from 'rxjs';
import { ReservationDetailComponent } from './reservation-detail.component';
import { HotelService } from '../../../services/hotel.service';
import { DataService } from '../../../services/Api/data.service';
import { AuthPermissionService } from '../../../services/auth-permission.service';
import { ConfirmDialogService } from '../../../services/confirm-dialog.service';
import { RealtimeService } from '../../../services/realtime.service';
import { HotelLivePayload } from '../../../models/realtime.models';

describe('ReservationDetailComponent realtime', () => {
  let component: ReservationDetailComponent;
  let fixture: ComponentFixture<ReservationDetailComponent>;
  const scopeHandlers = new Map<string, (payload: HotelLivePayload) => void>();
  let loadBookingSpy: jasmine.Spy;

  beforeEach(async () => {
    scopeHandlers.clear();

    await TestBed.configureTestingModule({
      declarations: [ReservationDetailComponent],
      imports: [TranslateModule.forRoot()],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: { paramMap: convertToParamMap({ id: '15' }) },
            params: of({ id: '15' }),
          },
        },
        {
          provide: HotelService,
          useValue: {
            getReservation: () => of({ success: true, data: { id: 15 } }),
          },
        },
        { provide: DataService, useValue: {} },
        { provide: Router, useValue: { navigate: jasmine.createSpy('navigate') } },
        { provide: AuthPermissionService, useValue: { has: () => true } },
        { provide: ConfirmDialogService, useValue: { confirm: () => Promise.resolve(true) } },
        {
          provide: RealtimeService,
          useValue: {
            onScope: (scope: string, handler: (payload: HotelLivePayload) => void) => {
              scopeHandlers.set(scope, handler);
              return () => scopeHandlers.delete(scope);
            },
          },
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(ReservationDetailComponent);
    component = fixture.componentInstance;
    loadBookingSpy = spyOn(component, 'loadBooking').and.stub();
    component.ngOnInit();
    loadBookingSpy.calls.reset();
  });

  it('reloads only when entity id matches current reservation', fakeAsync(() => {
    const scopeHandler = scopeHandlers.get('reservation_detail');
    expect(scopeHandler).toBeDefined();

    scopeHandler!({ board_version: 1, scopes: ['reservation_detail'], action: 'payment_recorded', entity: { type: 'reservation', id: 99 } });
    tick();
    expect(loadBookingSpy).not.toHaveBeenCalled();

    scopeHandler!({ board_version: 2, scopes: ['reservation_detail'], action: 'payment_recorded', entity: { type: 'reservation', id: 15 } });
    tick();
    expect(loadBookingSpy).toHaveBeenCalledWith('15', { softReload: true });
  }));
});

describe('ReservationDetailComponent payment validation', () => {
  let component: ReservationDetailComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReservationDetailComponent],
      imports: [TranslateModule.forRoot()],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: { paramMap: convertToParamMap({ id: '15' }) },
            params: of({ id: '15' }),
          },
        },
        { provide: HotelService, useValue: { getReservation: () => of({ success: true, data: { id: 15 } }) } },
        { provide: DataService, useValue: {} },
        { provide: Router, useValue: { navigate: jasmine.createSpy('navigate') } },
        { provide: AuthPermissionService, useValue: { has: () => true } },
        { provide: ConfirmDialogService, useValue: { confirm: () => Promise.resolve(true) } },
        { provide: RealtimeService, useValue: { onScope: () => () => undefined } },
      ],
    }).compileComponents();

    component = TestBed.createComponent(ReservationDetailComponent).componentInstance;
    component.balanceDue = 250;
  });

  it('flags payment above remaining balance', () => {
    component.paymentAmount = 300;
    expect(component.paymentAmountExceedsBalance).toBeTrue();
  });

  it('sets full payment to remaining balance', () => {
    component.setFullPaymentAmount();
    expect(component.paymentAmount).toBe(250);
    expect(component.paymentAmountExceedsBalance).toBeFalse();
  });

  it('uses paidAmount net zero instead of summing refund rows as payments', () => {
    component.paidAmount = 0;
    component.booking = {
      id: '1',
      guest: 'Test',
      room: '101',
      checkIn: '2026-07-05',
      checkOut: '2026-07-10',
      status: 'confirmed',
      amount: 966,
      phone: '',
      email: '',
      startDate: new Date('2026-07-05'),
      endDate: new Date('2026-07-10'),
      payments: [
        { id: '1', amount: 966, date: '2026-07-05', method: 'FINANCIALS.CASH', isRefund: false },
        { id: '2', amount: 966, date: '2026-07-06', method: 'FINANCIALS.REFUND', isRefund: true },
      ],
    };

    expect(component.getTotalPaid()).toBe(0);
  });
});

describe('ReservationDetailComponent post check-out actions', () => {
  let component: ReservationDetailComponent;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ReservationDetailComponent],
      imports: [TranslateModule.forRoot()],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: { paramMap: convertToParamMap({ id: '15' }) },
            params: of({ id: '15' }),
          },
        },
        { provide: HotelService, useValue: { getReservation: () => of({ success: true, data: { id: 15 } }) } },
        { provide: DataService, useValue: {} },
        { provide: Router, useValue: { navigate: jasmine.createSpy('navigate') } },
        { provide: AuthPermissionService, useValue: { has: () => true } },
        { provide: ConfirmDialogService, useValue: { confirm: () => Promise.resolve(true) } },
        { provide: RealtimeService, useValue: { onScope: () => () => undefined } },
      ],
    }).compileComponents();

    component = TestBed.createComponent(ReservationDetailComponent).componentInstance;
    const today = new Date().toISOString().split('T')[0];
    component.booking = {
      id: '15',
      guest: 'Test',
      room: '101',
      checkIn: today,
      checkOut: today,
      status: 'checked-out',
      amount: 500,
      phone: '',
      email: '',
      startDate: new Date(today),
      endDate: new Date(today),
      payments: [],
    };
    component.editExpireDate = today;
    component.editStartDate = today;
    component.logedin = 0;
    component.loginTime = today;
    component.paidAmount = 500;
    component.reservationStatusNum = 1;
  });

  it('hides refund, cancel, and check-in after guest has checked out', () => {
    component.changeLogs = [{
      id: 1,
      action: 'checked_in',
      user: null,
      old_values: {},
      new_values: {},
      changes: {},
      created_at: component.editStartDate,
    }];
    expect(component.hasCheckedOut).toBeTrue();
    expect(component.canRefund).toBeFalse();
    expect(component.canCancel).toBeFalse();
    expect(component.canCheckIn).toBeFalse();
    expect(component.canEditDates).toBeFalse();
  });

  it('allows check-in when login_time was set at create without a checked_in log', () => {
    const today = new Date().toISOString().split('T')[0];
    const tomorrow = new Date(Date.now() + 86400000).toISOString().split('T')[0];
    component.booking = {
      id: '17673',
      guest: 'Test',
      room: '102',
      checkIn: today,
      checkOut: tomorrow,
      status: 'confirmed',
      amount: 500,
      phone: '',
      email: '',
      startDate: new Date(today),
      endDate: new Date(tomorrow),
      payments: [],
    };
    component.editStartDate = today;
    component.editExpireDate = tomorrow;
    component.logedin = 0;
    component.loginTime = today;
    component.changeLogs = [];
    component.reservationStatusNum = 1;

    expect(component.hasCheckedOut).toBeFalse();
    expect(component.canCheckIn).toBeTrue();
  });
});
