import { Component, OnInit } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { HotelBranch, HotelBranchUser, HotelTaxFormPayload } from '../../../models/hotel.interface';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';

@Component({
    selector: 'app-hotels',
    templateUrl: './hotels.component.html',
    styleUrls: ['./hotels.component.css'],
    standalone: false
})
export class HotelsComponent implements OnInit {
  hotels: HotelBranch[] = [];
  isLoading = false;
  isSaving = false;

  showModal = false;
  isEditMode = false;
  currentHotel: { id?: number | string; name_ar: string; name_en: string; is_active?: boolean } = { name_ar: '', name_en: '' };
  taxForm: HotelTaxFormPayload = { tax_mode: 'separate' };

  /** Hotels that hold their own tax registration and can be shared with — excludes the hotel being edited. */
  get taxOwnerOptions(): HotelBranch[] {
    return this.hotels.filter((h) => !h.shares_tax_with_hotel_id && h.id !== this.currentHotel.id);
  }

  showUsersModal = false;
  usersModalHotel: HotelBranch | null = null;
  assignedUsers: HotelBranchUser[] = [];
  availableUsers: HotelBranchUser[] = [];
  isLoadingUsers = false;
  isSavingUser = false;
  selectedUserId: string | number | null = null;

  constructor(
    private hotelService: HotelService,
    private translate: TranslateService,
    private dataService: DataService,
  ) {}

  ngOnInit(): void {
    this.loadHotels();
  }

  loadHotels(): void {
    this.isLoading = true;
    this.hotelService.fetchHotels().subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.hotels = res.data ?? [];
        } else {
          this.dataService.showToast(res.message || this.translate.instant('HOTELS.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoading = false;
        this.dataService.showToast(this.translate.instant('HOTELS.LOAD_ERROR'), 'error');
      }
    });
  }

  openAddModal(): void {
    this.isEditMode = false;
    this.currentHotel = { name_ar: '', name_en: '' };
    this.taxForm = { tax_mode: 'separate' };
    this.showModal = true;
  }

  openEditModal(hotel: HotelBranch): void {
    this.isEditMode = true;
    this.currentHotel = { id: hotel.id, name_ar: hotel.name_ar, name_en: hotel.name_en, is_active: hotel.is_active };
    this.taxForm = hotel.shares_tax_with_hotel_id
      ? { tax_mode: 'shared', shares_tax_with_hotel_id: hotel.shares_tax_with_hotel_id }
      : {
          tax_mode: 'separate',
          vat_number: hotel.tax_profile?.vat_number ?? '',
          cr_number: hotel.tax_profile?.cr_number ?? '',
          org_name_ar: hotel.tax_profile?.org_name_ar ?? '',
          org_name_en: hotel.tax_profile?.org_name_en ?? '',
          org_unit_name: hotel.tax_profile?.org_unit_name ?? '',
          location: hotel.tax_profile?.location ?? '',
          industry: hotel.tax_profile?.industry ?? '',
        };
    this.showModal = true;
  }

  closeModal(): void {
    if (this.isSaving) return;
    this.showModal = false;
  }

  get taxFormValid(): boolean {
    if (this.taxForm.tax_mode === 'shared') {
      return !!this.taxForm.shares_tax_with_hotel_id;
    }
    return !!(this.taxForm.vat_number && this.taxForm.org_name_ar && this.taxForm.org_name_en);
  }

  saveHotel(): void {
    if (!this.taxFormValid) {
      this.dataService.showToast(this.translate.instant('HOTELS.TAX_FORM_INVALID'), 'error');
      return;
    }

    this.isSaving = true;
    const request = this.isEditMode && this.currentHotel.id
      ? this.hotelService.updateHotel(this.currentHotel.id, {
          name_ar: this.currentHotel.name_ar,
          name_en: this.currentHotel.name_en,
          is_active: this.currentHotel.is_active,
          ...this.taxForm,
        })
      : this.hotelService.addHotel({ name_ar: this.currentHotel.name_ar, name_en: this.currentHotel.name_en, ...this.taxForm });

    request.subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res.success) {
          this.showModal = false;
          this.loadHotels();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('HOTELS.SAVE_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('HOTELS.SAVE_ERROR'), 'error');
      }
    });
  }

  toggleActive(hotel: HotelBranch): void {
    this.hotelService.updateHotel(hotel.id, { is_active: !hotel.is_active }).subscribe({
      next: (res) => {
        if (res.success) {
          this.loadHotels();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('HOTELS.SAVE_ERROR'), 'error');
        }
      },
      error: () => this.dataService.showToast(this.translate.instant('HOTELS.SAVE_ERROR'), 'error')
    });
  }

  openUsersModal(hotel: HotelBranch): void {
    this.usersModalHotel = hotel;
    this.showUsersModal = true;
    this.selectedUserId = null;
    this.loadHotelUsers(hotel.id);
  }

  closeUsersModal(): void {
    if (this.isSavingUser) return;
    this.showUsersModal = false;
    this.usersModalHotel = null;
    this.assignedUsers = [];
    this.availableUsers = [];
  }

  private loadHotelUsers(hotelId: number | string): void {
    this.isLoadingUsers = true;
    this.hotelService.fetchHotelUsers(hotelId).subscribe({
      next: (res) => {
        this.isLoadingUsers = false;
        if (res.success) {
          this.assignedUsers = res.data?.assigned ?? [];
          this.availableUsers = res.data?.available ?? [];
        } else {
          this.dataService.showToast(res.message || this.translate.instant('HOTELS.LOAD_ERROR'), 'error');
        }
      },
      error: () => {
        this.isLoadingUsers = false;
        this.dataService.showToast(this.translate.instant('HOTELS.LOAD_ERROR'), 'error');
      }
    });
  }

  assignUser(): void {
    if (!this.usersModalHotel || !this.selectedUserId) return;
    this.isSavingUser = true;
    this.hotelService.assignHotelUser(this.usersModalHotel.id, this.selectedUserId).subscribe({
      next: (res) => {
        this.isSavingUser = false;
        if (res.success) {
          this.selectedUserId = null;
          this.loadHotelUsers(this.usersModalHotel!.id);
          this.loadHotels();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('HOTELS.SAVE_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSavingUser = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('HOTELS.SAVE_ERROR'), 'error');
      }
    });
  }

  removeUser(userId: number | string): void {
    if (!this.usersModalHotel) return;
    this.isSavingUser = true;
    this.hotelService.removeHotelUser(this.usersModalHotel.id, userId).subscribe({
      next: (res) => {
        this.isSavingUser = false;
        if (res.success) {
          this.loadHotelUsers(this.usersModalHotel!.id);
          this.loadHotels();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('HOTELS.REMOVE_USER_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSavingUser = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('HOTELS.REMOVE_USER_ERROR'), 'error');
      }
    });
  }
}
