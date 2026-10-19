export interface InvoicePrintLine {
  description: string;
  period: string;
  nights: number;
  unitRate: number;
  amount: number;
}

export interface InvoiceDailyLine {
  date: string;
  amount: number;
}

export interface InvoiceExtraChargeLine {
  date: string;
  description: string;
  reference: string | null;
  amount: number;
}

export interface InvoicePaymentLine {
  date: string;
  method: string;
  amount: number;
  voucherNumber?: string | null;
}

export interface InvoicePrintLabels {
  title: string;
  issuedOn: string;
  billTo: string;
  guest: string;
  phone: string;
  email: string;
  room: string;
  stay: string;
  nights: string;
  status: string;
  lineItems: string;
  description: string;
  period: string;
  nightsCol: string;
  unitRate: string;
  amount: string;
  dailyDetail: string;
  date: string;
  extraCharges: string;
  reference: string;
  paymentsReceived: string;
  method: string;
  accommodation: string;
  tax: string;
  classificationDiscount: string;
  additionalDiscount: string;
  totalDiscount: string;
  discount: string;
  total: string;
  paid: string;
  balance: string;
  thankYou: string;
  footer: string;
  currency: string;
  taxQr: string;
  invoiceNumber: string;
  voucherNumber: string;
  creditNotes: string;
}

export interface ReservationInvoicePrintData {
  dir: 'rtl' | 'ltr';
  lang: string;
  labels: InvoicePrintLabels;
  invoiceId: string;
  issuedOn: string;
  guestName: string;
  guestPhone: string;
  guestEmail: string;
  roomLabel: string;
  checkIn: string;
  checkOut: string;
  nightsCount: number;
  statusLabel: string;
  lineItems: InvoicePrintLine[];
  dailyLines: InvoiceDailyLine[];
  extraCharges: InvoiceExtraChargeLine[];
  payments: InvoicePaymentLine[];
  accommodationSubtotal: number;
  tax: number;
  classificationDiscount: number;
  additionalDiscount: number;
  discount: number;
  total: number;
  paid: number;
  balance: number;
  /** Data URL (PNG) of ZATCA tax QR code, when available. */
  taxQrDataUrl?: string | null;
  /** Guest-facing sequential invoice number (INV-000001), when one has been issued. */
  taxInvoiceNumber?: string | null;
  /** Credit notes issued against this invoice (refunds after checkout). */
  creditNotes?: { invoiceNumber: string; total: number; issuedAt: string | null }[];
}

