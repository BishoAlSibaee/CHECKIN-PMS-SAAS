export interface JobTitle {
  id: number | string;
  name_ar: string;
  name_en: string;
  description?: string;
  department_id: number | string;
  department?: any;
  created_at?: string;
  updated_at?: string;
}
