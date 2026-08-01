<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\V1\SimcardController;
use App\Http\Controllers\V1\TopupController;
use App\Http\Controllers\V1\Webhooks\EsimaccessWebhookController;

Route::post('v1/webhooks/esimaccess', EsimaccessWebhookController::class);


Route::prefix('v1/topupcontroller')
    ->middleware('stellar.sim.basic')
    ->group(function () {
        Route::post('/token', [TopupController::class, 'token']);
        Route::get('/resolve/{token}', [TopupController::class, 'resolve']);
        Route::post('/checkout', [TopupController::class, 'checkout']);
        Route::post('/prepare', [TopupController::class, 'prepare']);
        Route::post('/fulfill', [TopupController::class, 'fulfill']);
    });

Route::prefix('v1/sim')
    ->middleware('stellar.sim.basic')
    ->group(function () {
        // GET /api/v1/sim/plans
        Route::get('/plans', [SimcardController::class, 'plans']);

        // POST /api/v1/sim/order
        Route::post('/order', [SimcardController::class, 'order']);

        // POST /api/v1/sim/query
        Route::post('/query', [SimcardController::class, 'query']);

        // POST /api/v1/sim/user
        Route::post('/user', [SimcardController::class, 'user'])
            ->middleware('throttle:sim.user.read');

        // PATCH /api/v1/sim/user
        Route::patch('/user', [SimcardController::class, 'patchUser'])
            ->middleware('throttle:sim.user.write');

        // DELETE /api/v1/sim/user
        Route::delete('/user', [SimcardController::class, 'deleteUser'])
            ->middleware('throttle:sim.user.write');

        // DELETE /api/v1/sim/user/all
        Route::delete('/user/all', [SimcardController::class, 'deleteAllUser'])
            ->middleware('throttle:sim.user.write');

    });
