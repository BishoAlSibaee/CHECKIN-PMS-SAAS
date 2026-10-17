import { expect, Page } from '@playwright/test';

export const API_BASE = 'http://127.0.0.1:8001/api';
export const REVERB_PORT = 8080;

export const DEMO_BOARD_DATES = ['2026-08-15', '2026-08-01', '2026-08-20', '2026-08-10', '2026-06-28', '2026-07-01'];

async function reservationBalanceViaApi(page: Page, reservationId: number): Promise<number> {
  const token = await apiToken(page);
  const res = await page.request.get(`${API_BASE}/users/reservations/${reservationId}`, {
    headers: authHeaders(token),
  });
  if (!res.ok()) {
    return 0;
  }
  const body = await res.json();
  return Number(body.data?.balance_due ?? 0);
}

function e2eStayDates(kind: 'checkin' | 'payment'): { start: string; end: string } {
  const dayOffset = Math.floor(Date.now() / 86_400_000) % 80;
  const month = kind === 'checkin' ? 2 : 4;
  const start = new Date(Date.UTC(2027, month, 10 + dayOffset));
  const end = new Date(start);
  end.setUTCDate(end.getUTCDate() + 3);
  return {
    start: start.toISOString().slice(0, 10),
    end: end.toISOString().slice(0, 10),
  };
}

async function createE2EProvisionedReservation(
  page: Page,
  kind: 'checkin' | 'payment'
): Promise<{ reservationId: number; roomNumber: string; start: string; end: string }> {
  await ensureLoggedIn(page);
  const token = await apiToken(page);
  const headers = authHeaders(token);
  const { start, end } = kind === 'checkin' ? e2eCheckInStayDates() : e2eStayDates('payment');

  const availRes = await page.request.get(
    `${API_BASE}/users/booking-room-availability?start_date=${start}&expire_date=${end}&building_id=1`,
    { headers }
  );
  expect(availRes.ok(), `booking-room-availability for ${start}`).toBeTruthy();

  const rooms = listFromApiBody(await availRes.json(), ['rooms']) as Array<{
    id?: number;
    number?: string | number;
    available_for_period?: boolean;
  }>;
  const room = rooms.find((r) => r.available_for_period && r.id != null && r.number != null);
  expect(room, `No available room for E2E ${kind} provision`).toBeTruthy();

  const createRes = await page.request.post(`${API_BASE}/users/makeReservation`, {
    headers: { ...headers, 'Content-Type': 'application/json' },
    data: {
      client_id: 1,
      rooms: [{ room_id: room!.id }],
      start_date: start,
      expire_date: end,
      reservation_type: 0,
      reservation_status: 1,
      stay_reason_id: 1,
      reservation_source_id: 1,
      rent_type: 0,
      price_calculation_mode: 0,
      discount: 0,
      extras: 0,
      penalties: 0,
      pay_amount: 0,
      logedin: 0,
    },
  });
  expect(createRes.ok(), `makeReservation failed: ${await createRes.text()}`).toBeTruthy();

  const reservationId = Number((await createRes.json()).data?.id ?? 0);
  expect(reservationId).toBeGreaterThan(0);

  return {
    reservationId,
    roomNumber: String(room!.number),
    start,
    end,
  };
}

export function boardDatesToProbe(): string[] {
  const today = new Date().toISOString().split('T')[0];
  return [today, ...DEMO_BOARD_DATES.filter((d) => d <= today && d !== today)];
}

function e2eCheckInStayDates(): { start: string; end: string } {
  const start = new Date().toISOString().slice(0, 10);
  const end = new Date();
  end.setUTCDate(end.getUTCDate() + 3);
  return { start, end: end.toISOString().slice(0, 10) };
}

export async function ensureLoggedIn(page: Page): Promise<void> {
  await page.goto('/#/dashboard');
  await expect(page.locator('app-sidebar').first()).toBeVisible({ timeout: 30_000 });
}

export async function reverbReachable(): Promise<boolean> {
  try {
    const res = await fetch(`http://127.0.0.1:${REVERB_PORT}`, { method: 'GET' });
    return res.status < 500;
  } catch {
    return false;
  }
}

