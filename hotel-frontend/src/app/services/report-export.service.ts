import { Injectable } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import * as XLSX from 'xlsx';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import html2canvas from 'html2canvas';

export interface ExportColumn {
  key: string;
  label: string;
}

/** A4 portrait (~210×297mm at 96dpi) */
const A4_PORTRAIT_HTML_WIDTH_PX = 794;
const A4_PORTRAIT_HTML_HEIGHT_PX = 1123;
const A4_PDF_MARGIN_PT = 32;
const PDF_ROWS_PER_PAGE = 25;

@Injectable({ providedIn: 'root' })
export class ReportExportService {
  constructor(private translate: TranslateService) {}

  private isArabic(): boolean {
    const lang =
      this.translate.currentLang ||
      this.translate.defaultLang ||
      (typeof localStorage !== 'undefined' ? localStorage.getItem('language') : null) ||
      (typeof document !== 'undefined' ? document.documentElement.lang : null) ||
      'en';
    return lang === 'ar' || lang.startsWith('ar');
  }

  exportCsv(columns: ExportColumn[], rows: Record<string, unknown>[], filename: string): void {
    if (this.shouldUseExcelXmlExport(columns, rows)) {
      this.exportSpreadsheetXml(columns, rows, filename);
      return;
    }

    const delimiter = this.csvDelimiter();
    const header = columns.map(c => this.escapeCsv(this.formatExportCell(c.label), delimiter)).join(delimiter);
    const body = rows.map(row =>
      columns.map(c => this.escapeCsv(this.formatExportCell(row[c.key]), delimiter)).join(delimiter)
    );
    const content = [header, ...body].join('\r\n');
    const encoded = new TextEncoder().encode('\uFEFF' + content);
    const blob = new Blob([encoded], { type: 'text/csv;charset=utf-8;' });
    this.downloadBlob(blob, `${filename}.csv`);
  }

  saveBlob(blob: Blob, filename: string): void {
    this.downloadBlob(blob, filename);
  }

