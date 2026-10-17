export interface Penalty {
  id: number | string;
  name_ar: string;
  name_en: string;
  type: number | string;
  value: number;
  amount?: number; // Used in frontend UI
  description?: string;
  created_at?: string;
  updated_at?: string;
}
