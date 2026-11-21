<?php

use Illuminate\Support\Facades\Route;

// Every non-API address opens the React application, which does its own routing.
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api/|up$|build/|storage/).*$')
    ->name('app');
