<?php

use App\Enums\OutboundAbility;
use App\Http\Controllers\Api\V1\OutboundController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')
    ->middleware('auth:sanctum')
    ->name('api.v1.')
    ->group(function (): void {
        Route::post('/outbounds', [OutboundController::class, 'store'])
            ->middleware('abilities:'.OutboundAbility::Create->value)
            ->name('outbounds.store');

        Route::get('/outbounds/{outbound}', [OutboundController::class, 'show'])
            ->middleware('abilities:'.OutboundAbility::Read->value)
            ->name('outbounds.show');

        Route::post('/outbounds/{outbound}/replay', [OutboundController::class, 'replay'])
            ->middleware('abilities:'.OutboundAbility::Replay->value)
            ->name('outbounds.replay');
    });
