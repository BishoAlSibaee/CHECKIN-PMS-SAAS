export interface HotelTaxProfile {
  id: number | string;
  hotel_id: number | string;
  vat_number: string;
  cr_number?: string | null;
  org_name_ar: string;
  org_name_en: string;
  org_unit_name?: string | null;
  location?: string | null;
  industry?: string | null;
}

export interface HotelBranch {
  id: number | string;
  name_ar: string;
  name_en: string;
  is_active: boolean;
  buildings_count?: number;
  users_count?: number;
  created_at?: string;
  updated_at?: string;
  shares_tax_with_hotel_id?: number | string | null;
  tax_profile?: HotelTaxProfile | null;
  shares_tax_with?: { id: number | string; name_ar: string; name_en: string } | null;
}

export interface HotelTaxFormPayload {
  tax_mode: 'separate' | 'shared';
  shares_tax_with_hotel_id?: number | string | null;
  vat_number?: string;
  cr_number?: string;
  org_name_ar?: string;
  org_name_en?: string;
  org_unit_name?: string;
  location?: string;
  industry?: string;
}

export interface HotelBranchUser {
  id: number | string;
  name: string;
  job_number?: string;
}
