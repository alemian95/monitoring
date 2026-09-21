<?php

use App\Http\Controllers\StatusPageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/status');

// Il riepilogo di tutti i servizi e' roba interna: fuori dal pannello si vede
// un servizio alla volta, mai l'elenco di quello che c'e'.
Route::get('/status', [StatusPageController::class, 'index'])
    ->middleware('auth')
    ->name('status.index');

// Chi passa lo decide `MonitorVisibility`, perche' e' una proprieta' del
// servizio e non della rotta.
Route::get('/status/{monitor}', [StatusPageController::class, 'show'])->name('status.monitor');
