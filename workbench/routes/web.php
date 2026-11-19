<?php

/*
 * Demo app routes. Testbench loads this file inside the "web" middleware
 * group (testbench.yaml: workbench.discovers.web).
 */

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\LoginController;

Route::get('/', fn () => auth()->check()
    ? 'Signed in as '.e((string) auth()->user()?->getAttribute('email'))
    : 'OpenID provider demo. Visit /.well-known/openid-configuration.');

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
