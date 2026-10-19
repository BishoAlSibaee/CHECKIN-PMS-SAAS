import { Injectable } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { BehaviorSubject, combineLatest } from 'rxjs';
import { DataService } from './Api/data.service';
import { LanguageService } from './language.service';
import { HotelOption } from '../models/Auth/login-response';

const STORAGE_KEY = 'currentHotelId';

/**
 * COMMON.CURRENCY_SYMBOL in en.json/ar.json is a static "$" placeholder —
 * every template/component reading it (15+ sites) does so through
 * ngx-translate, so overriding the key at runtime via TranslateService.set()
 * updates all of them reactively without touching a single one.
 */
const CURRENCY_SYMBOLS: Record<string, string> = {
  SAR: 'ر.س',
  USD: '$',
  AED: 'د.إ',
  EGP: 'ج.م',
  KWD: 'د.ك',
  QAR: 'ر.ق',
  BHD: 'د.ب',
  OMR: 'ر.ع',
};

/**
 * Which hotel (branch) the staff member is currently working in. Mirrors
 * LanguageService's shape: a BehaviorSubject + localStorage persistence, sent
 * on every request via the X-Hotel-Id header (see auth.interceptor.ts) so the
 * backend can scope reservations/rooms/roles to it (ResolveCurrentHotel).
 */
@Injectable({
  providedIn: 'root',
})
export class HotelContextService {
  private readonly currentHotelIdSubject = new BehaviorSubject<number | null>(null);
  readonly currentHotelId$ = this.currentHotelIdSubject.asObservable();

  constructor(
    private dataService: DataService,
    private translate: TranslateService,
    private languageService: LanguageService,
  ) {
    this.dataService.user$.subscribe((user) => {
      const hotels = (user as unknown as { available_hotels?: HotelOption[] } | null)?.available_hotels ?? [];
      if (hotels.length === 0) {
        this.currentHotelIdSubject.next(null);
        return;
      }

      const saved = Number(localStorage.getItem(STORAGE_KEY));
      const savedIsValid = hotels.some((h) => h.id === saved);
      const serverDefault = (user as unknown as { current_hotel_id?: number } | null)?.current_hotel_id;

      this.currentHotelIdSubject.next(savedIsValid ? saved : (serverDefault ?? hotels[0].id));
    });

    // Re-applied on hotel switch AND on language change — switching language
    // reloads that language's full static dictionary from en.json/ar.json,
    // which would silently overwrite this override with the placeholder "$"
    // again if it weren't reapplied here.
    combineLatest([this.currentHotelId$, this.languageService.changes$]).subscribe(([hotelId]) => {
      const hotel = this.availableHotels.find((h) => h.id === hotelId);
      const currency = hotel?.tenant?.currency ?? 'SAR';
      this.translate.set('COMMON.CURRENCY_SYMBOL', CURRENCY_SYMBOLS[currency] ?? currency, this.languageService.current);
    });
  }

  get current(): number | null {
    return this.currentHotelIdSubject.value;
  }

  get availableHotels(): HotelOption[] {
    const user = this.dataService.user$.value as unknown as { available_hotels?: HotelOption[] } | null;
    return user?.available_hotels ?? [];
  }

  /** Only worth showing a switcher when there's actually a choice. */
  get hasMultipleHotels(): boolean {
    return this.availableHotels.length > 1;
  }

  switch(hotelId: number): void {
    if (!this.availableHotels.some((h) => h.id === hotelId)) {
      return;
    }
    localStorage.setItem(STORAGE_KEY, String(hotelId));
    this.currentHotelIdSubject.next(hotelId);
    // The current hotel changes what a user can see everywhere (rooms,
    // reservations, roles) — reload rather than trying to invalidate every
    // in-memory list individually.
    window.location.reload();
  }
}
