import {
  reservationSubtotal,
  reservationTax,
  reservationTotal,
  RESERVATION_TAX_RATE
} from './finance-formulas';

describe('finance-formulas (UI parity with backend)', () => {
  it('uses 15% tax rate', () => {
    expect(RESERVATION_TAX_RATE).toBe(0.15);
  });

  it('computes subtotal from base, discount, extras, penalties', () => {
    expect(reservationSubtotal(1000, 100, 50, 25)).toBe(975);
  });

  it('computes tax on subtotal', () => {
    expect(reservationTax(1000)).toBe(150);
    expect(reservationTax(975)).toBe(146.25);
  });

  it('computes total', () => {
    expect(reservationTotal(1000, 150)).toBe(1150);
  });

  it('matches typical reservation breakdown', () => {
    const base = 4705.81;
    const subtotal = reservationSubtotal(base, 0, 0, 0);
    const tax = reservationTax(subtotal);
    const total = reservationTotal(subtotal, tax);
    expect(subtotal).toBe(4705.81);
    expect(tax).toBe(705.87);
    expect(total).toBe(5411.68);
  });
});