  exportExcel(
    columns: ExportColumn[],
    rows: Record<string, unknown>[],
    filename: string,
    sheetName = 'Report'
  ): void {
    const data = rows.map(row => {
      const line: Record<string, string | number> = {};
      columns.forEach(c => {
        line[c.label] = this.cellValue(row[c.key]);
      });
      return line;
    });
    const ws = XLSX.utils.json_to_sheet(data);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, sheetName.substring(0, 31));
    XLSX.writeFile(wb, `${filename}.xlsx`);
  }

  exportPdf(
    title: string,
    columns: ExportColumn[],
    rows: Record<string, unknown>[],
    filename: string,
    summaryLines: string[] = []
  ): void {
    if (this.isArabic()) {
      const body = rows.map(row => columns.map(c => String(this.cellValue(row[c.key]))));
      void this.exportPdfFromHtmlPages(
        this.buildPaginatedTablePages(title, summaryLines, columns.map(c => c.label), body, '#0f4c4c'),
        filename
      );
      return;
    }

    const doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    const body = rows.map(row => columns.map(c => String(this.cellValue(row[c.key]))));
    this.renderPaginatedAutoTable(doc, {
      title,
      periodLine: summaryLines.join(' · '),
      sectionTitle: title,
      headers: columns.map(c => c.label),
      body,
      headColor: [15, 76, 76] as [number, number, number],
      includeCoverMeta: summaryLines
    });
    doc.save(`${filename}.pdf`);
  }

  exportFinancialDashboardPdf(payload: {
    title: string;
    periodLine: string;
    compareLine?: string;
    generatedAt: string;
    kpis: { label: string; value: string }[];
    seriesColumns: ExportColumn[];
    seriesRows: Record<string, unknown>[];
    transactionColumns: ExportColumn[];
    transactionRows: Record<string, unknown>[];
    filename: string;
  }): Promise<void> {
    if (this.isArabic()) {
      return this.exportPdfFromHtmlPages(
        this.buildFinancialDashboardPages(payload),
        payload.filename
      );
    }

    const doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    this.renderFinancialDashboardJsPdf(doc, payload);
    doc.save(`${payload.filename}.pdf`);
    return Promise.resolve();
  }

  exportFinancialDashboardExcel(payload: {
    filename: string;
    summaryColumns: ExportColumn[];
    summaryRows: Record<string, unknown>[];
    seriesColumns: ExportColumn[];
    seriesRows: Record<string, unknown>[];
    transactionColumns: ExportColumn[];
    transactionRows: Record<string, unknown>[];
    sheetLabels: { summary: string; series: string; transactions: string };
  }): void {
    this.exportMultiSheetExcel(
      [
        {
          name: payload.sheetLabels.summary,
          columns: payload.summaryColumns,
          rows: payload.summaryRows
        },
        {
          name: payload.sheetLabels.series,
          columns: payload.seriesColumns,
          rows: payload.seriesRows
        },
        {
          name: payload.sheetLabels.transactions,
          columns: payload.transactionColumns,
          rows: payload.transactionRows
        }
      ],
      payload.filename
    );
  }

  exportMultiSheetExcel(
    sheets: { name: string; columns: ExportColumn[]; rows: Record<string, unknown>[] }[],
    filename: string
  ): void {
    const wb = XLSX.utils.book_new();
    sheets.forEach(sheet => {
      const data = sheet.rows.map(row => {
        const line: Record<string, string | number> = {};
        sheet.columns.forEach(c => {
          line[c.label] = this.cellValue(row[c.key]);
        });
        return line;
      });
      const ws = XLSX.utils.json_to_sheet(data);
      XLSX.utils.book_append_sheet(wb, ws, sheet.name.substring(0, 31));
    });
    XLSX.writeFile(wb, `${filename}.xlsx`);
  }

  private async exportPdfFromHtmlPages(pageHtmlList: string[], filename: string): Promise<void> {
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();

    await document.fonts.ready;

    for (let i = 0; i < pageHtmlList.length; i++) {
      const host = document.createElement('div');
      host.className = 'pdf-export-host';
      host.innerHTML = pageHtmlList[i] + this.pdfExportStyles(A4_PORTRAIT_HTML_WIDTH_PX);
      host.style.position = 'fixed';
      host.style.left = '-10000px';
      host.style.top = '0';
      host.style.zIndex = '-1';
      host.style.background = '#ffffff';
      document.body.appendChild(host);

      try {
        await new Promise(r => setTimeout(r, 120));
        const canvas = await html2canvas(host.firstElementChild as HTMLElement, {
          scale: 2,
          useCORS: true,
          backgroundColor: '#ffffff',
          logging: false,
          width: A4_PORTRAIT_HTML_WIDTH_PX,
          height: A4_PORTRAIT_HTML_HEIGHT_PX
        });

        const imgData = canvas.toDataURL('image/png');
        if (i > 0) {
          pdf.addPage('a4', 'p');
        }
        pdf.addImage(imgData, 'PNG', 0, 0, pageWidth, pageHeight);
      } finally {
        document.body.removeChild(host);
      }
    }

    pdf.save(`${filename}.pdf`);
  }

  private buildFinancialDashboardPages(payload: {
    title: string;
    periodLine: string;
    compareLine?: string;
    generatedAt: string;
    kpis: { label: string; value: string }[];
    seriesColumns: ExportColumn[];
    seriesRows: Record<string, unknown>[];
    transactionColumns: ExportColumn[];
    transactionRows: Record<string, unknown>[];
  }): string[] {
    const pages: string[] = [];
    const meta = {
      title: payload.title,
      periodLine: payload.periodLine,
      compareLine: payload.compareLine,
      generatedAt: payload.generatedAt
    };
    const kpiSection = this.isArabic() ? 'ملخص المؤشرات' : 'Summary';
    const seriesSection = this.isArabic() ? 'السلسلة الزمنية' : 'Time series';
    const txSection = this.isArabic() ? 'المعاملات' : 'Transactions';

    const kpiBody = payload.kpis.map(k => [k.label, k.value]);
    this.chunkArray(kpiBody, PDF_ROWS_PER_PAGE).forEach((chunk, idx, arr) => {
      pages.push(this.buildHtmlPage({
        ...meta,
        showFullMeta: idx === 0,
        sectionTitle: kpiSection,
        pageLabel: this.pageLabel(idx + 1, arr.length),
        headers: [this.isArabic() ? 'المؤشر' : 'Metric', this.isArabic() ? 'القيمة' : 'Value'],
        body: chunk,
        headColor: '#0f4c4c'
      }));
    });

    const seriesBody = payload.seriesRows.map(row =>
      payload.seriesColumns.map(c => String(this.cellValue(row[c.key])))
    );
    const seriesHeaders = payload.seriesColumns.map(c => c.label);
    this.chunkArray(seriesBody, PDF_ROWS_PER_PAGE).forEach((chunk, idx, arr) => {
      pages.push(this.buildHtmlPage({
        ...meta,
        showFullMeta: false,
        sectionTitle: seriesSection,
        pageLabel: this.pageLabel(idx + 1, arr.length),
        headers: seriesHeaders,
        body: chunk,
        headColor: '#6366f1'
      }));
    });

    const txBody = payload.transactionRows.map(row =>
      payload.transactionColumns.map(c => String(this.cellValue(row[c.key])))
    );
    const txHeaders = payload.transactionColumns.map(c => c.label);
    this.chunkArray(txBody, PDF_ROWS_PER_PAGE).forEach((chunk, idx, arr) => {
      pages.push(this.buildHtmlPage({
        ...meta,
        showFullMeta: false,
        sectionTitle: txSection,
        pageLabel: this.pageLabel(idx + 1, arr.length),
        headers: txHeaders,
        body: chunk,
        headColor: '#10b981'
      }));
    });

    if (pages.length === 0) {
      pages.push(this.buildHtmlPage({
        ...meta,
        showFullMeta: true,
        sectionTitle: payload.title,
        pageLabel: '',
        headers: [this.isArabic() ? 'ملاحظة' : 'Note'],
        body: [[this.isArabic() ? 'لا توجد بيانات' : 'No data']],
        headColor: '#0f4c4c'
      }));
    }

    return pages;
  }

  private buildPaginatedTablePages(
    title: string,
    summaryLines: string[],
    headers: string[],
    body: string[][],
    headColor: string
  ): string[] {
    const chunks = this.chunkArray(body, PDF_ROWS_PER_PAGE);
    const periodLine = summaryLines.join(' · ') || title;
    if (!chunks.length) {
      return [
        this.buildHtmlPage({
          title,
          periodLine,
          generatedAt: new Date().toLocaleString(this.isArabic() ? 'ar' : 'en'),
          showFullMeta: true,
          sectionTitle: title,
          pageLabel: '',
          headers,
          body: [[this.isArabic() ? 'لا توجد بيانات' : 'No data']],
          headColor
        })
      ];
    }
    return chunks.map((chunk, idx, arr) =>
      this.buildHtmlPage({
        title,
        periodLine,
        compareLine: undefined,
        generatedAt: new Date().toLocaleString(this.isArabic() ? 'ar' : 'en'),
        showFullMeta: idx === 0,
        sectionTitle: title,
        pageLabel: this.pageLabel(idx + 1, arr.length),
        headers,
        body: chunk,
        headColor
      })
    );
  }

  private buildHtmlPage(opts: {
    title: string;
    periodLine: string;
    compareLine?: string;
    generatedAt: string;
    showFullMeta: boolean;
    sectionTitle: string;
    pageLabel: string;
    headers: string[];
    body: string[][];
    headColor: string;
  }): string {
    const dir = this.isArabic() ? 'rtl' : 'ltr';
    const lang = this.isArabic() ? 'ar' : 'en';
    const metaBlock = opts.showFullMeta
      ? `
        <p class="pdf-export-meta">${this.escapeHtml(opts.periodLine)}</p>
        ${opts.compareLine ? `<p class="pdf-export-meta pdf-export-meta--muted">${this.escapeHtml(opts.compareLine)}</p>` : ''}
        <p class="pdf-export-meta pdf-export-meta--muted">${this.escapeHtml(opts.generatedAt)}</p>
      `
      : `<p class="pdf-export-meta pdf-export-meta--muted">${this.escapeHtml(opts.periodLine)}</p>`;

    const sectionTitle = opts.pageLabel
      ? `${opts.sectionTitle} — ${opts.pageLabel}`
      : opts.sectionTitle;

    return `
      <div class="pdf-export-page" dir="${dir}" lang="${lang}">
        <div class="pdf-export-page__body">
          <div class="pdf-export-header">
            <div class="pdf-export-brand">CHECKIN PMS</div>
            <h1 class="pdf-export-title">${this.escapeHtml(opts.title)}</h1>
          </div>
          ${metaBlock}
          <h2 class="pdf-export-section">${this.escapeHtml(sectionTitle)}</h2>
          <div class="pdf-export-table-wrap">
            ${this.htmlTable(opts.headers, opts.body, opts.headColor)}
          </div>
        </div>
      </div>
    `;
  }

  private renderFinancialDashboardJsPdf(
    doc: jsPDF,
    payload: {
      title: string;
      periodLine: string;
      compareLine?: string;
      generatedAt: string;
      kpis: { label: string; value: string }[];
      seriesColumns: ExportColumn[];
      seriesRows: Record<string, unknown>[];
      transactionColumns: ExportColumn[];
      transactionRows: Record<string, unknown>[];
    }
  ): void {
    const meta = [payload.periodLine, payload.compareLine, payload.generatedAt].filter(Boolean) as string[];

    this.renderPaginatedAutoTable(doc, {
      title: payload.title,
      periodLine: payload.periodLine,
      sectionTitle: 'Summary',
      headers: ['Metric', 'Value'],
      body: payload.kpis.map(k => [k.label, k.value]),
      headColor: [15, 76, 76],
      includeCoverMeta: meta,
      newPage: false
    });

    const seriesBody = payload.seriesRows.map(row =>
      payload.seriesColumns.map(c => String(this.cellValue(row[c.key])))
    );
    this.chunkArray(seriesBody, PDF_ROWS_PER_PAGE).forEach((chunk, idx) => {
      this.renderPaginatedAutoTable(doc, {
        title: payload.title,
        periodLine: payload.periodLine,
        sectionTitle: `Time series (${idx + 1})`,
        headers: payload.seriesColumns.map(c => c.label),
        body: chunk,
        headColor: [99, 102, 241],
        newPage: true
      });
    });

    const txBody = payload.transactionRows.map(row =>
      payload.transactionColumns.map(c => String(this.cellValue(row[c.key])))
    );
    this.chunkArray(txBody, PDF_ROWS_PER_PAGE).forEach((chunk, idx) => {
      this.renderPaginatedAutoTable(doc, {
        title: payload.title,
        periodLine: payload.periodLine,
        sectionTitle: `Transactions (${idx + 1})`,
        headers: payload.transactionColumns.map(c => c.label),
        body: chunk,
        headColor: [16, 185, 129],
        newPage: true
      });
    });
  }

  private renderPaginatedAutoTable(
    doc: jsPDF,
    opts: {
      title: string;
      periodLine: string;
      sectionTitle: string;
      headers: string[];
      body: string[][];
      headColor: [number, number, number];
      includeCoverMeta?: string[];
      newPage?: boolean;
    }
  ): void {
    if (opts.newPage) {
      doc.addPage('a4', 'p');
    }

    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const margin = 40;
    const contentWidth = pageWidth - margin * 2;
    const rowCount = Math.max(opts.body.length, 1);
    const headerBlock = opts.includeCoverMeta ? 120 : 72;
    const tableHead = 28;
    const footer = 24;
    const bodyHeight = pageHeight - margin * 2 - headerBlock - tableHead - footer;
    const minCellHeight = this.autoTableMinCellHeight(rowCount, bodyHeight);
    const fontSize = rowCount <= 10 ? 10 : rowCount <= 18 ? 9 : 8;

    doc.setFillColor(15, 76, 76);
    doc.rect(0, 0, pageWidth, 48, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(13);
    doc.text(opts.title, pageWidth / 2, 22, { align: 'center' });
    doc.setFontSize(8);
    doc.text(opts.sectionTitle, pageWidth / 2, 38, { align: 'center' });

    let y = 58;
    doc.setTextColor(60, 60, 60);
    doc.setFontSize(9);
    doc.text(opts.periodLine, margin, y, { align: 'left', maxWidth: contentWidth });
    y += 12;

    if (opts.includeCoverMeta?.length) {
      opts.includeCoverMeta.slice(1).forEach(line => {
        doc.setFontSize(8);
        doc.setTextColor(100, 100, 100);
        doc.text(line, margin, y, { align: 'left', maxWidth: contentWidth });
        y += 11;
      });
      y += 4;
    }

    autoTable(doc, {
      startY: y,
      head: [opts.headers],
      body: opts.body,
      theme: 'striped',
      tableWidth: contentWidth,
      margin: { left: margin, right: margin, bottom: margin },
      styles: {
        fontSize,
        cellPadding: 6,
        minCellHeight,
        overflow: 'linebreak',
        valign: 'middle',
        halign: 'center'
      },
      headStyles: {
        fillColor: opts.headColor,
        textColor: 255,
        fontSize: fontSize + 1,
        minCellHeight: 22,
        halign: 'center'
      },
      alternateRowStyles: { fillColor: [248, 250, 252] }
    });
  }

  private chunkArray<T>(items: T[], size: number): T[][] {
    if (!items.length) {
      return [];
    }
    const chunks: T[][] = [];
    for (let i = 0; i < items.length; i += size) {
      chunks.push(items.slice(i, i + size));
    }
    return chunks;
  }

  private pageLabel(index: number, total: number): string {
    if (total <= 1) {
      return '';
    }
    return this.isArabic()
      ? `صفحة ${index} من ${total}`
      : `Page ${index} of ${total}`;
  }

  private htmlTable(headers: string[], body: string[][], headColor: string): string {
    const rowCount = Math.max(body.length, 1);
    const fontSize = rowCount <= 8 ? 11 : rowCount <= 15 ? 10 : rowCount <= 22 ? 9 : 8;
    const th = headers.map(h => `<th>${this.escapeHtml(h)}</th>`).join('');
    const trs = body
      .map(
        row =>
          `<tr>${row
            .map(cell => `<td>${this.escapeHtml(cell)}</td>`)
            .join('')}</tr>`
      )
      .join('');
    return `
      <table class="pdf-export-table" style="font-size:${fontSize}px">
        <thead style="background:${headColor}"><tr>${th}</tr></thead>
        <tbody>${trs}</tbody>
      </table>
    `;
  }

  private autoTableMinCellHeight(rowCount: number, bodyHeight: number): number {
    const rowsOnPage = Math.min(Math.max(rowCount, 1), PDF_ROWS_PER_PAGE);
    const distributed = Math.floor(bodyHeight / rowsOnPage);
    return Math.min(Math.max(distributed, 20), 32);
  }

  private pdfExportStyles(widthPx: number): string {
    return `
      <style>
        .pdf-export-page {
          width: ${widthPx}px;
          height: ${A4_PORTRAIT_HTML_HEIGHT_PX}px;
          max-height: ${A4_PORTRAIT_HTML_HEIGHT_PX}px;
          overflow: hidden;
          box-sizing: border-box;
          background: #fff;
          font-family: 'Noto Sans Arabic', 'Segoe UI', Tahoma, Arial, sans-serif;
          color: #1a1a1a;
        }
        .pdf-export-page__body {
          height: 100%;
          padding: 20px 24px 24px;
          box-sizing: border-box;
          display: flex;
          flex-direction: column;
        }
        .pdf-export-header {
          background: linear-gradient(135deg, #0f4c4c 0%, #1e5c5c 100%);
          color: #fff;
          padding: 14px 18px;
          border-radius: 8px;
          margin-bottom: 12px;
          text-align: center;
          flex-shrink: 0;
        }
        .pdf-export-brand {
          font-size: 10px;
          opacity: 0.9;
        }
        .pdf-export-title {
          margin: 4px 0 0;
          font-size: 17px;
          font-weight: 700;
          line-height: 1.3;
        }
        .pdf-export-meta {
          margin: 0 0 5px;
          font-size: 10px;
          line-height: 1.4;
          flex-shrink: 0;
        }
        .pdf-export-meta--muted { color: #64748b; font-size: 9px; }
        .pdf-export-section {
          font-size: 12px;
          font-weight: 700;
          margin: 8px 0 10px;
          color: #0f4c4c;
          border-right: 4px solid #0f4c4c;
          padding-right: 8px;
          flex-shrink: 0;
        }
        [dir="ltr"] .pdf-export-section {
          border-right: none;
          border-left: 4px solid #0f4c4c;
          padding-right: 0;
          padding-left: 8px;
        }
        .pdf-export-table-wrap {
          flex: 1;
          display: flex;
          flex-direction: column;
          align-items: center;
          min-height: 0;
        }
        .pdf-export-table {
          width: auto;
          max-width: 100%;
          height: auto;
          table-layout: auto;
          border-collapse: collapse;
          margin-inline: auto;
        }
        .pdf-export-table th {
          color: #fff;
          padding: 8px 10px;
          text-align: center;
          font-weight: 600;
          vertical-align: middle;
          white-space: nowrap;
        }
        .pdf-export-table td {
          border: 1px solid #e2e8f0;
          padding: 8px 10px;
          text-align: center;
          vertical-align: middle;
          white-space: nowrap;
        }
        .pdf-export-table tbody tr:nth-child(even) { background: #f8fafc; }
      </style>
    `;
  }

  private escapeHtml(value: string): string {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  private cellValue(value: unknown): string | number {
    const formatted = this.formatExportCell(value);
    if (typeof formatted === 'number') {
      return formatted;
    }
    return formatted;
  }

  /** Normalize API values for CSV/Excel (ASCII dates, no locale symbols). */
  formatExportCell(value: unknown): string | number {
    if (value === null || value === undefined) {
      return '';
    }
    if (typeof value === 'number' && Number.isFinite(value)) {
      return value;
    }
    if (value instanceof Date) {
      return this.formatDateTimeParts(
        value.getFullYear(),
        value.getMonth() + 1,
        value.getDate(),
        value.getHours(),
        value.getMinutes(),
        value.getSeconds()
      );
    }

    const s = String(value).trim();
    if (s === '') {
      return '';
    }

    const isoDateTime = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})/.exec(s);
    if (isoDateTime) {
      return `${isoDateTime[1]}-${isoDateTime[2]}-${isoDateTime[3]} ${isoDateTime[4]}:${isoDateTime[5]}:${isoDateTime[6]}`;
    }

    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
      return s;
    }

    return s;
  }

  private formatDateTimeParts(
    year: number,
    month: number,
    day: number,
    hour: number,
    minute: number,
    second: number
  ): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${year}-${pad(month)}-${pad(day)} ${pad(hour)}:${pad(minute)}:${pad(second)}`;
  }

  private csvDelimiter(): string {
    return ',';
  }

  /**
   * Excel on Arabic Windows often garbles UTF-8/UTF-16 CSV files.
   * SpreadsheetML (.xls) preserves Arabic headers, dates, and numbers reliably.
   */
  private shouldUseExcelXmlExport(columns: ExportColumn[], rows: Record<string, unknown>[]): boolean {
    if (this.isArabic()) {
      return true;
    }

    const arabicPattern = /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF]/;
    if (columns.some(c => arabicPattern.test(c.label))) {
      return true;
    }

    return rows.some(row =>
      columns.some(c => arabicPattern.test(String(row[c.key] ?? '')))
    );
  }

  private exportSpreadsheetXml(
    columns: ExportColumn[],
    rows: Record<string, unknown>[],
    filename: string
  ): void {
    const cell = (value: unknown) => {
      const text = String(this.formatExportCell(value));
      return `<Cell ss:StyleID="Text"><Data ss:Type="String">${this.escapeXml(text)}</Data></Cell>`;
    };

    const headerRow = `<Row>${columns.map(c => cell(c.label)).join('')}</Row>`;
    const bodyRows = rows
      .map(row => `<Row>${columns.map(c => cell(row[c.key])).join('')}</Row>`)
      .join('');

    const xml = `<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Styles>
  <Style ss:ID="Text"><NumberFormat ss:Format="@"/></Style>
</Styles>
<Worksheet ss:Name="Report">
<Table>
${headerRow}
${bodyRows}
</Table>
</Worksheet>
</Workbook>`;

    const blob = new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    this.downloadBlob(blob, `${filename}.xls`);
  }

  private escapeXml(value: string): string {
    return value
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  private escapeCsv(value: string | number, delimiter: string): string {
    const s = String(value);
    const mustQuote =
      s.includes(delimiter) ||
      s.includes('"') ||
      s.includes('\n') ||
      s.includes('\r') ||
      /^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/.test(s) ||
      /[^\x00-\x7F]/.test(s);

    if (mustQuote) {
      return `"${s.replace(/"/g, '""')}"`;
    }
    return s;
  }

  private downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
  }
}
