export interface PlatformAdminCreator {
  id: number;
  name: string;
  email: string;
}

export interface Hotel {
  id: number;
  tenant_id: number;
  name_ar: string;
  name_en: string;
  is_active: boolean;
}

export interface Tenant {
  id: number;
  name_ar: string;
  name_en: string;
  is_active: boolean;
  subscription_ends_at: string | null;
  is_subscription_active: boolean;
  hotels_count: number;
  hotels?: Hotel[];
  created_by?: PlatformAdminCreator | null;
  created_at: string;
}

export interface PropertySpec {
  buildings_count: number;
  floors_count: number;
  rooms_per_floor: number;
}

export interface OnboardTenantRequest extends PropertySpec {
  tenant_name_ar: string;
  tenant_name_en: string;
  hotel_name_ar: string;
  hotel_name_en: string;
  subscription_days: number | null;
  admin_name: string;
  admin_job_number: string;
  admin_email: string;
  admin_mobile: string;
  admin_password: string;
}

export interface OnboardTenantResponse {
  tenant: Tenant;
  hotel: Hotel;
  admin: { job_number: string; name: string };
}

export interface AddHotelRequest extends PropertySpec {
  hotel_name_ar: string;
  hotel_name_en: string;
}

export interface UpdateTenantRequest {
  name_ar?: string;
  name_en?: string;
  is_active?: boolean;
}

export interface RenewSubscriptionRequest {
  no_expiry?: boolean;
  extend_days?: number;
}
