<?php

use App\Http\Controllers\ExternalSubscriptionController;
use App\Http\Controllers\PromoCampaignController;
use Illuminate\Support\Facades\Route;

// External messaging-provider subscriptions (Taqnyat SMS balance) and
// promotional campaign drafts. Admin/manager only — see the plan doc for
// why this is a higher trust bar than POS charges.

Route::middleware(['permission:manage external subscriptions,api'])->group(function () {
    Route::get('external-subscriptions/taqnyat', [ExternalSubscriptionController::class, 'taqnyatBalance']);
    Route::post('external-subscriptions/taqnyat/credentials', [ExternalSubscriptionController::class, 'saveTaqnyatCredentials']);

    Route::get('promo-campaigns/guest-classifications', [PromoCampaignController::class, 'guestClassifications']);
    Route::get('promo-campaigns/audience-preview', [PromoCampaignController::class, 'audiencePreview']);
    Route::get('promo-campaigns', [PromoCampaignController::class, 'index']);
    Route::post('promo-campaigns', [PromoCampaignController::class, 'store']);
});
