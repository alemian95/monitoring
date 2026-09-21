<?php

use App\Http\Controllers\PingController;
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

// L'ingresso dei push monitor: il segreto e' il token nell'URL, perche' e' un
// indirizzo che finisce nella crontab di qualcun altro e deve poter essere
// revocato da solo. Il throttle serve a rendere noioso chi lo trovasse.
Route::match(['get', 'post'], '/ping/{token}/{status?}', PingController::class)
    ->whereIn('status', ['fail'])
    ->middleware('throttle:60,1')
    ->name('monitor.ping');
