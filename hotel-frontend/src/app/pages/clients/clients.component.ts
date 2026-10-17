import { Component, OnInit, OnDestroy } from '@angular/core';
import { HotelService } from '../../services/hotel.service';
import { Client } from '../../models/client.interface';
import { Router } from '@angular/router';
import { Subscription } from 'rxjs';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../services/Api/data.service';

@Component({
    selector: 'app-clients',
    templateUrl: './clients.component.html',
    styleUrls: ['./clients.component.css'],
    standalone: false
})
export class ClientsComponent implements OnInit, OnDestroy {
  clients: Client[] = [];
  filteredClients: Client[] = [];
  searchTerm = '';
  guestTypeFilter: 'all' | string = 'all';
  private clientsSubscription?: Subscription;

  currentPage = 1;
  pageSize = 10;
  totalPages = 1;
  Math = Math;
  isLoading = true;
  isSaving = false;

  showAddModal = false;
  isSubmitted = false;
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
  showCountryDropdown = false;

  readonly guestTypeOptions = ['CITIZEN', 'RESIDENT', 'VISITOR', 'GULF CITIZEN'] as const;

  newClient: any = {
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
    nationality: ''
  };

  get activeCount(): number {
    return this.clients.filter((c) => this.isActive(c)).length;
  }

  get citizenCount(): number {
    return this.clients.filter((c) => this.normalizeGuestType(c.guest_type) === 'CITIZEN').length;
  }

  get paginationFrom(): number {
    if (this.filteredClients.length === 0) {
      return 0;
    }
    return (this.currentPage - 1) * this.pageSize + 1;
  }

  get paginationTo(): number {
    return Math.min(this.currentPage * this.pageSize, this.filteredClients.length);
  }

  constructor(
    private hotelService: HotelService,
    private router: Router,
    private translate: TranslateService,
    private dataService: DataService
  ) {
    this.filteredCountryCodes = [...this.countryCodes];
  }

  filterCountryCodes() {
    const term = this.newClient.international_code?.toString().toLowerCase() || '';
    this.filteredCountryCodes = this.countryCodes.filter(c =>
      c.code.toLowerCase().includes(term) ||
      c.name.toLowerCase().includes(term)
    );
    this.showCountryDropdown = true;
  }

  selectCountryCode(code: string) {
    this.newClient.international_code = code;
    this.showCountryDropdown = false;
  }

  onBlurCountry() {
    setTimeout(() => {
      this.showCountryDropdown = false;
    }, 200);
  }

  ngOnInit(): void {
    this.subscribeToClients();
    this.loadClients();
  }

  ngOnDestroy(): void {
    this.clientsSubscription?.unsubscribe();
  }

  private subscribeToClients(): void {
    this.clientsSubscription = this.hotelService.clients$.subscribe(clients => {
      this.clients = clients;
      this.applyFilter(false);
    });
  }

  loadClients() {
    this.isLoading = true;
    this.hotelService.fetchClients().subscribe({
      next: () => {
        this.isLoading = false;
      },
      error: () => {
        this.isLoading = false;
      }
    });
  }

  clearFilters(): void {
    this.searchTerm = '';
    this.guestTypeFilter = 'all';
    this.applyFilter();
  }

  isActive(client: Client): boolean {
    return !client.status || client.status === 'active';
  }

  clientInitials(client: Client): string {
    const a = (client.first_name || '').charAt(0);
    const b = (client.last_name || '').charAt(0);
    return `${a}${b}`.toUpperCase() || '?';
  }

  normalizeGuestType(guestType: string | null | undefined): string {
    if (!guestType) {
      return '';
    }
    return guestType.split(' ').join('_').toUpperCase();
  }

  guestTypeLabelKey(guestType: string | null | undefined): string {
    const normalized = this.normalizeGuestType(guestType);
    return normalized ? `CLIENTS.${normalized}` : 'COMMON.NA';
  }

  applyFilter(resetPage = true) {
    const q = this.searchTerm.trim().toLowerCase();
    this.filteredClients = this.clients.filter(client => {
      const typeOk =
        this.guestTypeFilter === 'all' ||
        this.normalizeGuestType(client.guest_type) === this.normalizeGuestType(this.guestTypeFilter);
      if (!typeOk) {
        return false;
      }
      if (!q) {
        return true;
      }
      return (
        (`${client.first_name} ${client.last_name}`).toLowerCase().includes(q) ||
        (!!client.email && client.email.toLowerCase().includes(q)) ||
        (!!client.mobile && client.mobile.includes(this.searchTerm.trim())) ||
        (!!client.IdNumber && client.IdNumber.includes(this.searchTerm.trim())) ||
        (!!client.id && client.id.toString().includes(this.searchTerm.trim())) ||
        (!!client.nationality && client.nationality.toLowerCase().includes(q))
      );
    });
    this.totalPages = Math.max(1, Math.ceil(this.filteredClients.length / this.pageSize) || 1);
    if (resetPage) {
      this.currentPage = 1;
    } else if (this.currentPage > this.totalPages) {
      this.currentPage = this.totalPages;
    }
  }

  get paginatedClients() {
    const startIndex = (this.currentPage - 1) * this.pageSize;
    return this.filteredClients.slice(startIndex, startIndex + this.pageSize);
  }

  nextPage() {
    if (this.currentPage < this.totalPages) {
      this.currentPage++;
    }
  }

  prevPage() {
    if (this.currentPage > 1) {
      this.currentPage--;
    }
  }

  onPerPageChange(): void {
    this.applyFilter();
  }

  goToDetails(clientId: string) {
    this.router.navigate(['/admin/clients', clientId]);
  }

  newBooking(clientId: string) {
    this.router.navigate(['/booking/reservations/create'], { queryParams: { clientId } });
  }

  addNewClient() {
    this.showAddModal = true;
    this.isSubmitted = false;
    this.isSaving = false;
    this.filteredCountryCodes = [...this.countryCodes];
    this.newClient = {
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
      nationality: ''
    };
  }

  saveClient() {
    this.isSubmitted = true;
    if (!(this.newClient.first_name && this.newClient.last_name && this.newClient.mobile && this.newClient.IdNumber)) {
      return;
    }
    if (this.isSaving) {
      return;
    }

    this.isSaving = true;
    const payload = {
      ...this.newClient,
      email: this.newClient.email || '',
      birth_date: this.newClient.birth_date || '',
      nationality: this.newClient.nationality || '',
      international_code: this.newClient.international_code || '+966'
    };

    this.hotelService.addClient(payload).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res?.success === false) {
          this.dataService.showToast(
            res.message || this.translate.instant('MESSAGES.SAVE_ERROR', { item: this.translate.instant('CLIENTS.CLIENT') }),
            'error'
          );
          return;
        }
        this.showAddModal = false;
        this.isSubmitted = false;
        this.dataService.showToast(
          this.translate.instant('MESSAGES.SAVE_SUCCESS', { item: this.translate.instant('CLIENTS.CLIENT') }),
          'success'
        );
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(
          err?.error?.message || this.translate.instant('MESSAGES.SAVE_ERROR', { item: this.translate.instant('CLIENTS.CLIENT') }),
          'error'
        );
      }
    });
  }

  closeModal() {
    if (this.isSaving) {
      return;
    }
    this.showAddModal = false;
  }
}
