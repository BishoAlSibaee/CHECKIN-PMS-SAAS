import { Injectable } from '@angular/core';
import { BehaviorSubject } from 'rxjs';
import { DataService } from './Api/data.service';
import { HotelOption } from '../models/Auth/login-response';

const STORAGE_KEY = 'currentHotelId';

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

  constructor(private dataService: DataService) {
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
