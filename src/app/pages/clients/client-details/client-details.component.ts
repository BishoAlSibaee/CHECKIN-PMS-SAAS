import { Component, OnInit, OnDestroy, HostListener } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { HotelService } from '../../../services/hotel.service';
import { Client } from '../../../models/client.interface';
import { ClientNote } from '../../../models/client-note.interface';
import { Booking } from '../../../models/booking.interface';
import { Subscription } from 'rxjs';
import { TranslateService } from '@ngx-translate/core';
import { ConfirmDialogService } from '../../../services/confirm-dialog.service';
import { DataService } from '../../../services/Api/data.service';

interface EditClientForm {
  id: string;
  first_name: string;
  last_name: string;
  email: string;
  international_code: string;
  mobile: string;
  IdType: string;
  IdNumber: string;
  birth_date: string;
  gender: string;
  guest_type: string;
  nationality: string;
}

@Component({
    selector: 'app-client-details',
    templateUrl: './client-details.component.html',
    styleUrls: ['./client-details.component.css'],
    standalone: false
})
export class ClientDetailsComponent implements OnInit, OnDestroy {
  client?: Client;
  recentBookings: Booking[] = [];
  bookingsTotal = 0;
  bookingsPage = 1;
  bookingsPageSize = 25;
  bookingsLastPage = 1;
  isLoadingBookings = false;
  bookingsError = '';
  notes: ClientNote[] = [];
  isLoading: boolean = true;
  isLoadingNotes: boolean = false;
  private clientsSubscription?: Subscription;
  private clientId: string | null = null;

  // Note Modal
  showNoteModal: boolean = false;
  showViewNoteModal: boolean = false;
  selectedNote?: ClientNote;
  newNote: any = {
    title: '',
    description: ''
  };

  // Edit Client Modal
  showEditModal: boolean = false;
  isSavingEdit = false;
  isSubmitted: boolean = false;
  countryCodes: {code: string, name: string, key: string}[] = [
    { code: '+966', name: 'Saudi Arabia', key: 'COUNTRIES.SAUDI_ARABIA' },
    { code: '+963', name: 'Syria', key: 'COUNTRIES.SYRIA' },
    { code: '+971', name: 'UAE', key: 'COUNTRIES.UAE' },
    { code: '+965', name: 'Kuwait', key: 'COUNTRIES.KUWAIT' },
    { code: '+974', name: 'Qatar', key: 'COUNTRIES.QATAR' },
    { code: '+973', name: 'Bahrain', key: 'COUNTRIES.BAHRAIN' },
    { code: '+968', name: 'Oman', key: 'COUNTRIES.OMAN' },
    { code: '+962', name: 'Jordan', key: 'COUNTRIES.JORDAN' },
    { code: '+961', name: 'Lebanon', key: 'COUNTRIES.LEBANON' },
    { code: '+964', name: 'Iraq', key: 'COUNTRIES.IRAQ' },
    { code: '+20', name: 'Egypt', key: 'COUNTRIES.EGYPT' }
  ];
  filteredCountryCodes: {code: string, name: string, key: string}[] = [];
  showCountryDropdown: boolean = false;
  editClientData: EditClientForm = this.emptyEditForm();

  constructor(
    private route: ActivatedRoute,
    private hotelService: HotelService,
    private router: Router,
    private translate: TranslateService,
    private confirmDialog: ConfirmDialogService,
    private dataService: DataService
  ) {
    this.filteredCountryCodes = [...this.countryCodes];
  }

  ngOnInit(): void {
    this.clientId = this.route.snapshot.paramMap.get('id');
    if (this.clientId) {
      this.isLoading = true;
      this.hotelService.fetchClientById(this.clientId).subscribe(client => {
        this.isLoading = false;
        if (client) {
          this.client = client;
          this.loadData(this.clientId!);
        } else {
          console.error('Client not found');
          this.router.navigate(['/admin/clients']);
        }
      });
    } else {
      this.router.navigate(['/admin/clients']);
    }
  }

  ngOnDestroy(): void {
  }

  get clientDisplayName(): string {
    if (!this.client) {
      return '';
    }
    const full = String(this.client.name ?? '').trim();
    if (full) {
      return full;
    }
    return `${this.client.first_name ?? ''} ${this.client.last_name ?? ''}`.trim();
  }

