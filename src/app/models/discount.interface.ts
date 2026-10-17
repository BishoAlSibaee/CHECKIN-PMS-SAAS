export interface Discount {
  id: string | number;
  name: string;
  is_percentage: number | boolean;
  percent: number;
  is_fixed: number | boolean;
  fixed_amount: number;
  is_active: number | boolean;
  created_at?: string;
  updated_at?: string;
}
