import { resolveFinancialPeriod } from './financial-period';

describe('financial-period', () => {
  afterEach(() => {
    jasmine.clock().uninstall();
  });

  it('resolves last_year as previous calendar year', () => {
    jasmine.clock().install();
    jasmine.clock().mockDate(new Date(2026, 4, 15));
    const p = resolveFinancialPeriod('last_year');
    expect(p.start).toBe('2025-01-01');
    expect(p.end).toBe('2025-12-31');
    expect(p.labelParams?.['year']).toBe(2025);
  });

  it('resolves month from monthValue', () => {
    const p = resolveFinancialPeriod('month', { monthValue: '2026-05' });
    expect(p.start).toBe('2026-05-01');
    expect(p.end).toBe('2026-05-31');
  });
});
