<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('quality/dashboard', function () {
    return Inertia::render('quality/Dashboard');
})->middleware(['auth', 'verified'])->name('quality.dashboard');

require __DIR__.'/settings.php';
