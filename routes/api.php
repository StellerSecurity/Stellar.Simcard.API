<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\V1\SimcardController;

Route::prefix('v1/sim')
    ->middleware('stellar.sim.basic')
    ->group(function () {
        // GET /api/v1/sim/plans
        Route::get('/plans', [SimcardController::class, 'plans']);

        // POST /api/v1/sim/order
        Route::post('/order', [SimcardController::class, 'order']);

        // GET /api/v1/sim/query/{planId}
        Route::get('/query/{planId}', [SimcardController::class, 'query']);
    });
