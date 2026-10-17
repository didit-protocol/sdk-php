<?php

declare(strict_types=1);

// Add this route to your routes/api.php. Routes there are served under /api and
// skip the CSRF check, so the destination URL is https://your-app.example/api/webhooks/didit.

use App\Http\Controllers\DiditWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/didit', DiditWebhookController::class)->name('webhooks.didit');
