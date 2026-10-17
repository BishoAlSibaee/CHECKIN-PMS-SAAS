export interface PricingPlan {
  id: string;
  name: string;
  name_ar?: string;
  name_en?: string;
  description: string;
  discount: number;
  startDate: string;
  endDate: string;
  status: 'active' | 'draft' | 'expired' | 'inactive';
  linkedRoomTypes: string[];
  conditions: string;
  pricingplan_id?: string;
  roomtype_id?: string;
  dailyPrice?: number;
  monthlyPrice?: number;
  activeType?: number;
}
