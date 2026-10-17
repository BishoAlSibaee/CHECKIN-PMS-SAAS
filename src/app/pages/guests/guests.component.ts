import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { HotelService } from '../../services/hotel.service';
import { GuestClassification, GuestFeature, GuestClassificationFeature, ClientClassification } from '../../models/guest.interface';
import { Subscription, forkJoin, Observable, OperatorFunction, of } from 'rxjs';
import { debounceTime, distinctUntilChanged, map, switchMap, catchError, tap } from 'rxjs/operators';
import { DataService } from '../../services/Api/data.service';
import { ConfirmDialogService } from '../../services/confirm-dialog.service';
import { TranslateService } from '@ngx-translate/core';

@Component({
    selector: 'app-guests',
    templateUrl: './guests.component.html',
    styleUrls: ['./guests.component.css'],
    standalone: false
})
export class GuestsComponent implements OnInit, OnDestroy {
  activeTab: 'classifications' | 'features' | 'clients' = 'classifications';

  classifications: GuestClassification[] = [];
  features: GuestFeature[] = [];
  /** Full list for assignment/mapping modals (not limited to current table page). */
  classificationOptions: GuestClassification[] = [];
  allFeaturesForMapping: GuestFeature[] = [];
  clientClassifications: ClientClassification[] = [];

  discounts: any[] = [];
  isLoading = false;
  isSearchingClients = false;
  searchTerm = '';
  statusFilter: 'all' | 'active' | 'inactive' = 'all';
  private subscription = new Subscription();

  get filteredClassifications(): GuestClassification[] {
    const q = this.searchTerm.trim().toLowerCase();
    return this.classifications.filter((item) => {
      const active = this.isItemActive(item.active);
      const statusOk =
        this.statusFilter === 'all' ||
        (this.statusFilter === 'active' && active) ||
        (this.statusFilter === 'inactive' && !active);
      if (!statusOk) {
        return false;
      }
      if (!q) {
        return true;
      }
      return (
        (item.name_en || '').toLowerCase().includes(q) ||
        (item.name_ar || '').toLowerCase().includes(q) ||
        (item.description || '').toLowerCase().includes(q)
      );
    });
  }

  get filteredFeatures(): GuestFeature[] {
    const q = this.searchTerm.trim().toLowerCase();
    if (!q) {
      return this.features;
    }
    return this.features.filter((item) =>
      (item.name_en || '').toLowerCase().includes(q) ||
      (item.name_ar || '').toLowerCase().includes(q) ||
      (item.feature_description || item.description || '').toLowerCase().includes(q)
    );
  }

  get filteredClientClassifications(): ClientClassification[] {
    const q = this.searchTerm.trim().toLowerCase();
    if (!q) {
      return this.clientClassifications;
    }
    return this.clientClassifications.filter((item) => {
      const clientName = `${item.client?.first_name || ''} ${item.client?.last_name || ''}`.toLowerCase();
      const mobile = (item.client?.mobile || '').toLowerCase();
      const className = `${item.classification?.name_en || ''} ${item.classification?.name_ar || ''}`.toLowerCase();
      return clientName.includes(q) || mobile.includes(q) || className.includes(q);
    });
  }

  get primaryActionLabel(): string {
    if (this.activeTab === 'features') {
      return 'GUEST_MGMT.ADD_FEATURE';
    }
    if (this.activeTab === 'clients') {
      return 'GUEST_MGMT.ASSIGN_CLASSIFICATION';
    }
    return 'GUEST_MGMT.ADD_CLASSIFICATION';
  }

  clearFilters(): void {
    this.searchTerm = '';
    this.statusFilter = 'all';
  }

  isItemActive(active: unknown): boolean {
    return active === 1 || active === true;
  }

  displayName(item: { name_en?: string; name_ar?: string; name?: string } | null | undefined): string {
    if (!item) {
      return '';
    }
    if (this.currentLang === 'ar') {
      return item.name_ar || item.name_en || item.name || '';
    }
    return item.name_en || item.name_ar || item.name || '';
  }

  clientInitials(client: { first_name?: string; last_name?: string } | null | undefined): string {
    const a = (client?.first_name || '').charAt(0);
    const b = (client?.last_name || '').charAt(0);
    return `${a}${b}`.toUpperCase() || '?';
  }

