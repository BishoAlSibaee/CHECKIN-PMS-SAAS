export type BookingStatus = 'confirmed' | 'checked-in' | 'checked-out' | 'cancelled' | 'unconfirmed' | 'pending-payment';

/** Calendar dot / legend states (payment + reservation status). */
export type CalendarReservationState =
  | 'confirmed'
  | 'checked-in'
  | 'partial-pay'
  | 'needs-pay'
  | 'pending'
  | 'cancelled';

export interface Payment {
  id: string;
  amount: number;
  date: string;
  method: string;
  note?: string;
  isRefund?: boolean;
  voucherNumber?: string | null;
}

export interface Booking {
  id: string;
  guest: string;
  room: string;
  checkIn: string;
  checkOut: string;
  status: BookingStatus;
  amount: number;
  phone: string;
  email: string;
  startDate: Date;
  endDate: Date;
  // Additional details for the detailed view
  clientId?: string;
  roomTypeId?: string;
  paymentStatus?: 'pending' | 'paid' | 'partially-paid';
  paymentMethod?: string;
  notes?: string;
  roomPrice?: number;
  tax?: number;
  discount?: number;
  classificationDiscount?: number;
  totalDays?: number;
  payments?: Payment[];
  paidAmount?: number;
  balanceDue?: number;
}
