export type DynamicPricingMetric = 'occupancy' | 'lead_time';

export interface DynamicPricingRule {
  id: number;
  room_type_id: number;
  metric: DynamicPricingMetric;
  range_from: number;
  range_to: number | null;
  adjustment_percent: number;
}