  openPrimaryAction(): void {
    if (this.activeTab === 'features') {
      this.openModal('feature');
      return;
    }
    if (this.activeTab === 'clients') {
      this.openModal('assignment');
      return;
    }
    this.openModal('classification');
  }

  // Forms
  classificationForm: FormGroup;
  featureForm: FormGroup;

  editingId: string | null = null;
  showModal = false;
  modalType: 'classification' | 'feature' | 'mapping' | 'assignment' = 'classification';

  // Mapping state
  selectedClassification: GuestClassification | null = null;
  classificationFeatures: GuestClassificationFeature[] = [];
  availableFeatures: GuestFeature[] = [];

  // Assignment state
  clients: any[] = [];
  assignmentForm: FormGroup;
  selectedClientForAssignment: any = null;

  // Pagination state
  pagination = {
    classifications: { current: 1, total: 0, last: 1, perPage: 10 },
    features: { current: 1, total: 0, last: 1, perPage: 10 },
    clients: { current: 1, total: 0, last: 1, perPage: 10 }
  };

  get currentLang(): string {
    return this.translate.currentLang || 'en';
  }

  constructor(
    private hotelService: HotelService,
    private fb: FormBuilder,
    private dataService:DataService,
    private translate: TranslateService,
    private confirmDialog: ConfirmDialogService
  ) {
    this.classificationForm = this.fb.group({
      name_en: ['', Validators.required],
      name_ar: ['', Validators.required],
      description: [''],
      color: ['#3b82f6'],
      discount_id: [''],
      active: [1]
    });

    // Handle checkbox conversion for active field
    this.classificationForm.get('active')?.valueChanges.subscribe(val => {
      if (typeof val === 'boolean') {
        this.classificationForm.get('active')?.setValue(val ? 1 : 0, { emitEvent: false });
      }
    });

    this.featureForm = this.fb.group({
      name_en: ['', Validators.required],
      name_ar: ['', Validators.required],
      feature_description: [''],
      description: [''], // Keep for internal mapping if needed
      icon: ['bi-star']
    });

    this.assignmentForm = this.fb.group({
      client_id: ['', Validators.required],
      guest_classification_id: ['', Validators.required],
      client_search: [''] // Added for searchable select
    });
  }

