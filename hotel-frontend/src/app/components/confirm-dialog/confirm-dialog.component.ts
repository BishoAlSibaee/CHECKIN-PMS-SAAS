import { Component } from '@angular/core';
import { ConfirmDialogService, ConfirmDialogState } from '../../services/confirm-dialog.service';
import { Observable } from 'rxjs';

@Component({
    selector: 'app-confirm-dialog',
    templateUrl: './confirm-dialog.component.html',
    styleUrls: ['./confirm-dialog.component.css'],
    standalone: false
})
export class ConfirmDialogComponent {
  state$: Observable<ConfirmDialogState>;

  constructor(private confirmDialog: ConfirmDialogService) {
    this.state$ = this.confirmDialog.state$;
  }

  onBackdropClick(): void {
    this.confirmDialog.cancel();
  }

  onCancel(): void {
    this.confirmDialog.cancel();
  }

  onConfirm(): void {
    this.confirmDialog.accept();
  }
}
