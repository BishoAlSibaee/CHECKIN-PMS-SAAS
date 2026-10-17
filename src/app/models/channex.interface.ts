export interface OtaAllocationRow {
  room_type_id: number;
  room_type_name_en: string;
  room_type_name_ar: string;
  total_rooms: number;
  allocated_rooms: number;
  ota_booked: number;
  rate_plans: OtaRatePlan[];
  has_active_rate_plan: boolean;
}

export interface PlatformLedgerRow {
  reservation_source_id: number;
  name_en: string;
  name_ar: string;
  balance: number;
  total_debit: number;
  total_credit: number;
}

export interface PlatformStatementLine {
  entry_date: string;
  description: string;
  reservation_id: number | null;
  debit: number;
  credit: number;
  memo: string | null;
}

export interface PlatformStatement {
  lines: PlatformStatementLine[];
  total_debit: number;
  total_credit: number;
  balance: number;
}

export interface OtaRatePlan {
  id: number;
  hotel_id: number;
  room_type_id: number;
  name_ar: string;
  name_en: string;
  price_adjustment_percent: number;
  is_refundable: boolean;
  is_active: boolean;
  room_type?: { id: number; name_ar: string; name_en: string } | null;
  channex_mapping?: { id: number; channex_rate_plan_id: string } | null;
}

export interface ChannexRoomTypeMapping {
  id: number;
  room_type_id: number;
  channex_room_type_id: string;
  room_type?: { id: number; name_ar: string; name_en: string } | null;
}

export interface ChannexProperty {
  id: number;
  hotel_id: number;
  channex_property_id: string;
}

export interface ChannexConnectionStatus {
  enabled: boolean;
  environment: string;
  property: ChannexProperty | null;
  room_type_mappings: ChannexRoomTypeMapping[];
}

export interface ChannexReviewScore {
  category: string;
  score: number;
}

export interface ChannexReview {
  id: string;
  content: string;
  guest_name: string | null;
  inserted_at: string;
  received_at: string;
  updated_at: string;
  is_hidden: boolean;
  is_replied: boolean;
  ota: string;
  ota_reservation_id: string | null;
  overall_score: number;
  reply: string | null;
  scores: ChannexReviewScore[];
  tags: string[];
}

export interface ChannexPropertyScoreCategory {
  category: string;
  score: number;
  count: number;
}

export interface ChannexOtaScore {
  ota: string;
  overall_score: number;
  count: number;
}

export interface ChannexPropertyScore {
  overall_score: number;
  count?: number;
  categories?: ChannexPropertyScoreCategory[];
  ota_scores?: ChannexOtaScore[];
}

export interface ChannexMessage {
  message: string | null;
  attachments?: string[];
  sender: 'guest' | 'property';
  inserted_at: string;
  updated_at: string;
}

export interface ChannexMessageThread {
  id: string;
  title: string | null;
  is_closed: boolean;
  provider: 'BookingCom' | 'Airbnb' | 'Expedia' | string;
  message_count: number;
  last_message: ChannexMessage | null;
  last_message_received_at: string | null;
  booking_id?: string | null;
}