  // Client search logic for NgbTypeahead (Server-side search)
  searchClients: OperatorFunction<string, readonly any[]> = (text$: Observable<string>) =>
    text$.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      tap(() => this.isSearchingClients = true),
      switchMap(term =>
        term.length < 2 ? of([]) :
        this.hotelService.fetchClients(term, '10', false).pipe(
          catchError(() => of([]))
        )
      ),
      tap(() => this.isSearchingClients = false)
    );

  clientFormatter = (result: any) => {
    if (!result || typeof result !== 'object') return '';
    return `${result.first_name || ''} ${result.last_name || ''} (${result.mobile || result.IdNumber || result.id || ''})`.trim();
  };


  onClientSelect(event: any): void {
    const client = event.item;
    this.selectedClientForAssignment = client;
    this.assignmentForm.patchValue({
      client_id: client.id
    });
  }

  ngOnInit(): void {
    this.loadData();
    this.hotelService.fetchClients().subscribe(data => this.clients = data);

    this.subscription.add(
      this.hotelService.guestClassifications$.subscribe(data => this.classifications = data)
    );
    this.subscription.add(
      this.hotelService.guestFeatures$.subscribe(data => this.features = data)
    );
    this.subscription.add(
      this.hotelService.clientClassifications$.subscribe(data => this.clientClassifications = data)
    );
    this.subscription.add(
      this.hotelService.discounts$.subscribe(data => this.discounts = data)
    );
  }

  ngOnDestroy(): void {
    this.subscription.unsubscribe();
  }

  loadData(): void {
    this.isLoading = true;
    forkJoin([
      this.hotelService.fetchGuestClassifications(this.pagination.classifications.current, this.pagination.classifications.perPage),
      this.hotelService.fetchGuestFeatures(this.pagination.features.current, this.pagination.features.perPage),
      this.hotelService.fetchClientClassifications(this.pagination.clients.current, this.pagination.clients.perPage),
      // this.hotelService.fetchClients(),
      this.hotelService.fetchDiscounts()
    ]).subscribe({
      next: (results: any[]) => {
        // Handle pagination data from results
        if (results[0] && results[0].total !== undefined) {
          this.pagination.classifications.total = results[0].total;
          this.pagination.classifications.last = results[0].lastPage;
        }
        if (results[1] && results[1].total !== undefined) {
          this.pagination.features.total = results[1].total;
          this.pagination.features.last = results[1].lastPage;
        }
        if (results[2] && results[2].total !== undefined) {
          this.pagination.clients.total = results[2].total;
          this.pagination.clients.last = results[2].lastPage;
        }
        this.isLoading = false;
      },
      error: () => this.isLoading = false
    });
  }

  onPageChange(tab: 'classifications' | 'features' | 'clients', page: number): void {
    if (page < 1) return;

    this.isLoading = true;
    if (tab === 'classifications') {
      this.pagination.classifications.current = page;
      this.hotelService.fetchGuestClassifications(page, this.pagination.classifications.perPage).subscribe(res => {
        this.pagination.classifications.total = res.total;
        this.pagination.classifications.last = res.lastPage;
        this.isLoading = false;
      });
    } else if (tab === 'features') {
      this.pagination.features.current = page;
      this.hotelService.fetchGuestFeatures(page, this.pagination.features.perPage).subscribe(res => {
        this.pagination.features.total = res.total;
        this.pagination.features.last = res.lastPage;
        this.isLoading = false;
      });
    } else if (tab === 'clients') {
      this.pagination.clients.current = page;
      this.hotelService.fetchClientClassifications(page, this.pagination.clients.perPage).subscribe(res => {
        this.pagination.clients.total = res.total;
        this.pagination.clients.last = res.lastPage;
        this.isLoading = false;
      });
    }
  }

  onPerPageChange(tab: 'classifications' | 'features' | 'clients'): void {
    this.onPageChange(tab, 1);
  }

  getPages(lastPage: number): number[] {
    return Array.from({ length: lastPage }, (_, i) => i + 1);
  }

  setTab(tab: 'classifications' | 'features' | 'clients'): void {
    this.activeTab = tab;
    this.clearFilters();
  }

  openModal(type: 'classification' | 'feature' | 'mapping' | 'assignment', data?: any): void {
    this.modalType = type;
    this.editingId = data ? data.id : null;
    this.showModal = true;

    if (type === 'classification') {
      this.classificationForm.reset({
        name_en: data?.name_en || '',
        name_ar: data?.name_ar || '',
        description: data?.description || '',
        color: data?.color || '#3b82f6',
        discount_id: data?.discount_id || '',
        active: data?.active === 1 || data?.active === true ? 1 : (data?.active === 0 || data?.active === false ? 0 : 1)
      });
    } else if (type === 'feature') {
      this.featureForm.reset({
        name_en: data?.name_en || '',
        name_ar: data?.name_ar || '',
        feature_description: data?.feature_description || data?.description || '',
        description: data?.description || '',
        icon: data?.icon || 'bi-star'
      });
    } else if (type === 'mapping') {
      this.selectedClassification = data;
      this.allFeaturesForMapping = [];
      this.loadClassificationFeatures(data.id);
      this.hotelService.fetchGuestFeatures(1, 500).subscribe(res => {
        this.allFeaturesForMapping = Array.isArray(res) ? res : (res.data || []);
        this.updateAvailableFeatures();
      });
    } else if (type === 'assignment') {
      this.selectedClientForAssignment = null;
      this.classificationOptions = [];
      this.assignmentForm.reset({
        client_id: '',
        guest_classification_id: '',
        client_search: ''
      });
      this.hotelService.fetchGuestClassifications(1, 500).subscribe(res => {
        this.classificationOptions = Array.isArray(res) ? res : (res.data || []);
      });
    }
  }

  submitAssignment(): void {
    if (this.assignmentForm.invalid) return;

    const data = {
      client_id: this.assignmentForm.value.client_id,
      classifications_id: this.assignmentForm.value.guest_classification_id
    };

    this.hotelService.assignClientClassification(data, this.pagination.clients.current).subscribe({
      next: (res) => {
        if (res.success || res.result === 'success') {
          const msg = this.translate.instant('MESSAGES.SAVE_SUCCESS', { item: this.translate.instant('MESSAGES.ITEM.CLASSIFICATION') });
          this.dataService.showToast(msg);
          this.closeModal();
        } else {
          const msg = res.message || this.translate.instant('MESSAGES.SAVE_ERROR', { item: this.translate.instant('MESSAGES.ITEM.CLASSIFICATION') });
          this.dataService.showToast(msg, 'error');
        }
      },
      error: () => {
        const msg = this.translate.instant('MESSAGES.ACTION_ERROR');
        this.dataService.showToast(msg, 'error');
      }
    });
  }

  loadClassificationFeatures(classificationId: string): void {
    this.hotelService.getGuestClassificationFeatures(classificationId).subscribe(data => {
      this.classificationFeatures = data;
      this.updateAvailableFeatures();
    });
  }

  updateAvailableFeatures(): void {
    const assignedIds = this.classificationFeatures.map(cf => cf.guest_feature_id.toString());
    const source = this.allFeaturesForMapping.length > 0 ? this.allFeaturesForMapping : this.features;
    this.availableFeatures = source.filter(f => !assignedIds.includes(f.id.toString()));
  }

  assignFeature(featureId: string): void {
    if (!this.selectedClassification) return;

    this.hotelService.addGuestClassificationFeature({
      guest_classification_id: this.selectedClassification.id,
      guest_feature_id: featureId
    }).subscribe({
      next: (res) => {
        if (res.success || res.result === 'success') {
          this.loadClassificationFeatures(this.selectedClassification!.id);
        } else {
          const msg = res.message || this.translate.instant('MESSAGES.ACTION_ERROR');
          this.dataService.showToast(msg, 'error');
        }
      },
      error: () => {
        const msg = this.translate.instant('MESSAGES.ACTION_ERROR');
        this.dataService.showToast(msg, 'error');
      }
    });
  }

  removeFeatureMapping(mappingId: string): void {
    if (!this.selectedClassification) return;

    this.hotelService.deleteGuestClassificationFeature(mappingId).subscribe({
      next: (res) => {
        if (res.success || res.result === 'success') {
          this.loadClassificationFeatures(this.selectedClassification!.id);
        } else {
          const msg = res.message || this.translate.instant('MESSAGES.ACTION_ERROR');
          this.dataService.showToast(msg, 'error');
        }
      },
      error: () => {
        const msg = this.translate.instant('MESSAGES.ACTION_ERROR');
        this.dataService.showToast(msg, 'error');
      }
    });
  }

  closeModal(): void {
    this.showModal = false;
    this.editingId = null;
  }

  saveClassification(): void {
    if (this.classificationForm.invalid) return;

    // Ensure active is sent as 1 or 0, not true/false
    const rawValue = this.classificationForm.value;
    const data = {
      ...rawValue,
      active: rawValue.active === true || rawValue.active === 1 ? 1 : 0
    };

    const obs = this.editingId
      ? this.hotelService.updateGuestClassification({ ...data, id: this.editingId }, this.pagination.classifications.current, this.pagination.classifications.perPage)
      : this.hotelService.addGuestClassification(data, this.pagination.classifications.current, this.pagination.classifications.perPage);

    obs.subscribe({
      next: (res) => {
        if (res.success || res.result === 'success') {
          const msg = this.translate.instant('MESSAGES.SAVE_SUCCESS', { item: this.translate.instant('MESSAGES.ITEM.CLASSIFICATION') });
          this.dataService.showToast(msg);
          this.closeModal();
        } else {
          const msg = res.message || this.translate.instant('MESSAGES.SAVE_ERROR', { item: this.translate.instant('MESSAGES.ITEM.CLASSIFICATION') });
          this.dataService.showToast(msg, 'error');
        }
      },
      error: () => {
        const msg = this.translate.instant('MESSAGES.ACTION_ERROR');
        this.dataService.showToast(msg, 'error');
      }
    });
  }

  saveFeature(): void {
    if (this.featureForm.invalid) return;

    const data = this.featureForm.value;
    const obs = this.editingId
      ? this.hotelService.updateGuestFeature({ ...data, id: this.editingId }, this.pagination.features.current, this.pagination.features.perPage)
      : this.hotelService.addGuestFeature(data, this.pagination.features.current, this.pagination.features.perPage);

    obs.subscribe({
      next: (res) => {
        if (res.success || res.result === 'success') {
          const msg = this.translate.instant('MESSAGES.SAVE_SUCCESS', { item: this.translate.instant('MESSAGES.ITEM.FEATURE') });
          this.dataService.showToast(msg);
          this.closeModal();
        } else {
          const msg = res.message || this.translate.instant('MESSAGES.SAVE_ERROR', { item: this.translate.instant('MESSAGES.ITEM.FEATURE') });
          this.dataService.showToast(msg, 'error');
        }
      },
      error: () => {
        const msg = this.translate.instant('MESSAGES.ACTION_ERROR');
        this.dataService.showToast(msg, 'error');
      }
    });
  }

  async deleteClassification(id: string): Promise<void> {
    const confirmMsg = this.translate.instant('MESSAGES.CONFIRM_DELETE', { item: this.translate.instant('MESSAGES.ITEM.CLASSIFICATION') });
    const ok = await this.confirmDialog.confirm(confirmMsg);
    if (ok) {
      this.hotelService.deleteGuestClassification(id, this.pagination.classifications.current, this.pagination.classifications.perPage).subscribe({
        next: (res) => {
          if (res.success || res.result === 'success') {
            const msg = this.translate.instant('MESSAGES.REMOVE_SUCCESS', { item: this.translate.instant('MESSAGES.ITEM.CLASSIFICATION') });
            this.dataService.showToast(msg);
          } else {
            const msg = res.message || this.translate.instant('MESSAGES.REMOVE_ERROR', { item: this.translate.instant('MESSAGES.ITEM.CLASSIFICATION') });
            this.dataService.showToast(msg, 'error');
          }
        },
        error: () => {
          const msg = this.translate.instant('MESSAGES.ACTION_ERROR');
          this.dataService.showToast(msg, 'error');
        }
      });
    }
  }

  async deleteFeature(id: string): Promise<void> {
    const confirmMsg = this.translate.instant('MESSAGES.CONFIRM_DELETE', { item: this.translate.instant('MESSAGES.ITEM.FEATURE') });
    const ok = await this.confirmDialog.confirm(confirmMsg);
    if (ok) {
      this.hotelService.deleteGuestFeature(id, this.pagination.features.current, this.pagination.features.perPage).subscribe({
        next: (res) => {
          if (res.success || res.result === 'success') {
            const msg = this.translate.instant('MESSAGES.REMOVE_SUCCESS', { item: this.translate.instant('MESSAGES.ITEM.FEATURE') });
            this.dataService.showToast(msg);
          } else {
            const msg = res.message || this.translate.instant('MESSAGES.REMOVE_ERROR', { item: this.translate.instant('MESSAGES.ITEM.FEATURE') });
            this.dataService.showToast(msg, 'error');
          }
        },
        error: () => {
          const msg = this.translate.instant('MESSAGES.ACTION_ERROR');
          this.dataService.showToast(msg, 'error');
        }
      });
    }
  }

  discountLabel(discount: { name?: string; is_percentage?: boolean | number; is_fixed?: boolean | number; percent?: number; fixed_amount?: number }): string {
    const name = discount.name || '';
    if (discount.is_percentage) {
      return `${name} (${discount.percent ?? 0}%)`;
    }
    if (discount.is_fixed) {
      return `${name} (${discount.fixed_amount ?? 0})`;
    }
    return name;
  }

  setClassificationActive(active: boolean): void {
    this.classificationForm.patchValue({ active: active ? 1 : 0 });
  }

  isClassificationActive(): boolean {
    const value = this.classificationForm.get('active')?.value;
    return value === 1 || value === true;
  }

  async removeClientClassification(clientId: string): Promise<void> {
    const confirmMsg = this.translate.instant('MESSAGES.CONFIRM_REMOVE', { item: this.translate.instant('MESSAGES.ITEM.CLASSIFICATION') });
    const ok = await this.confirmDialog.confirm(confirmMsg);
    if (ok) {
      this.hotelService.removeClientClassification(clientId, this.pagination.clients.current, this.pagination.clients.perPage).subscribe({
        next: (res) => {
          if (res.success || res.result === 'success') {
            const msg = this.translate.instant('MESSAGES.REMOVE_SUCCESS', { item: this.translate.instant('MESSAGES.ITEM.CLASSIFICATION') });
            this.dataService.showToast(msg);
          } else {
            const msg = res.message || this.translate.instant('MESSAGES.REMOVE_ERROR', { item: this.translate.instant('MESSAGES.ITEM.CLASSIFICATION') });
            this.dataService.showToast(msg, 'error');
          }
        },
        error: () => {
          const msg = this.translate.instant('MESSAGES.ACTION_ERROR');
          this.dataService.showToast(msg, 'error');
        }
      });
    }
  }
}
