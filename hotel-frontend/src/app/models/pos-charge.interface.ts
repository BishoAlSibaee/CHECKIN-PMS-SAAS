export interface PosCategory {
  id: number;
  hotel_id: number | null;
  account_id: number;
  name_en: string;
  name_ar: string;
  is_active: boolean;
  account?: { id: number; name_en: string; name_ar: string; code: string };
}

export interface PosCharge {
  id: number;
  hotel_id: number;
  reservation_id: number | null;
  pos_category_id: number;
  description: string | null;
  invoice_reference: string | null;
  quantity: number;
  unit_price: number;
  amount: number;
  tax_amount: number;
  charge_date: string;
  payment_status: number;
  status: number;
  walkin_reference: string | null;
  created_by: number | null;
  voided_by: number | null;
  voided_at: string | null;
  category?: PosCategory;
  reservation?: { id: number; client?: { first_name: string; last_name: string } };
  creator?: { id: number; name: string };
}

export const POS_PAYMENT_STATUS_PAID_IMMEDIATELY = 0;
export const POS_PAYMENT_STATUS_CHARGED_TO_ROOM = 1;

export const POS_CHARGE_STATUS_ACTIVE = 0;
export const POS_CHARGE_STATUS_VOIDED = 1;

export interface PosRoomLookupResult {
  room_id: number;
  room_number: string;
  reservation_id: number | null;
  guest_name: string | null;
}
