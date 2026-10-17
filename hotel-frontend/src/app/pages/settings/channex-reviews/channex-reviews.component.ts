import { Component, OnInit } from '@angular/core';
import { HotelService } from '../../../services/hotel.service';
import { ChannexReview, ChannexPropertyScore } from '../../../models/channex.interface';
import { TranslateService } from '@ngx-translate/core';
import { DataService } from '../../../services/Api/data.service';

/**
 * Guest reviews from every connected OTA in one place, with a real reply
 * capability (POST /reviews/:id/reply via Channex) — previously these were
 * only visible on each platform's own dashboard. Note: confirmed live
 * against the real Channex sandbox that the Reviews API currently returns
 * 403 Forbidden on this account's plan, so this page is built and tested
 * (via mocked responses) but not yet verified against real review data.
 */
@Component({
    selector: 'app-channex-reviews',
    templateUrl: './channex-reviews.component.html',
    styleUrls: ['./channex-reviews.component.css'],
    standalone: false
})
export class ChannexReviewsComponent implements OnInit {
  reviews: ChannexReview[] = [];
  scores: ChannexPropertyScore | null = null;
  isLoading = false;
  isLoadingScores = false;
  loadError: string | null = null;

  page = 1;
  limit = 20;
  hasNextPage = false;

  replyDraft: Record<string, string> = {};
  submittingReviewId: string | null = null;

  constructor(
    private hotelService: HotelService,
    private translate: TranslateService,
    private dataService: DataService,
  ) {}

  ngOnInit(): void {
    this.loadReviews();
    this.loadScores();
  }

  loadReviews(): void {
    this.isLoading = true;
    this.loadError = null;
    this.hotelService.fetchChannexReviews(this.page, this.limit).subscribe({
      next: (res) => {
        this.isLoading = false;
        if (res.success) {
          this.reviews = res.data?.reviews ?? [];
          this.hasNextPage = this.reviews.length >= this.limit;
          this.reviews.forEach((r) => (this.replyDraft[r.id] = r.reply ?? ''));
        } else {
          this.loadError = res.message || this.translate.instant('CHANNEX_REVIEWS.LOAD_ERROR');
        }
      },
      error: (err) => {
        this.isLoading = false;
        this.loadError = err.error?.message || this.translate.instant('CHANNEX_REVIEWS.LOAD_ERROR');
      }
    });
  }

  loadScores(): void {
    this.isLoadingScores = true;
    this.hotelService.fetchChannexReviewScores().subscribe({
      next: (res) => {
        this.isLoadingScores = false;
        this.scores = res.success ? (res.data ?? null) : null;
      },
      error: () => {
        this.isLoadingScores = false;
        this.scores = null;
      }
    });
  }

  nextPage(): void {
    if (!this.hasNextPage) return;
    this.page++;
    this.loadReviews();
  }

  previousPage(): void {
    if (this.page <= 1) return;
    this.page--;
    this.loadReviews();
  }

  submitReply(review: ChannexReview): void {
    const text = (this.replyDraft[review.id] || '').trim();
    if (!text || this.submittingReviewId) return;

    this.submittingReviewId = review.id;
    this.hotelService.replyToChannexReview(review.id, text).subscribe({
      next: (res) => {
        this.submittingReviewId = null;
        if (res.success) {
          this.dataService.showToast(this.translate.instant('CHANNEX_REVIEWS.REPLY_SUCCESS'), 'success');
          review.reply = text;
          review.is_replied = true;
        } else {
          this.dataService.showToast(res.message || this.translate.instant('CHANNEX_REVIEWS.REPLY_ERROR'), 'error');
        }
      },
      error: (err) => {
        this.submittingReviewId = null;
        this.dataService.showToast(err.error?.message || this.translate.instant('CHANNEX_REVIEWS.REPLY_ERROR'), 'error');
      }
    });
  }

  scoreClass(score: number): string {
    if (score >= 8) return 'channex-score--good';
    if (score >= 5) return 'channex-score--mid';
    return 'channex-score--poor';
  }
}