function escapeHtml(value: unknown): string {
  if (value === null || value === undefined) {
    return '';
  }
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function money(value: number, currency: string): string {
  const n = Number(value);
  const formatted = Number.isFinite(n) ? n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00';
  return `${escapeHtml(currency)}${formatted}`;
}

function infoRow(label: string, value: string): string {
  if (!value) {
    return '';
  }
  return `
    <tr>
      <th scope="row">${escapeHtml(label)}</th>
      <td>${escapeHtml(value)}</td>
    </tr>`;
}

export function buildReservationInvoiceHtml(data: ReservationInvoicePrintData): string {
  const { labels: L } = data;
  const stayRange = `${data.checkIn} → ${data.checkOut}`;

  const lineRows = data.lineItems.length
    ? data.lineItems.map((row) => `
        <tr>
          <td>${escapeHtml(row.description)}</td>
          <td class="col-period">${escapeHtml(row.period)}</td>
          <td class="col-num">${row.nights}</td>
          <td class="col-money">${money(row.unitRate, L.currency)}</td>
          <td class="col-money col-strong">${money(row.amount, L.currency)}</td>
        </tr>`).join('')
    : `<tr><td colspan="5" class="empty-row">—</td></tr>`;

  const dailyRows = data.dailyLines.length
    ? data.dailyLines.map((line) => `
        <tr>
          <td>${escapeHtml(line.date)}</td>
          <td class="col-money">${money(line.amount, L.currency)}</td>
        </tr>`).join('')
    : '';

  const extraChargeRows = data.extraCharges.length
    ? data.extraCharges.map((line) => `
        <tr>
          <td>${escapeHtml(line.date)}</td>
          <td>${escapeHtml(line.description)}</td>
          <td>${escapeHtml(line.reference || '—')}</td>
          <td class="col-money col-strong">${money(line.amount, L.currency)}</td>
        </tr>`).join('')
    : '';

  const paymentRows = data.payments.length
    ? data.payments.map((p) => `
        <tr>
          <td>${escapeHtml(p.date)}</td>
          <td>${escapeHtml(p.method)}</td>
          <td>${escapeHtml(p.voucherNumber || '—')}</td>
          <td class="col-money col-strong">${money(p.amount, L.currency)}</td>
        </tr>`).join('')
    : `<tr><td colspan="4" class="empty-row">—</td></tr>`;

  const totalRow = (label: string, value: number, opts?: { strong?: boolean; accent?: boolean; muted?: boolean }) => {
    const cls = [
      'totals-row',
      opts?.strong ? 'totals-row--strong' : '',
      opts?.accent ? 'totals-row--accent' : '',
      opts?.muted ? 'totals-row--muted' : '',
    ].filter(Boolean).join(' ');
    return `
      <div class="${cls}">
        <span>${escapeHtml(label)}</span>
        <span>${money(value, L.currency)}</span>
      </div>`;
  };

  return `<!DOCTYPE html>
<html lang="${escapeHtml(data.lang)}" dir="${data.dir}">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>${escapeHtml(L.title)}</title>
  <style>
    :root {
      --ink: #0f172a;
      --muted: #64748b;
      --line: #e2e8f0;
      --brand: #0f4c4c;
      --brand-soft: #e6f5f3;
      --accent: #0d9488;
      --paper: #ffffff;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 24px;
      font-family: "Segoe UI", Tahoma, Arial, sans-serif;
      font-size: 13px;
      line-height: 1.45;
      color: var(--ink);
      background: #f1f5f9;
    }
    .invoice {
      max-width: 820px;
      margin: 0 auto;
      background: var(--paper);
      border: 1px solid var(--line);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 10px 40px rgba(15, 23, 42, 0.08);
    }
    .invoice-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 24px;
      padding: 28px 32px;
      background: linear-gradient(135deg, var(--brand) 0%, #2d4a73 100%);
      color: #fff;
    }
    .brand-name {
      font-size: 22px;
      font-weight: 700;
      letter-spacing: 0.02em;
      margin: 0 0 4px;
    }
    .brand-tag {
      margin: 0;
      opacity: 0.85;
      font-size: 12px;
    }
    .invoice-meta {
      text-align: end;
      min-width: 200px;
    }
    .invoice-meta h1 {
      margin: 0 0 8px;
      font-size: 20px;
      font-weight: 700;
    }
    .invoice-meta p {
      margin: 0 0 4px;
      font-size: 12px;
      opacity: 0.92;
    }
    .status-pill {
      display: inline-block;
      margin-top: 8px;
      padding: 4px 12px;
      border-radius: 999px;
      background: rgba(255,255,255,0.16);
      font-size: 11px;
      font-weight: 600;
    }
    .invoice-body { padding: 28px 32px 32px; }
    .section-title {
      margin: 0 0 12px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-bottom: 28px;
    }
    .info-card {
      border: 1px solid var(--line);
      border-radius: 10px;
      overflow: hidden;
      background: #fafbfc;
    }
    .info-card table {
      width: 100%;
      border-collapse: collapse;
    }
    .info-card th,
    .info-card td {
      padding: 10px 14px;
      border-bottom: 1px solid var(--line);
      vertical-align: top;
      text-align: start;
    }
    .info-card th {
      width: 38%;
      font-weight: 600;
      color: var(--muted);
      background: #f8fafc;
      font-size: 12px;
    }
    .info-card tr:last-child th,
    .info-card tr:last-child td { border-bottom: none; }
    table.data-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 8px;
      font-size: 12px;
    }
    .data-table thead th {
      background: var(--brand);
      color: #fff;
      font-weight: 600;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      padding: 10px 12px;
      text-align: start;
      border: 1px solid #16304f;
    }
    [dir="rtl"] .data-table thead th,
    [dir="rtl"] .data-table td,
    [dir="rtl"] .info-card th,
    [dir="rtl"] .info-card td { text-align: right; }
    .data-table tbody td {
      padding: 10px 12px;
      border: 1px solid var(--line);
      vertical-align: top;
    }
    .data-table tbody tr:nth-child(even) td { background: #f8fafc; }
    .col-period { white-space: nowrap; }
    .col-num { text-align: center; width: 64px; }
    .col-money { text-align: end; white-space: nowrap; width: 110px; }
    .col-strong { font-weight: 700; color: var(--ink); }
    .empty-row { text-align: center; color: var(--muted); padding: 16px !important; }
    .totals-wrap {
      display: flex;
      justify-content: flex-end;
      margin-top: 20px;
    }
    .totals-box {
      width: min(100%, 340px);
      border: 1px solid var(--line);
      border-radius: 10px;
      overflow: hidden;
      background: #fafbfc;
    }
    .totals-row {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      padding: 10px 16px;
      border-bottom: 1px solid var(--line);
      font-size: 13px;
    }
    .totals-row:last-child { border-bottom: none; }
    .totals-row--muted span:first-child { color: var(--muted); }
    .totals-row--strong {
      background: var(--brand-soft);
      font-weight: 700;
      font-size: 15px;
    }
    .totals-row--accent {
      background: #ecfdf5;
      color: #047857;
      font-weight: 700;
    }
    .invoice-footer {
      margin-top: 28px;
      padding-top: 16px;
      border-top: 1px dashed var(--line);
      text-align: center;
      color: var(--muted);
      font-size: 11px;
    }
    .tax-qr-wrap {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      margin: 0 0 16px;
    }
    .tax-qr {
      width: 160px;
      height: 160px;
      image-rendering: pixelated;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: #fff;
      padding: 6px;
    }
    .tax-qr-label {
      margin: 0;
      font-size: 12px;
      font-weight: 600;
      color: var(--ink);
    }
    .tax-invoice-number {
      margin: 0 0 10px;
      font-size: 13px;
      font-weight: 700;
      color: var(--ink);
      text-align: center;
    }
    .thank-you {
      margin: 0 0 6px;
      font-size: 14px;
      font-weight: 600;
      color: var(--accent);
    }
    .sub-section { margin-top: 24px; }
    @media print {
      body { background: #fff; padding: 0; }
      .invoice {
        max-width: none;
        border: none;
        border-radius: 0;
        box-shadow: none;
      }
      .invoice-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .data-table thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
    @page { size: A4 portrait; margin: 12mm; }
  </style>
</head>
<body>
  <div class="invoice">
    <header class="invoice-header">
      <div>
        <p class="brand-name">CHECKIN PMS</p>
        <p class="brand-tag">Hotel Management</p>
      </div>
      <div class="invoice-meta">
        <h1>${escapeHtml(L.title)}</h1>
        <p>${escapeHtml(L.issuedOn)}: ${escapeHtml(data.issuedOn)}</p>
        <span class="status-pill">${escapeHtml(data.statusLabel)}</span>
      </div>
    </header>

    <div class="invoice-body">
      <div class="info-grid">
        <div class="info-card">
          <table>
            <caption class="section-title" style="caption-side: top; padding: 12px 14px 0; text-align: start;">${escapeHtml(L.billTo)}</caption>
            <tbody>
              ${infoRow(L.guest, data.guestName)}
              ${infoRow(L.phone, data.guestPhone)}
              ${infoRow(L.email, data.guestEmail)}
            </tbody>
          </table>
        </div>
        <div class="info-card">
          <table>
            <caption class="section-title" style="caption-side: top; padding: 12px 14px 0; text-align: start;">${escapeHtml(L.stay)}</caption>
            <tbody>
              ${infoRow(L.room, data.roomLabel)}
              ${infoRow(L.stay, stayRange)}
              ${infoRow(L.nights, String(data.nightsCount))}
            </tbody>
          </table>
        </div>
      </div>

      <h2 class="section-title">${escapeHtml(L.lineItems)}</h2>
      <table class="data-table">
        <thead>
          <tr>
            <th>${escapeHtml(L.description)}</th>
            <th>${escapeHtml(L.period)}</th>
            <th class="col-num">${escapeHtml(L.nightsCol)}</th>
            <th class="col-money">${escapeHtml(L.unitRate)}</th>
            <th class="col-money">${escapeHtml(L.amount)}</th>
          </tr>
        </thead>
        <tbody>${lineRows}</tbody>
      </table>

      ${dailyRows ? `
      <div class="sub-section">
        <h2 class="section-title">${escapeHtml(L.dailyDetail)}</h2>
        <table class="data-table">
          <thead>
            <tr>
              <th>${escapeHtml(L.date)}</th>
              <th class="col-money">${escapeHtml(L.amount)}</th>
            </tr>
          </thead>
          <tbody>${dailyRows}</tbody>
        </table>
      </div>` : ''}

      ${extraChargeRows ? `
      <div class="sub-section">
        <h2 class="section-title">${escapeHtml(L.extraCharges)}</h2>
        <table class="data-table">
          <thead>
            <tr>
              <th>${escapeHtml(L.date)}</th>
              <th>${escapeHtml(L.description)}</th>
              <th>${escapeHtml(L.reference)}</th>
              <th class="col-money">${escapeHtml(L.amount)}</th>
            </tr>
          </thead>
          <tbody>${extraChargeRows}</tbody>
        </table>
      </div>` : ''}

      <div class="sub-section">
        <h2 class="section-title">${escapeHtml(L.paymentsReceived)}</h2>
        <table class="data-table">
          <thead>
            <tr>
              <th>${escapeHtml(L.date)}</th>
              <th>${escapeHtml(L.method)}</th>
              <th>${escapeHtml(L.voucherNumber)}</th>
              <th class="col-money">${escapeHtml(L.amount)}</th>
            </tr>
          </thead>
          <tbody>${paymentRows}</tbody>
        </table>
      </div>

      <div class="totals-wrap">
        <div class="totals-box">
          ${totalRow(L.accommodation, data.accommodationSubtotal, { muted: true })}
          ${totalRow(L.tax, data.tax, { muted: true })}
          ${data.classificationDiscount > 0.005 ? totalRow(L.classificationDiscount, -data.classificationDiscount, { muted: true }) : ''}
          ${data.additionalDiscount > 0.005 ? totalRow(L.additionalDiscount, -data.additionalDiscount, { muted: true }) : ''}
          ${data.classificationDiscount > 0.005 && data.additionalDiscount > 0.005 ? totalRow(L.totalDiscount, -data.discount, { muted: true }) : ''}
          ${data.discount > 0.005 && data.classificationDiscount <= 0.005 && data.additionalDiscount <= 0.005 ? totalRow(L.discount, -data.discount, { muted: true }) : ''}
          ${totalRow(L.total, data.total, { strong: true })}
          ${totalRow(L.paid, data.paid, { muted: true })}
          ${totalRow(L.balance, data.balance, { accent: true })}
        </div>
      </div>

      <footer class="invoice-footer">
        ${data.taxInvoiceNumber ? `<p class="tax-invoice-number">${escapeHtml(L.invoiceNumber)}: ${escapeHtml(data.taxInvoiceNumber)}</p>` : ''}
        ${data.creditNotes && data.creditNotes.length ? `
        <p class="tax-invoice-number">${escapeHtml(L.creditNotes)}: ${data.creditNotes.map((cn) => `${escapeHtml(cn.invoiceNumber)} (${money(cn.total, L.currency)})`).join(', ')}</p>` : ''}
        ${data.taxQrDataUrl ? `
        <div class="tax-qr-wrap">
          <img class="tax-qr" src="${escapeHtml(data.taxQrDataUrl)}" alt="${escapeHtml(L.taxQr)}" width="160" height="160" />
          <p class="tax-qr-label">${escapeHtml(L.taxQr)}</p>
        </div>` : ''}
        <p class="thank-you">${escapeHtml(L.thankYou)}</p>
        <p>${escapeHtml(L.footer)}</p>
      </footer>
    </div>
  </div>
  <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>`;
}

export function openReservationInvoicePrint(data: ReservationInvoicePrintData): boolean {
  const w = window.open('', '_blank');
  if (!w) {
    return false;
  }
  w.document.open();
  w.document.write(buildReservationInvoiceHtml(data));
  w.document.close();
  return true;
}
