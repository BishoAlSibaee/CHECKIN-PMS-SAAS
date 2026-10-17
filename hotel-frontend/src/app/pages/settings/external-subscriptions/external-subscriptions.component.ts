import { Component, OnInit } from '@angular/core';
import { ExternalSubscriptionService } from '../../../services/external-subscription.service';
import { HotelService } from '../../../services/hotel.service';
import { DataService } from '../../../services/Api/data.service';
import { TranslateService } from '@ngx-translate/core';
import { AuthPermissionService } from '../../../services/auth-permission.service';
import {
  AudiencePreviewClient,
  GuestClassification,
  PromoAudienceType,
  PromoCampaign,
  TaqnyatBalance
} from '../../../models/promo-campaign.interface';

@Component({
    selector: 'app-external-subscriptions',
    templateUrl: './external-subscriptions.component.html',
    styleUrls: ['./external-subscriptions.component.css'],
    standalone: false
})
export class ExternalSubscriptionsComponent implements OnInit {
  activeTab: 'taqnyat' | 'campaigns' = 'taqnyat';

  // Taqnyat balance
  balance: TaqnyatBalance | null = null;
  isLoadingBalance = false;
  credentialsForm = { token: '', sender: '' };
  isSavingCredentials = false;
  showCredentialsForm = false;

  // Campaign composer
  classifications: GuestClassification[] = [];
  campaigns: PromoCampaign[] = [];
  isLoadingCampaigns = false;

  messageBody = '';
  audienceType: PromoAudienceType = 'classification';
  selectedClassificationId: number | null = null;
  manualSearch = '';
  manualResults: { id: number; name: string; mobile?: string }[] = [];
  selectedClients: { id: number; name: string }[] = [];

  previewCount: number | null = null;
  isPreviewing = false;
  isSavingDraft = false;

  constructor(
    private externalSubscriptionService: ExternalSubscriptionService,
    private hotelService: HotelService,
    private dataService: DataService,
    private translate: TranslateService,
    public authPerm: AuthPermissionService
  ) {}

  ngOnInit(): void {
    this.loadBalance();
    this.loadClassifications();
    this.loadCampaigns();
  }

  loadBalance(): void {
    this.isLoadingBalance = true;
    this.externalSubscriptionService.fetchTaqnyatBalance().subscribe({
      next: (res) => {
        this.isLoadingBalance = false;
        this.balance = res.success ? res.data : null;
      },
      error: () => {
        this.isLoadingBalance = false;
        this.balance = null;
      }
    });
  }

  saveCredentials(): void {
    if (!this.credentialsForm.token.trim() || this.isSavingCredentials) return;
    this.isSavingCredentials = true;
    this.externalSubscriptionService.saveTaqnyatCredentials(this.credentialsForm.token, this.credentialsForm.sender || null).subscribe({
      next: (res) => {
        this.isSavingCredentials = false;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('EXTERNAL_SUBSCRIPTIONS.CREDENTIALS_SAVED'), 'success');
          this.showCredentialsForm = false;
          this.credentialsForm = { token: '', sender: '' };
          this.loadBalance();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('EXTERNAL_SUBSCRIPTIONS.CREDENTIALS_SAVE_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSavingCredentials = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('EXTERNAL_SUBSCRIPTIONS.CREDENTIALS_SAVE_ERROR'), 'error');
      }
    });
  }

  loadClassifications(): void {
    this.externalSubscriptionService.fetchGuestClassifications().subscribe((res: any) => {
      this.classifications = res?.data ?? [];
    });
  }

  loadCampaigns(): void {
    this.isLoadingCampaigns = true;
    this.externalSubscriptionService.fetchCampaigns().subscribe({
      next: (res) => {
        this.isLoadingCampaigns = false;
        this.campaigns = res.success ? (res.data ?? []) : [];
      },
      error: () => {
        this.isLoadingCampaigns = false;
      }
    });
  }

  searchManualClients(): void {
    if (!this.manualSearch.trim()) {
      this.manualResults = [];
      return;
    }
    this.hotelService.fetchClients(this.manualSearch, '20').subscribe((clients: any) => {
      this.manualResults = (clients ?? []).map((c: any) => ({
        id: Number(c.id),
        name: `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim(),
        mobile: c.mobile
      }));
    });
  }

  addManualClient(client: { id: number; name: string }): void {
    if (!this.selectedClients.some(c => c.id === client.id)) {
      this.selectedClients.push(client);
      this.previewAudience();
    }
    this.manualSearch = '';
    this.manualResults = [];
  }

  removeManualClient(id: number): void {
    this.selectedClients = this.selectedClients.filter(c => c.id !== id);
    this.previewAudience();
  }

  onAudienceTypeChange(): void {
    this.previewCount = null;
    this.previewAudience();
  }

  previewAudience(): void {
    if (this.audienceType === 'classification' && !this.selectedClassificationId) {
      this.previewCount = null;
      return;
    }
    if (this.audienceType === 'manual' && this.selectedClients.length === 0) {
      this.previewCount = 0;
      return;
    }

    this.isPreviewing = true;
    const params: any = { audience_type: this.audienceType };
    if (this.audienceType === 'classification') {
      params.guest_classification_id = this.selectedClassificationId;
    } else {
      params.client_ids = this.selectedClients.map(c => c.id);
    }

    this.externalSubscriptionService.audiencePreview(params).subscribe({
      next: (res) => {
        this.isPreviewing = false;
        this.previewCount = res.success ? res.data.count : null;
      },
      error: () => {
        this.isPreviewing = false;
        this.previewCount = null;
      }
    });
  }

  get canSaveDraft(): boolean {
    if (!this.messageBody.trim() || this.isSavingDraft) return false;
    if (this.audienceType === 'classification') return !!this.selectedClassificationId;
    return this.selectedClients.length > 0;
  }

  saveDraft(): void {
    if (!this.canSaveDraft) return;
    this.isSavingDraft = true;

    const payload: any = {
      message_body: this.messageBody,
      audience_type: this.audienceType
    };
    if (this.audienceType === 'classification') {
      payload.guest_classification_id = this.selectedClassificationId;
    } else {
      payload.client_ids = this.selectedClients.map(c => c.id);
    }

    this.externalSubscriptionService.createCampaign(payload).subscribe({
      next: (res) => {
        this.isSavingDraft = false;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('EXTERNAL_SUBSCRIPTIONS.DRAFT_SAVED'), 'success');
          this.messageBody = '';
          this.selectedClients = [];
          this.selectedClassificationId = null;
          this.previewCount = null;
          this.loadCampaigns();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('EXTERNAL_SUBSCRIPTIONS.DRAFT_SAVE_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSavingDraft = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('EXTERNAL_SUBSCRIPTIONS.DRAFT_SAVE_ERROR'), 'error');
      }
    });
  }
}
