export interface GroupBookingMemberRoom {
  room_id: number;
  room_number?: string;
}

/** One draft reservation within a group — mirrors makeReservation()'s own field set. */
export interface GroupBookingMemberDraft {
  client_id: number | null;
  client_label?: string;
  rooms: GroupBookingMemberRoom[];
  start_date: string;
  expire_date: string;
  stay_reason_id: number | null;
  reservation_source_id: number | null;
  rent_type: number;
  price_calculation_mode: number;
  reservation_status: number;
  discount?: number;
}

export interface GroupBookingMemberSummary {
  reservation_id: number;
  client_name: string | null;
  start_date: string;
  expire_date: string;
  total: number;
  paid: number;
  balance_due: number;
  reservation_status: number;
}

export interface GroupBookingFinancials {
  member_count: number;
  total: number;
  paid: number;
  balance_due: number;
  by_status: Record<string, number>;
  members: GroupBookingMemberSummary[];
}

export interface GroupBooking {
  id: number;
  hotel_id: number;
  name: string;
  company_name: string | null;
  contact_name: string | null;
  contact_mobile: string | null;
  contact_email: string | null;
  notes: string | null;
  group_discount_percent: number;
  status: number;
  created_by: number;
  reservations_count?: number;
  created_at?: string;
}

export interface GroupCancelMemberResult {
  reservation_id: number;
  success: boolean;
  message: string;
}

export interface GroupCancelResult {
  fully_cancelled: boolean;
  members: GroupCancelMemberResult[];
}
