import { TestBed } from '@angular/core/testing';
import { TranslateService } from '@ngx-translate/core';
import { ReportExportService } from './report-export.service';

describe('ReportExportService', () => {
  let service: ReportExportService;
  let currentLang = 'en';

  beforeEach(() => {
    currentLang = 'en';
    TestBed.configureTestingModule({
      providers: [
        ReportExportService,
        {
          provide: TranslateService,
          useValue: {
            get currentLang() {
              return currentLang;
            },
            defaultLang: 'en',
          },
        },
      ],
    });
    service = TestBed.inject(ReportExportService);
  });

  describe('formatExportCell', () => {
    it('normalizes ISO datetime to YYYY-MM-DD HH:mm:ss', () => {
      expect(service.formatExportCell('2026-08-15T12:30:45.000000Z')).toBe('2026-08-15 12:30:45');
      expect(service.formatExportCell('2026-08-15 12:30:45')).toBe('2026-08-15 12:30:45');
    });

    it('keeps date-only values unchanged', () => {
      expect(service.formatExportCell('2026-08-15')).toBe('2026-08-15');
    });

    it('formats Date objects as ASCII datetime', () => {
      const d = new Date(2026, 7, 15, 9, 5, 3);
      expect(service.formatExportCell(d)).toBe('2026-08-15 09:05:03');
    });
  });

  describe('exportCsv', () => {
    let downloadedBlob: Blob | null = null;
    let downloadedFilename = '';

    beforeEach(() => {
      downloadedBlob = null;
      downloadedFilename = '';
      spyOn(URL, 'createObjectURL').and.returnValue('blob:test');
      spyOn(URL, 'revokeObjectURL');
      spyOn(HTMLAnchorElement.prototype, 'click');
      spyOn<any>(service, 'downloadBlob').and.callFake((blob: Blob, filename: string) => {
        downloadedBlob = blob;
        downloadedFilename = filename;
      });
    });

    it('uses UTF-8 CSV for English', async () => {
      service.exportCsv(
        [{ key: 'charge_date', label: 'Date' }],
        [{ charge_date: '2026-08-15' }],
        'revenue-summary'
      );

      const bytes = new Uint8Array(await downloadedBlob!.arrayBuffer());
      const blobContent = await downloadedBlob!.text();
      expect(downloadedFilename).toBe('revenue-summary.csv');
      expect(bytes[0]).toBe(0xef);
      expect(bytes[1]).toBe(0xbb);
      expect(bytes[2]).toBe(0xbf);
      expect(downloadedBlob!.type).toBe('text/csv;charset=utf-8;');
      expect(blobContent).toContain('Date');
      expect(blobContent).toContain('"2026-08-15"');
    });

    it('uses SpreadsheetML .xls for Arabic locale (revenue-summary columns)', async () => {
      currentLang = 'ar';
      service.exportCsv(
        [
          { key: 'charge_date', label: 'التاريخ' },
          { key: 'revenue', label: 'الإجمالي' },
        ],
        [{ charge_date: '2026-08-15', revenue: 1250.5 }],
        'revenue-summary'
      );

      const blobContent = await downloadedBlob!.text();
      expect(downloadedFilename).toBe('revenue-summary.xls');
      expect(downloadedBlob!.type).toBe('application/vnd.ms-excel;charset=utf-8;');
      expect(blobContent).toContain('<?xml version="1.0" encoding="UTF-8"?>');
      expect(blobContent).toContain('التاريخ');
      expect(blobContent).toContain('الإجمالي');
      expect(blobContent).toContain('2026-08-15');
      expect(blobContent).toContain('1250.5');
    });
  });

  describe('pdf table layout', () => {
    it('caps row height for sparse reports like trial balance', () => {
      const height = (service as unknown as { autoTableMinCellHeight: (rowCount: number, bodyHeight: number) => number })
        .autoTableMinCellHeight(4, 900);

      expect(height).toBeGreaterThanOrEqual(20);
      expect(height).toBeLessThanOrEqual(32);
    });
  });
});
