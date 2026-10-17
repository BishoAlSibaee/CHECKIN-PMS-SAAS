export type AirbnbRequestMode = 'auto_accept' | 'manual_review' | 'auto_decline';
export type AirbnbFallbackAction = 'accept' | 'decline';
export type AirbnbDeclineReason =
  | 'dates_not_available'
  | 'not_a_good_fit'
  | 'waiting_for_better_reservation'
  | 'not_comfortable';

export interface AirbnbRequestSettings {
  id: number;
  hotel_id: number;
  mode: AirbnbRequestMode;
  reminder_interval_minutes: number;
  fallback_action: AirbnbFallbackAction;
  decline_reason: AirbnbDeclineReason;
  updated_by: number | null;
}

export interface AirbnbRequestBooking {
  booking_id?: string;
  arrival_date?: string;
  departure_date?: string;
  [key: string]: unknown;
}

export interface AirbnbRequest {
  id: number;
  hotel_id: number;
  channex_property_id: string;
  live_feed_event_id: string;
  message_thread_id: string | null;
  channex_booking_id: string | null;
  raw_payload: AirbnbRequestBooking;
  received_at: string;
  status: 'pending' | 'resolved';
  resolution: 'accept' | 'decline' | null;
  resolved_by: 'staff' | 'auto_accept' | 'auto_decline' | 'fallback' | null;
  resolved_by_user_id: number | null;
  resolved_at: string | null;
  last_reminder_sent_at: string | null;
  error_message: string | null;
}
