export type PromoAudienceType = 'manual' | 'classification';

export interface GuestClassification {
  id: number;
  name_ar: string;
  name_en: string;
}

export interface AudiencePreviewClient {
  id: number;
  name: string;
  phone: string | null;
}

export interface PromoCampaign {
  id: number;
  channel: string;
  message_body: string;
  audience_type: PromoAudienceType;
  guest_classification_id: number | null;
  recipient_count: number;
  status: string;
  created_at: string;
  creator?: { id: number; name: string };
}

export interface TaqnyatBalance {
  configured: boolean;
  balance?: number;
  currency?: string;
  account_status?: string;
  account_expiry_date?: string | null;
  error?: string;
}
