export interface Client {
  id: string;
  first_name: string;
  last_name: string;
  name?: string; // Derived or provided
  email: string | null;
  international_code: string | null;
  mobile: string;
  phone?: string; // Alias for mobile
  IdType: 'ID' | 'PASSPORT' | null;
  IdNumber: string | null;
  birth_date: string | null;
  gender: 'MALE' | 'FEMALE' ;
  guest_type: 'CITIZEN' | 'RESIDENT' | 'GULF CITIZEN' | 'VISITOR' | null;
  nationality: string | null;
  address?: string;
  city?: string;
  country?: string;
  loyaltyPoints?: number;
  totalBookings?: number;
  company_id?: number | null;
  totalSpent?: number;
  lastVisit?: string;
  status?: 'active' | 'inactive';
  created_at?: string;
}