  get clientInitials(): string {
    const name = this.clientDisplayName;
    if (!name) {
      return 'C';
    }
    const parts = name.split(/\s+/).filter(Boolean);
    if (parts.length === 1) {
      return parts[0].slice(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }

  loadData(clientId: string) {
    this.loadBookings(clientId);
    this.loadNotes(clientId);
  }

  loadBookings(clientId: string, page = 1): void {
    this.isLoadingBookings = true;
    this.bookingsError = '';
    this.bookingsPage = page;
    this.hotelService.fetchBookings(page, this.bookingsPageSize, clientId).subscribe({
      next: (res) => {
        if (Array.isArray(res)) {
          this.recentBookings = res;
          this.bookingsTotal = res.length;
          this.bookingsLastPage = 1;
        } else {
          this.recentBookings = res.data ?? [];
          this.bookingsTotal = res.total ?? this.recentBookings.length;
          this.bookingsLastPage = res.lastPage ?? 1;
        }
        if (this.client) {
          this.client.totalBookings = Math.max(this.client.totalBookings ?? 0, this.bookingsTotal);
        }
        this.isLoadingBookings = false;
      },
      error: () => {
        this.recentBookings = [];
        this.bookingsError = this.translate.instant('CLIENT_DETAILS.BOOKINGS_LOAD_ERROR');
        this.isLoadingBookings = false;
      },
    });
  }

  goToBookingsPage(page: number): void {
    if (!this.clientId || page < 1 || page > this.bookingsLastPage || page === this.bookingsPage) {
      return;
    }
    this.loadBookings(this.clientId, page);
  }

  bookingStatusLabel(status: Booking['status']): string {
    const keyMap: Record<Booking['status'], string> = {
      confirmed: 'RESERVATIONS.CONFIRMED',
      'checked-in': 'RESERVATIONS.CHECKED_IN',
      'checked-out': 'RESERVATIONS.CHECKED_OUT',
      cancelled: 'RESERVATIONS.CANCELLED',
      unconfirmed: 'RESERVATIONS.CALENDAR_LEGEND.PENDING',
      'pending-payment': 'RESERVATIONS.PENDING_PAYMENT',
    };
    return this.translate.instant(keyMap[status] ?? 'RESERVATIONS.CONFIRMED');
  }

  bookingStatusClass(status: Booking['status']): string {
    switch (status) {
      case 'checked-in':
        return 'bg-success-subtle text-success-emphasis border border-success-subtle';
      case 'checked-out':
        return 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle';
      case 'cancelled':
        return 'bg-danger-subtle text-danger-emphasis border border-danger-subtle';
      case 'unconfirmed':
        return 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
      case 'pending-payment':
        return 'bg-info-subtle text-info-emphasis border border-info-subtle';
      default:
        return 'bg-primary-subtle text-primary-emphasis border border-primary-subtle';
    }
  }

  loadNotes(clientId: string) {
    this.isLoadingNotes = true;
    this.hotelService.fetchClientNotes(clientId).subscribe(notes => {
      this.notes = notes;
      this.isLoadingNotes = false;
    });
  }

  addNote() {
    this.showNoteModal = true;
    this.newNote = { title: '', description: '' };
  }

  viewNote(note: ClientNote) {
    this.selectedNote = note;
    this.showViewNoteModal = true;
  }

  saveNote() {
    if (this.client && this.newNote.title && this.newNote.description) {
      const payload = {
        client_id: this.client.id,
        title: this.newNote.title,
        description: this.newNote.description
      };
      this.hotelService.addClientNote(payload).subscribe(() => {
        this.loadNotes(this.client!.id);
        this.showNoteModal = false;
      });
    }
  }

  async deleteNote(id: string, event?: Event): Promise<void> {
    if (event) {
      event.stopPropagation();
    }

    const item = this.translate.instant('MESSAGES.ITEM.NOTE');
    const message = this.translate.instant('MESSAGES.CONFIRM_DELETE', { item });

    const ok = await this.confirmDialog.confirm(message);
    if (ok) {
      this.hotelService.deleteClientNote(id).subscribe(() => {
        this.loadNotes(this.client!.id);
        if (this.selectedNote?.id === id) {
          this.showViewNoteModal = false;
        }
      });
    }
  }

  closeNoteModal() {
    this.showNoteModal = false;
  }

  closeViewNoteModal() {
    this.showViewNoteModal = false;
    this.selectedNote = undefined;
  }

  goBack() {
    this.router.navigate(['/admin/clients']);
  }

  viewAllBookings() {
    if (this.client) {
      this.router.navigate(['/booking/reservations'], { queryParams: { clientId: this.client.id } });
    }
  }

  viewBooking(id: string) {
    this.router.navigate(['/booking/reservations', id]);
  }

  filterCountryCodes() {
    const term = this.editClientData.international_code?.toString().toLowerCase() || '';
    this.filteredCountryCodes = this.countryCodes.filter(c =>
      c.code.toLowerCase().includes(term) ||
      c.name.toLowerCase().includes(term)
    );
    this.showCountryDropdown = true;
  }

  selectCountryCode(code: string) {
    this.editClientData.international_code = code;
    this.showCountryDropdown = false;
  }

  onBlurCountry() {
    setTimeout(() => {
      this.showCountryDropdown = false;
    }, 150);
  }

  editClient(): void {
    if (!this.client) {
      return;
    }
    this.isSubmitted = false;
    this.editClientData = this.buildEditForm(this.client);
    this.filteredCountryCodes = [...this.countryCodes];
    this.showCountryDropdown = false;
    this.showEditModal = true;
  }

  get canSaveEdit(): boolean {
    const form = this.editClientData;
    return Boolean(
      form.first_name?.trim()
      && form.last_name?.trim()
      && form.mobile?.trim()
      && form.IdNumber?.trim()
    );
  }

  saveClientEdit(): void {
    this.isSubmitted = true;
    if (!this.canSaveEdit || this.isSavingEdit) {
      return;
    }

    this.isSavingEdit = true;
    const payload = {
      id: this.editClientData.id,
      first_name: this.editClientData.first_name.trim(),
      last_name: this.editClientData.last_name.trim(),
      email: this.editClientData.email?.trim() || null,
      international_code: this.editClientData.international_code?.trim() || null,
      mobile: this.editClientData.mobile.trim(),
      IdType: this.editClientData.IdType || null,
      IdNumber: this.editClientData.IdNumber.trim(),
      birth_date: this.editClientData.birth_date || null,
      gender: this.editClientData.gender || null,
      guest_type: this.editClientData.guest_type || null,
      nationality: this.editClientData.nationality?.trim() || null,
    };

    this.hotelService.updateClient(payload).subscribe({
      next: (res) => {
        this.isSavingEdit = false;
        if (res.success) {
          this.client = {
            ...this.client!,
            first_name: payload.first_name,
            last_name: payload.last_name,
            email: payload.email ?? '',
            international_code: payload.international_code ?? '',
            mobile: payload.mobile,
            IdType: (payload.IdType as Client['IdType']) ?? null,
            IdNumber: payload.IdNumber,
            birth_date: payload.birth_date,
            gender: (payload.gender as Client['gender']) || 'MALE',
            guest_type: (payload.guest_type as Client['guest_type']) ?? null,
            nationality: payload.nationality ?? '',
            name: `${payload.first_name} ${payload.last_name}`.trim(),
          };
          this.dataService.showToast(this.translate.instant('CLIENT_DETAILS.EDIT_SAVE_OK'), 'success');
          this.closeEditModal();
          this.isSubmitted = false;
        } else {
          this.dataService.showToast(res.message || this.translate.instant('CLIENT_DETAILS.EDIT_SAVE_ERROR'), 'error');
        }
      },
      error: () => {
        this.isSavingEdit = false;
        this.dataService.showToast(this.translate.instant('CLIENT_DETAILS.EDIT_SAVE_ERROR'), 'error');
      },
    });
  }

  closeEditModal(): void {
    if (this.isSavingEdit) {
      return;
    }
    this.showEditModal = false;
    this.showCountryDropdown = false;
  }

  @HostListener('document:keydown.escape')
  onEscapeKey(): void {
    if (this.showEditModal) {
      this.closeEditModal();
    }
  }

  private emptyEditForm(): EditClientForm {
    return {
      id: '',
      first_name: '',
      last_name: '',
      email: '',
      international_code: '+966',
      mobile: '',
      IdType: 'ID',
      IdNumber: '',
      birth_date: '',
      gender: 'MALE',
      guest_type: 'CITIZEN',
      nationality: '',
    };
  }

  private buildEditForm(client: Client): EditClientForm {
    return {
      id: client.id,
      first_name: client.first_name ?? '',
      last_name: client.last_name ?? '',
      email: client.email ?? '',
      international_code: client.international_code?.trim() || '+966',
      mobile: client.mobile ?? '',
      IdType: client.IdType || 'ID',
      IdNumber: client.IdNumber ?? '',
      birth_date: this.toDateInputValue(client.birth_date),
      gender: client.gender || 'MALE',
      guest_type: client.guest_type === 'GULF CITIZEN' ? 'CITIZEN' : (client.guest_type || 'CITIZEN'),
      nationality: client.nationality ?? '',
    };
  }

  private toDateInputValue(value: string | null | undefined): string {
    if (!value) {
      return '';
    }
    const text = typeof value === 'string' ? value : String(value);
    const raw = text.length >= 10 ? text.slice(0, 10) : text;
    return /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : '';
  }

  newBooking() {
    if (this.client) {
      this.router.navigate(['/booking/reservations/create'], { queryParams: { clientId: this.client.id } });
    }
  }
}
