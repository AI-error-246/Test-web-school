<?php

use App\Http\Controllers\IdentityController;
use App\Http\Middleware\VerifySupabaseJwt;
use Illuminate\Support\Facades\Route;

Route::get('/me', IdentityController::class)->middleware(VerifySupabaseJwt::class)->name('identity');
