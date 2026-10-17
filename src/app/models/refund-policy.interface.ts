export type RefundPaymentStatus = 'partial' | 'full' | 'paid';

export type RefundThresholdMode = 'fixed_days' | 'percent_of_stay';

export interface RefundPolicy {
  id: number | string;
  name: string;
  rent_type: number | null;
  timing: 'before_start' | 'after_start';
  threshold_mode?: RefundThresholdMode;
  threshold_percent?: number | null;
  days_threshold: number;
  days_before_checkin?: number;
  refund_percent: number;
  refund_basis: 'total' | 'remaining_nights' | 'paid_net';
  payment_status: 1 | 2 | null;
  payment_statuses?: RefundPaymentStatus[] | null;
  during_stay?: number;
  priority?: number;
}

export interface RefundPreview {
  policy: RefundPolicy;
  refund_amount: number;
  breakdown: {
    net_paid: number;
    total: number;
    policy_name: string;
    refund_percent: number;
    refund_basis: string;
    days_until_start: number;
    days_since_start: number;
    total_nights?: number;
    remaining_base: number;
    rent_type: number;
    threshold_mode?: RefundThresholdMode;
    threshold_percent?: number | null;
    resolved_threshold_days?: number;
    partial_cancel?: boolean;
    new_expire_date?: string;
    cancelled_nights_count?: number;
    cancelled_nights_base?: number;
  };
}
