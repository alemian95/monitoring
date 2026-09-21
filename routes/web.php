<?php

use App\Http\Controllers\StatusPageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/status');

Route::get('/status', [StatusPageController::class, 'index'])->name('status.index');

// La stessa pagina serve il servizio pubblico e quello a link firmato: e'
// `MonitorVisibility` a decidere chi passa, perche' e' una proprieta' del
// servizio e non della rotta.
Route::get('/status/{monitor}', [StatusPageController::class, 'show'])->name('status.monitor');
