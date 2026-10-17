import {
  breakdownNightlyRate,
  breakdownRowAmount,
  breakdownRowExpandable,
  buildBreakdownFromDailyLines,
  dailyLinesForPeriod,
} from './reservation-price-breakdown';

describe('reservation-price-breakdown', () => {
  const dailyLines = [
    { date: '2026-06-01', amount: 100 },
    { date: '2026-06-02', amount: 100 },
    { date: '2026-06-03', amount: 120 },
  ];

  it('filters daily lines within a breakdown period', () => {
    const row = { from: '2026-06-01', to: '2026-06-02', nights: 2, kind: 'daily' as const, amount: 200 };
    expect(dailyLinesForPeriod(dailyLines, row)).toEqual([
      { date: '2026-06-01', amount: 100 },
      { date: '2026-06-02', amount: 100 },
    ]);
  });

  it('marks multi-night rows as expandable', () => {
    const segments = buildBreakdownFromDailyLines(dailyLines, false);
    expect(breakdownRowExpandable(dailyLines, segments[0])).toBeTrue();
    expect(breakdownRowExpandable(dailyLines, segments[1])).toBeFalse();
  });

  it('derives nightly rate from daily lines and rounds to one decimal', () => {
    const row = {
      from: '2026-09-01',
      to: '2026-09-30',
      nights: 30,
      kind: 'monthly' as const,
      amount: 2289.6,
      billing_source: 'plan',
    };
    const lines = Array.from({ length: 30 }, (_, index) => ({
      date: `2026-09-${String(index + 1).padStart(2, '0')}`,
      amount: 76.66,
    }));

    expect(breakdownNightlyRate(row, lines)).toBe(76.7);
    expect(breakdownRowAmount(row, lines)).toBe(2299.8);
  });

  it('splits plan monthly proration from plan daily extra nights', () => {
    const planMonthlyLines = Array.from({ length: 15 }, (_, index) => ({
      date: `2026-09-${String(index + 15).padStart(2, '0')}`,
      amount: 93.33,
      price_source: 'plan',
    }));
    const planDailyLines = [
      { date: '2026-09-30', amount: 75, price_source: 'plan' },
      ...Array.from({ length: 14 }, (_, index) => ({
        date: `2026-10-${String(index + 1).padStart(2, '0')}`,
        amount: 75,
        price_source: 'plan',
      })),
    ];
    const dailyLines = [...planMonthlyLines, ...planDailyLines];

    const segments = buildBreakdownFromDailyLines(dailyLines, true);
    expect(segments.length).toBe(2);
    expect(segments[0].from).toBe('2026-09-15');
    expect(segments[0].to).toBe('2026-09-29');
    expect(segments[0].kind).toBe('monthly');
    expect(breakdownNightlyRate(segments[0], dailyLines)).toBe(93.3);
    expect(segments[1].from).toBe('2026-09-30');
    expect(segments[1].to).toBe('2026-10-14');
    expect(segments[1].kind).toBe('daily');
    expect(breakdownNightlyRate(segments[1], dailyLines)).toBe(75);
  });

  it('labels prorated monthly room-type chunks as monthly even under 28 nights', () => {
    const start = new Date('2026-08-31T12:00:00');
    const dailyLines = Array.from({ length: 14 }, (_, index) => {
      const date = new Date(start);
      date.setDate(date.getDate() + index);
      return {
        date: date.toISOString().slice(0, 10),
        amount: 116.67,
        price_source: 'max',
      };
    });

    const segments = buildBreakdownFromDailyLines(dailyLines, true);
    expect(segments.length).toBe(1);
    expect(segments[0].kind).toBe('monthly');
  });
});
