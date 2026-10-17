export interface Company {
  id: number;
  hotel_id: number;
  name: string;
  contact_name: string | null;
  contact_mobile: string | null;
  contact_email: string | null;
  discount_id: number | null;
  discount?: { id: number; name: string; is_percentage: boolean; percent: number | null; is_fixed: boolean; fixed_amount: number | null } | null;
  credit_limit: number | null;
  status: number;
  notes: string | null;
  created_at?: string;
}

export interface CompanyLedgerRow {
  company_id: number;
  name: string;
  credit_limit: number | null;
  status: number;
  balance: number;
  total_debit: number;
  total_credit: number;
}

export interface CompanyStatementLine {
  entry_date: string;
  description: string | null;
  reservation_id: number | null;
  debit: number;
  credit: number;
  memo: string | null;
}

export interface CompanyStatement {
  lines: CompanyStatementLine[];
  total_debit: number;
  total_credit: number;
  balance: number;
}
