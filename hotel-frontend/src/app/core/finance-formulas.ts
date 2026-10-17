/** Mirrors backend reservation tax rules (15% VAT, half-up). */
export const RESERVATION_TAX_RATE = 0.15;

export function reservationSubtotal(
  basePrice: number,
  discount: number,
  extras: number,
  penalties: number
): number {
  return roundMoney(basePrice - discount + extras + penalties);
}

export function reservationTax(subtotal: number): number {
  return roundMoney(subtotal * RESERVATION_TAX_RATE);
}

export function reservationTotal(subtotal: number, taxes: number): number {
  return roundMoney(subtotal + taxes);
}

export function roundMoney(value: number): number {
  return Math.round((value + Number.EPSILON) * 100) / 100;
}
