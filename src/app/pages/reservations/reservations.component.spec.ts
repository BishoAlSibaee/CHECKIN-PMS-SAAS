import { startOfDay } from 'date-fns';
import { ReservationsComponent } from './reservations.component';

describe('ReservationsComponent calendar state', () => {
  let component: ReservationsComponent;

  beforeEach(() => {
    component = Object.create(ReservationsComponent.prototype) as ReservationsComponent;
  });

  it('applies date_from and date_to query filters and syncs calendar day', () => {
    component.dateFrom = '';
    component.dateTo = '';
    component.viewDate = startOfDay(new Date('2026-01-01'));
    component.activeDay = startOfDay(new Date('2026-01-01'));

    component.applyDateFiltersFromQuery({
      date_from: '2026-07-20',
      date_to: '2026-07-20',
    });

    expect(component.dateFrom).toBe('2026-07-20');
    expect(component.dateTo).toBe('2026-07-20');
    expect(component.viewDate).toEqual(startOfDay(new Date('2026-07-20T00:00:00')));
    expect(component.activeDay).toEqual(startOfDay(new Date('2026-07-20T00:00:00')));
  });

  it('uses partial payment color state before checked-in state when balance remains', () => {
    const state = (component as any).inferCalendarState(1, 1, 750, 250);

    expect(state).toBe('partial-pay');
  });

  it('uses needs-pay color state for checked-in reservations with no payment and balance due', () => {
    const state = (component as any).inferCalendarState(1, 1, 1000, 0);

    expect(state).toBe('needs-pay');
  });

  it('uses balance due from booking data before checked-in state', () => {
    const state = (component as any).inferCalendarStateFromBooking({
      id: '17651',
      guest: 'Guest',
      room: '101',
      checkIn: '2026-07-15',
      checkOut: '2026-07-24',
      status: 'checked-in',
      amount: 8464,
      paidAmount: 1000,
      balanceDue: 7464,
      phone: '',
      email: '',
      startDate: new Date('2026-07-15'),
      endDate: new Date('2026-07-24'),
    });

    expect(state).toBe('partial-pay');
  });

  it('uses warning status badge when a checked-in booking has balance due', () => {
    const badgeClass = component.getBookingStatusBadgeClass({
      id: '17651',
      guest: 'Guest',
      room: '101',
      checkIn: '2026-07-15',
      checkOut: '2026-07-24',
      status: 'checked-in',
      amount: 8464,
      paidAmount: 1000,
      balanceDue: 7464,
      phone: '',
      email: '',
      startDate: new Date('2026-07-15'),
      endDate: new Date('2026-07-24'),
    });

    expect(badgeClass).toBe('status-balance-due');
  });
});
