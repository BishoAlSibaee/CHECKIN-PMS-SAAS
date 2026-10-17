import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { Location } from '@angular/common';
import { TranslateModule } from '@ngx-translate/core';
import { of } from 'rxjs';
import { RoomHistoryComponent } from './room-history.component';
import { HotelService } from '../../../services/hotel.service';
import { RealtimeService } from '../../../services/realtime.service';

import { Booking } from '../../../models/booking.interface';

describe('RoomHistoryComponent', () => {
  let component: RoomHistoryComponent;
  let fixture: ComponentFixture<RoomHistoryComponent>;
  let hotelService: jasmine.SpyObj<HotelService>;

  const sampleBooking: Booking = {
    id: '100',
    guest: 'Guest A',
    checkIn: '2026-01-01',
    checkOut: '2026-01-03',
    status: 'confirmed',
    amount: 200,
    payments: [{ id: '1', amount: 100, date: '2026-01-01', method: 'cash' }],
    room: '102',
    phone: '',
    email: '',
    startDate: new Date('2026-01-01'),
    endDate: new Date('2026-01-03'),
  };

  beforeEach(async () => {
    hotelService = jasmine.createSpyObj('HotelService', ['fetchRoomHistory']);
    hotelService.fetchRoomHistory.and.callFake((_room: string, page: number, perPage: number) => {
      if (perPage === 100) {
        return of({
          data: [sampleBooking],
          total: 1,
          currentPage: 1,
          lastPage: 1,
        });
      }

      if (page === 1) {
        return of({
          data: Array.from({ length: 10 }, (_, i) => ({ ...sampleBooking, id: String(100 + i) })),
          total: 25,
          currentPage: 1,
          lastPage: 3,
        });
      }

      return of({
        data: [{ ...sampleBooking, id: '200' }],
        total: 25,
        currentPage: page,
        lastPage: 3,
      });
    });

    await TestBed.configureTestingModule({
      declarations: [RoomHistoryComponent],
      imports: [TranslateModule.forRoot()],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: {
            params: of({ number: '102' }),
          },
        },
        { provide: Router, useValue: { navigate: jasmine.createSpy('navigate') } },
        { provide: Location, useValue: { back: jasmine.createSpy('back') } },
        { provide: HotelService, useValue: hotelService },
        {
          provide: RealtimeService,
          useValue: {
            onScope: () => () => undefined,
          },
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(RoomHistoryComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('loads paginated table data from the API', () => {
    expect(component.bookings.length).toBe(10);
    expect(component.totalItems).toBe(25);
    expect(component.totalPages).toBe(3);
    expect(component.paginationFrom).toBe(1);
    expect(component.paginationTo).toBe(10);
  });

  it('requests the next page when pagination changes', () => {
    component.setPage(2);
    fixture.detectChanges();

    expect(hotelService.fetchRoomHistory).toHaveBeenCalledWith('102', 2, 10);
    expect(component.bookings[0].id).toBe('200');
    expect(component.currentPage).toBe(2);
  });

  it('marks body for print layout when print is requested', () => {
    component.printCurrentPage();

    expect(document.body.classList.contains('room-history-print-active')).toBeTrue();
    document.body.classList.remove('room-history-print-active');
  });

  it('exposes occupancy span dates for the summary card', () => {
    component.summaryBookings = [
      { ...sampleBooking, checkIn: '2026-01-01', checkOut: '2026-01-05' },
      { ...sampleBooking, id: '101', checkIn: '2026-02-10', checkOut: '2026-02-20' },
    ];

    expect(component.occupancyMetrics.spanFrom).toBe('2026-01-01');
    expect(component.occupancyMetrics.spanTo).toBe('2026-02-20');
    expect(component.occupancyMetrics.bookedNights).toBeGreaterThan(0);
    expect(component.occupancyMetrics.spanDays).toBeGreaterThan(0);
  });
});
