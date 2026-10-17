import { Component, OnInit } from '@angular/core';
import { PosChargeService } from '../../../services/pos-charge.service';
import { DataService } from '../../../services/Api/data.service';
import { TranslateService } from '@ngx-translate/core';
import {
  PosCategory,
  PosRoomLookupResult,
  POS_PAYMENT_STATUS_PAID_IMMEDIATELY,
  POS_PAYMENT_STATUS_CHARGED_TO_ROOM,
} from '../../../models/pos-charge.interface';

@Component({
    selector: 'app-pos-quick-entry',
    templateUrl: './pos-quick-entry.component.html',
    styleUrls: ['./pos-quick-entry.component.css'],
    standalone: false
})
export class PosQuickEntryComponent implements OnInit {
  readonly PAID_IMMEDIATELY = POS_PAYMENT_STATUS_PAID_IMMEDIATELY;
  readonly CHARGED_TO_ROOM = POS_PAYMENT_STATUS_CHARGED_TO_ROOM;

  categories: PosCategory[] = [];

  roomNumber = '';
  isLookingUp = false;
  lookupResult: PosRoomLookupResult | null = null;
  lookupAttempted = false;
  isWalkin = false;

  form = {
    pos_category_id: null as number | null,
    description: '',
    invoice_reference: '',
    quantity: 1,
    unit_price: 0,
    payment_status: this.PAID_IMMEDIATELY,
    walkin_reference: '',
  };
  isSaving = false;

  constructor(
    private posChargeService: PosChargeService,
    private dataService: DataService,
    private translate: TranslateService,
  ) {}

  ngOnInit(): void {
    this.posChargeService.listCategories().subscribe((res: any) => {
      this.categories = res?.data ?? [];
    });
  }

  get amount(): number {
    return round2((this.form.quantity || 0) * (this.form.unit_price || 0));
  }

  get taxAmount(): number {
    return round2(this.amount * 0.15);
  }

  get total(): number {
    return round2(this.amount + this.taxAmount);
  }

  startWalkin(): void {
    this.isWalkin = true;
    this.lookupResult = null;
    this.lookupAttempted = false;
    this.roomNumber = '';
    this.form.payment_status = this.PAID_IMMEDIATELY;
  }

  backToRoomSearch(): void {
    this.isWalkin = false;
    this.lookupResult = null;
    this.lookupAttempted = false;
    this.roomNumber = '';
  }

  lookupRoom(): void {
    if (!this.roomNumber.trim() || this.isLookingUp) return;

    this.isLookingUp = true;
    this.lookupAttempted = false;
    this.posChargeService.lookupRoom(this.roomNumber.trim()).subscribe({
      next: (res) => {
        this.isLookingUp = false;
        this.lookupAttempted = true;
        if (res.success) {
          this.lookupResult = res.data;
          this.form.payment_status = this.lookupResult?.reservation_id
            ? this.CHARGED_TO_ROOM
            : this.PAID_IMMEDIATELY;
        } else {
          this.lookupResult = null;
          this.dataService.showToast(res.message || this.translate.instant('POS.ROOM_NOT_FOUND'), 'error');
        }
      },
      error: (err) => {
        this.isLookingUp = false;
        this.lookupAttempted = true;
        this.lookupResult = null;
        this.dataService.showToast(err.error?.message || this.translate.instant('POS.ROOM_NOT_FOUND'), 'error');
      }
    });
  }

  canChargeToRoom(): boolean {
    return !this.isWalkin && !!this.lookupResult?.reservation_id;
  }

  resetItemFields(): void {
    this.form = {
      pos_category_id: null,
      description: '',
      invoice_reference: '',
      quantity: 1,
      unit_price: 0,
      payment_status: this.canChargeToRoom() ? this.CHARGED_TO_ROOM : this.PAID_IMMEDIATELY,
      walkin_reference: '',
    };
  }

  canSubmit(): boolean {
    return !!this.form.pos_category_id && this.form.quantity > 0 && this.form.unit_price >= 0 && !this.isSaving;
  }

  submit(): void {
    if (!this.canSubmit()) return;

    this.isSaving = true;

    const payload: any = {
      pos_category_id: this.form.pos_category_id,
      description: this.form.description || null,
      invoice_reference: this.form.invoice_reference || null,
      quantity: this.form.quantity,
      unit_price: this.form.unit_price,
      payment_status: this.form.payment_status,
    };

    if (this.canChargeToRoom()) {
      payload.reservation_id = this.lookupResult!.reservation_id;
    } else {
      payload.walkin_reference = this.isWalkin
        ? (this.form.walkin_reference || null)
        : `${this.translate.instant('POS.ROOM')} ${this.roomNumber}`;
    }

    this.posChargeService.create(payload).subscribe({
      next: (res) => {
        this.isSaving = false;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('POS.CHARGE_SAVED'), 'success');
          this.resetItemFields();
        } else {
          this.dataService.showToast(res.message || this.translate.instant('POS.CHARGE_SAVE_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSaving = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('POS.CHARGE_SAVE_ERROR'), 'error');
      }
    });
  }
}

function round2(value: number): number {
  return Math.round(value * 100) / 100;
}
