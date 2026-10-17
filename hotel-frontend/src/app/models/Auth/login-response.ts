export interface HotelOption {
  id: number;
  name_ar: string;
  name_en: string;
}

export interface LoginResponse {
  id: number;
  email: string;
  token: string;
  role: string;
  roles?: string[];
  permissions?: string[];
  job_number?: string;
  name?: string;
  available_hotels?: HotelOption[];
  current_hotel_id?: number;
}
