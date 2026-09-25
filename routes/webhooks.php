<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Paste these URLs into your Meta app (see Admin -> Channels for the full URLs).
Route::get('webhooks/whatsapp', [WebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('webhooks/whatsapp', [WebhookController::class, 'whatsapp'])->name('webhooks.whatsapp');

Route::get('webhooks/messenger', [WebhookController::class, 'verify'])->name('webhooks.messenger.verify');
Route::post('webhooks/messenger', [WebhookController::class, 'messenger'])->name('webhooks.messenger');