function authHeaders(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}`, Accept: 'application/json' };
}

function listFromApiBody(body: { data?: Record<string, unknown> | unknown[] }, keys: string[]): unknown[] {
  const data = body.data;
  if (Array.isArray(data)) {
    return data;
  }
  if (data && typeof data === 'object') {
    for (const key of keys) {
      const value = (data as Record<string, unknown>)[key];
      if (Array.isArray(value)) {
        return value;
      }
    }
  }
  return [];
}

export async function apiToken(page: Page): Promise<string> {
  const token = await page.evaluate(() => {
    try {
      const raw = localStorage.getItem('user');
      if (!raw) return '';
      const json = raw.startsWith('{') ? raw : atob(raw);
      return (JSON.parse(json).token as string) || '';
    } catch {
      return '';
    }
  });
  expect(token, 'auth token in localStorage').toBeTruthy();
  return token;
}

export function parseMoney(text: string): number {
  const easternToLatin = text.replace(/[\u0660-\u0669]/g, (d) => String(d.charCodeAt(0) - 0x0660));
  const normalized = easternToLatin.replace(/[^\d.,-]/g, '').replace(/,/g, '');
  const value = Number(normalized);
  return Number.isFinite(value) ? value : 0;
}

export async function confirmAppDialog(page: Page): Promise<void> {
  const dialog = page.locator('.confirm-dialog');
  await expect(dialog).toBeVisible({ timeout: 15_000 });
  await dialog.locator('.confirm-dialog-footer button.btn-primary, .confirm-dialog-footer button.btn-danger').click();
  await expect(dialog).toBeHidden({ timeout: 15_000 });
}

export async function openRoomBoard(page: Page, date: string): Promise<void> {
  await page.goto('/#/dashboard/room-board');
  await expect(page.locator('app-room-board')).toBeVisible({ timeout: 30_000 });
  await expect
    .poll(async () => page.locator('app-room-board .spinner-border').count(), { timeout: 45_000 })
    .toBe(0);

  const dateInput = page.locator('app-room-board input[type="date"]');
  await expect(dateInput).toBeVisible({ timeout: 15_000 });

  const boardRefresh = page.waitForResponse(
    (response) => response.url().includes('/occupancy-board') && response.url().includes(`date=${date}`),
    { timeout: 45_000 }
  );
  await dateInput.fill(date);
  await dateInput.dispatchEvent('input');
  await dateInput.dispatchEvent('change');
  await dateInput.blur();
  await boardRefresh;

  await expect
    .poll(async () => page.locator('app-room-board .spinner-border').count(), { timeout: 45_000 })
    .toBe(0);
}

export interface CheckInCandidate {
  date: string;
  roomNumber: string;
  reservationId: number;
}

export async function findCheckInCandidate(page: Page, dates = boardDatesToProbe()): Promise<CheckInCandidate | null> {
  await ensureLoggedIn(page);
  const token = await apiToken(page);

  for (const date of dates) {
    const res = await page.request.get(`${API_BASE}/users/rooms/occupancy-board?date=${date}`, {
      headers: authHeaders(token),
    });
    if (!res.ok()) {
      continue;
    }

    const body = await res.json();
    const rooms = body.data?.rooms ?? body.data?.data?.rooms ?? [];
    const candidate = rooms.find(
      (r: { occupancy_status?: string; number?: string | number; reservation?: { reservation_id?: number } }) =>
        r.occupancy_status === 'check_in_today' && r.reservation?.reservation_id && r.number != null
    );

    if (candidate) {
      return {
        date,
        roomNumber: String(candidate.number),
        reservationId: Number(candidate.reservation.reservation_id),
      };
    }
  }

  return null;
}

export async function provisionCheckInCandidate(page: Page): Promise<CheckInCandidate> {
  const today = new Date().toISOString().split('T')[0];
  const existing = await findCheckInCandidate(page, [today]);
  if (existing) {
    return existing;
  }

  const created = await createE2EProvisionedReservation(page, 'checkin');
  const provisioned = await findCheckInCandidate(page, [created.start]);
  if (provisioned) {
    return provisioned;
  }

  return {
    date: created.start,
    roomNumber: created.roomNumber,
    reservationId: created.reservationId,
  };
}

export async function requireCheckInCandidate(page: Page): Promise<CheckInCandidate> {
  return provisionCheckInCandidate(page);
}

export async function checkInReservationForRealtime(page: Page, candidate: CheckInCandidate): Promise<void> {
  await openRoomBoard(page, candidate.date);

  const token = await apiToken(page);
  const res = await page.request.patch(`${API_BASE}/users/reservations/${candidate.reservationId}`, {
    headers: { ...authHeaders(token), 'Content-Type': 'application/json' },
    data: { logedin: 1, login_time: candidate.date },
  });
  expect(res.ok(), `check-in API failed: ${await res.text()}`).toBeTruthy();
}

/** @deprecated Prefer checkInReservationForRealtime — UI check-in is flaky across locales/board dates. */
export async function checkInRoomOnBoard(page: Page, date: string, roomNumber: string): Promise<void> {
  await openRoomBoard(page, date);

  const roomPattern = new RegExp(`#?${roomNumber.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`, 'i');
  const checkInPattern = /check\s*in|تسجيل/i;
  const roomCard = page.locator('app-room-board .room-card').filter({ hasText: roomPattern }).first();
  const listRow = page.locator('app-room-board .list-row').filter({ hasText: roomPattern }).first();

  await expect
    .poll(async () => (await roomCard.count()) + (await listRow.count()), { timeout: 25_000 })
    .toBeGreaterThan(0);

  if (await roomCard.count()) {
    const checkInBtn = roomCard.getByRole('button', { name: checkInPattern });
    await expect(checkInBtn).toBeVisible({ timeout: 20_000 });
    await checkInBtn.click();
  } else {
    const checkInBtn = listRow.getByRole('button', { name: checkInPattern });
    await expect(checkInBtn).toBeVisible({ timeout: 20_000 });
    await checkInBtn.click();
  }

  await confirmAppDialog(page);
}

