export interface NightAuditOverdueCheckout {
  reservation_id: number;
  guest: string;
  room: string | null;
  expire_date: string;
  days_overdue: number;
}

export interface NightAuditZatcaPending {
  invoice_id: number;
  invoice_number: string;
  reservation_id: number;
  status: string | null;
  error_message: string | null;
}

export interface NightAuditRun {
  id: number;
  hotel_id: number;
  audit_date: string;
  exceptions_count: number;
  generated_at: string;
  metrics: {
    cash_collected: number;
    refunds_total: number;
    overdue_checkouts_count: number;
    trial_balance_debit: number;
    trial_balance_credit: number;
    zatca_pending_count: number;
  };
  exceptions: {
    overdue_checkouts: NightAuditOverdueCheckout[];
    trial_balance_unbalanced: boolean;
    zatca_pending: NightAuditZatcaPending[];
  };
}
