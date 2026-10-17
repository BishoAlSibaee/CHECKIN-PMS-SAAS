import { Component, OnInit } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { ChannexMessageThread, ChannexMessage } from '../../../models/channex.interface';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';

/**
 * A unified inbox for guest chat threads across every connected OTA
 * (Booking.com, Expedia, Airbnb — the only three Channex supports).
 * Threads can exist with no booking at all: Airbnb "Inquiry" messages are
 * sent before a booking is made. Previously these were only visible on each
 * platform's own separate dashboard.
 */
@Component({
    selector: 'app-channex-messages',
    templateUrl: './channex-messages.component.html',
    styleUrls: ['./channex-messages.component.css'],
    standalone: false
})
export class ChannexMessagesComponent implements OnInit {
  threads: ChannexMessageThread[] = [];
  isLoadingThreads = false;
  loadError: string | null = null;

  page = 1;
  limit = 20;
  hasNextPage = false;

  selectedThread: ChannexMessageThread | null = null;
  messages: ChannexMessage[] = [];
  isLoadingMessages = false;

  replyDraft = '';
  isSubmittingReply = false;
  isMarkingNoReply = false;
  isClosingThread = false;

  constructor(
    private hotelService: HotelService,
    private translate: TranslateService,
    private dataService: DataService,
  ) {}

  ngOnInit(): void {
    this.loadThreads();
  }

  loadThreads(): void {
    this.isLoadingThreads = true;
    this.loadError = null;
    this.hotelService.fetchChannexMessageThreads(this.page, this.limit).subscribe({
      next: (res) => {
        this.isLoadingThreads = false;
        if (res.success) {
          this.threads = res.data?.threads ?? [];
          this.hasNextPage = this.threads.length >= this.limit;
        } else {
          this.loadError = res.message || this.translate.instant('CHANNEX_MESSAGES.LOAD_ERROR');
        }
      },
      error: (err) => {
        this.isLoadingThreads = false;
        this.loadError = err.error?.message || this.translate.instant('CHANNEX_MESSAGES.LOAD_ERROR');
      }
    });
  }

  nextPage(): void {
    if (!this.hasNextPage) return;
    this.page++;
    this.loadThreads();
  }

  previousPage(): void {
    if (this.page <= 1) return;
    this.page--;
    this.loadThreads();
  }

  selectThread(thread: ChannexMessageThread): void {
    this.selectedThread = thread;
    this.messages = [];
    this.replyDraft = '';
    this.isLoadingMessages = true;

    this.hotelService.fetchChannexThreadMessages(thread.id).subscribe({
      next: (res) => {
        this.isLoadingMessages = false;
        this.messages = res.success ? (res.data ?? []) : [];
      },
      error: () => {
        this.isLoadingMessages = false;
        this.messages = [];
      }
    });
  }

  closeConversation(): void {
    this.selectedThread = null;
    this.messages = [];
    this.replyDraft = '';
  }

  submitReply(): void {
    const text = this.replyDraft.trim();
    if (!text || !this.selectedThread || this.isSubmittingReply) return;

    this.isSubmittingReply = true;
    this.hotelService.replyToChannexThread(this.selectedThread.id, text).subscribe({
      next: (res) => {
        this.isSubmittingReply = false;
        if (res.success) {
          this.messages = [...this.messages, { message: text, sender: 'property', inserted_at: new Date().toISOString(), updated_at: new Date().toISOString() }];
          this.replyDraft = '';
          if (this.selectedThread) {
            this.selectedThread.message_count = (this.selectedThread.message_count || 0) + 1;
          }
        } else {
          this.dataService.showToast(res.message || this.translate.instant('CHANNEX_MESSAGES.REPLY_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.isSubmittingReply = false;
        this.dataService.showToast(err.error?.message || this.translate.instant('CHANNEX_MESSAGES.REPLY_ERROR'), 'error');
      }
    });
  }

  markNoReplyNeeded(): void {
    if (!this.selectedThread || this.isMarkingNoReply) return;

    this.isMarkingNoReply = true;
    this.hotelService.markChannexThreadNoReplyNeeded(this.selectedThread.id).subscribe({
      next: (res) => {
        this.isMarkingNoReply = false;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('CHANNEX_MESSAGES.NO_REPLY_SUCCESS'), 'success');
        }
      },
      error: () => {
        this.isMarkingNoReply = false;
      }
    });
  }

  closeThread(): void {
    if (!this.selectedThread || this.isClosingThread) return;

    this.isClosingThread = true;
    this.hotelService.closeChannexThread(this.selectedThread.id).subscribe({
      next: (res) => {
        this.isClosingThread = false;
        if (res.success && this.selectedThread) {
          this.selectedThread.is_closed = true;
          this.dataService.showToast(this.translate.instant('CHANNEX_MESSAGES.CLOSE_SUCCESS'), 'success');
        }
      },
      error: () => {
        this.isClosingThread = false;
      }
    });
  }

  providerIcon(provider: string): string {
    switch (provider) {
      case 'Airbnb': return 'bi-house-heart';
      case 'BookingCom': return 'bi-building';
      case 'Expedia': return 'bi-airplane';
      default: return 'bi-chat-dots';
    }
  }
}
