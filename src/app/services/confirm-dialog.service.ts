import { Injectable } from '@angular/core';
import { BehaviorSubject } from 'rxjs';

export interface ConfirmDialogOptions {
  message: string;
  title: string;
  confirmText: string;
  cancelText: string;
  variant: 'danger' | 'primary';
}

export interface ConfirmDialogState {
  visible: boolean;
  options: ConfirmDialogOptions | null;
}

@Injectable({ providedIn: 'root' })
export class ConfirmDialogService {
  private resolveFn: ((value: boolean) => void) | null = null;

  private readonly stateSubject = new BehaviorSubject<ConfirmDialogState>({
    visible: false,
    options: null,
  });

  readonly state$ = this.stateSubject.asObservable();

  confirm(message: string, options?: Partial<ConfirmDialogOptions>): Promise<boolean> {
    return new Promise((resolve) => {
      this.resolveFn = resolve;
      this.stateSubject.next({
        visible: true,
        options: {
          message,
          title: options?.title ?? 'Confirm',
          confirmText: options?.confirmText ?? 'OK',
          cancelText: options?.cancelText ?? 'Cancel',
          variant: options?.variant ?? 'danger',
        },
      });
    });
  }

  accept(): void {
    this.resolveFn?.(true);
    this.close();
  }

  cancel(): void {
    this.resolveFn?.(false);
    this.close();
  }

  private close(): void {
    this.stateSubject.next({ visible: false, options: null });
    this.resolveFn = null;
  }
}