export async function waitForRoomInHouseOnBoard(page: Page, roomNumber: string, timeoutMs = 25_000): Promise<void> {
  const roomPattern = new RegExp(`#?${roomNumber.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`, 'i');
  await expect
    .poll(async () => {
      const inHouseCard = page.locator('app-room-board .room-card.room-card--in_house').filter({ hasText: roomPattern });
      if (await inHouseCard.count()) {
        return true;
      }
      const inHouseRow = page.locator('app-room-board .list-row.room-card--in_house').filter({ hasText: roomPattern });
      return (await inHouseRow.count()) > 0;
    }, { timeout: timeoutMs })
    .toBe(true);
}

export async function fetchDashboardInHouse(page: Page): Promise<number> {
  const token = await apiToken(page);
  const res = await page.request.get(`${API_BASE}/users/dashboard/summary`, {
    headers: authHeaders(token),
  });
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  return Number(body.data?.occupancy?.in_house ?? 0);
}

export async function openCollectionsTotalBalance(page: Page): Promise<number> {
  await page.goto('/#/booking/collections');
  await expect(page.locator('app-collections')).toBeVisible({ timeout: 30_000 });

  const kpi = page.locator('app-collections .kpi-card--primary .kpi-value').first();
  await expect(kpi).toBeVisible({ timeout: 45_000 });

  return parseMoney(await kpi.innerText());
}

export async function readCollectionsKpiOnPage(page: Page): Promise<number> {
  const kpi = page.locator('app-collections .kpi-card--primary .kpi-value').first();
  await expect(kpi).toBeVisible({ timeout: 45_000 });
  return parseMoney(await kpi.innerText());
}

export async function fetchCollectionsTotalViaApi(page: Page): Promise<number> {
  const token = await apiToken(page);
  const res = await page.request.get(`${API_BASE}/users/collections/summary`, {
    headers: authHeaders(token),
  });
  expect(res.ok()).toBeTruthy();
  return Number((await res.json()).data?.total_balance ?? 0);
}

export async function fetchReservationBalance(page: Page, reservationId: number): Promise<number> {
  await ensureLoggedIn(page);
  return reservationBalanceViaApi(page, reservationId);
}

export async function openReservationDetailWithBalance(page: Page, reservationId: number): Promise<void> {
  await page.goto(`/#/booking/reservations/${reservationId}`);
  await expect(page.locator('app-reservation-detail')).toBeVisible({ timeout: 30_000 });
  await expect
    .poll(async () => page.locator('app-reservation-detail .spinner-border').count(), { timeout: 45_000 })
    .toBe(0);
  await expect
    .poll(async () => reservationBalanceViaApi(page, reservationId), { timeout: 30_000 })
    .toBeGreaterThan(0.01);
}

export async function recordReservationPaymentViaApi(
  page: Page,
  reservationId: number,
  amount?: number
): Promise<number> {
  const balance = await reservationBalanceViaApi(page, reservationId);
  expect(balance).toBeGreaterThan(0.01);

  const payAmount = amount ?? Math.min(balance, Math.max(1, Math.round(balance * 0.25 * 100) / 100));
  const token = await apiToken(page);
  const res = await page.request.post(`${API_BASE}/users/reservations/${reservationId}/payments`, {
    headers: { ...authHeaders(token), 'Content-Type': 'application/json' },
    data: { pay: payAmount, type: 0 },
  });
  expect(res.ok(), `payment API failed: ${await res.text()}`).toBeTruthy();

  return payAmount;
}

export async function provisionReservationWithBalance(page: Page): Promise<number> {
  const existing = await findReservationWithBalance(page);
  if (existing) {
    const balance = await reservationBalanceViaApi(page, existing);
    if (balance > 0.01) {
      return existing;
    }
  }

  const created = await createE2EProvisionedReservation(page, 'payment');

  await expect
    .poll(async () => reservationBalanceViaApi(page, created.reservationId), { timeout: 30_000 })
    .toBeGreaterThan(0.01);

  return created.reservationId;
}

export async function findReservationWithBalance(page: Page): Promise<number | null> {
  await ensureLoggedIn(page);
  const token = await apiToken(page);
  const res = await page.request.get(`${API_BASE}/users/collections?tab=all&per_page=50&sort=balance_due`, {
    headers: authHeaders(token),
  });
  if (!res.ok()) {
    return null;
  }

  const body = await res.json();
  const rows = listFromApiBody(body, ['items', 'data']) as Array<{ balance_due?: number; reservation_id?: number }>;
  for (const row of rows) {
    if (Number(row.balance_due ?? 0) <= 0.01) {
      continue;
    }
    const id = Number(row.reservation_id);
    const balance = await reservationBalanceViaApi(page, id);
    if (balance > 0.01) {
      return id;
    }
  }
  return null;
}
