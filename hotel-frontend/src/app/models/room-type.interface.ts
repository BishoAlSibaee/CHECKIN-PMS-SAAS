export interface RoomType {
  id: string;
  name: string;
  name_ar?: string;
  name_en?: string;
  description: string;
  baseRate: number;
  maxOccupancy: number;
  amenities: string[];
  color: string;
  minDailyRate: number;
  maxDailyRate: number;
  minMonthlyRate: number;
  maxMonthlyRate: number;
  active_type?: number;
  dynamic_pricing_enabled?: boolean;
}
