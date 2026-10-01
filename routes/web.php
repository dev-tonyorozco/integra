<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('public.welcome'))->name('home');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::get('/password/forgot', [AuthController::class, 'forgot'])->name('password.request');
    Route::post('/password/email', [AuthController::class, 'email'])->name('password.email')->middleware('throttle:3,1');
    Route::get('/password/reset/{token}', [AuthController::class, 'resetForm'])->name('password.reset');
    Route::post('/password/reset', [AuthController::class, 'reset'])->name('password.update')->middleware('throttle:10,1');
});
Route::get('/c/{slug}', [PublicController::class, 'home'])->name('public.home');
Route::get('/apply/{slug}', [PublicController::class, 'apply'])->name('public.apply');
Route::post('/apply/{slug}', [PublicController::class, 'submit'])->middleware('throttle:10,1');
Route::get('/receipt', [PublicController::class, 'receipt'])->name('receipt');
Route::get('/tasks/{token}', [PublicController::class, 'task'])->name('public.task');
Route::post('/tasks/{token}', [PublicController::class, 'answer'])->middleware('throttle:10,1');
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/switch', [AuthController::class, 'switch'])->name('switch');
    Route::prefix('app')->middleware('organization')->group(function () {
        Route::get('/', [CaseController::class, 'dashboard'])->name('dashboard');
        Route::get('/users', [DirectoryController::class, 'index'])->name('directory');
        Route::get('/users/export', [DirectoryController::class, 'export'])->name('directory.export');
        Route::get('/users/new', [DirectoryController::class, 'edit'])->name('directory.new');
        Route::post('/users/new', [DirectoryController::class, 'save']);
        Route::get('/users/{id}', [DirectoryController::class, 'edit'])->name('directory.edit')->whereNumber('id');
        Route::post('/users/{id}', [DirectoryController::class, 'save'])->whereNumber('id');
        Route::get('/catalog/opportunities/{id}/qr', [CatalogController::class, 'qr'])->name('catalog.qr');
        Route::get('/catalog/{catalog}', [CatalogController::class, 'index'])->name('catalog');
        Route::get('/catalog/{catalog}/export', [CatalogController::class, 'export'])->name('catalog.export');
        Route::get('/catalog/{catalog}/new', [CatalogController::class, 'edit'])->name('catalog.new');
        Route::post('/catalog/{catalog}/new', [CatalogController::class, 'save']);
        Route::get('/catalog/{catalog}/{id}', [CatalogController::class, 'edit'])->name('catalog.edit')->whereNumber('id');
        Route::post('/catalog/{catalog}/{id}', [CatalogController::class, 'save'])->whereNumber('id');
        Route::post('/catalog/{catalog}/{id}/duplicate', [CatalogController::class, 'duplicate'])->name('catalog.duplicate');
        Route::get('/cases', [CaseController::class, 'index'])->name('cases');
        Route::get('/cases/export', [CaseController::class, 'export'])->name('cases.export');
        Route::get('/cases/{id}', [CaseController::class, 'show'])->name('cases.show')->whereNumber('id');
        Route::post('/cases/{id}', [CaseController::class, 'act'])->whereNumber('id');
        Route::get('/cases/{id}/pdf', [CaseController::class, 'pdf'])->name('cases.pdf');
        Route::get('/files/{id}', [CaseController::class, 'file'])->name('files');
        Route::get('/reports', [CaseController::class, 'reports'])->name('reports');
        Route::get('/notifications', [CaseController::class, 'notifications'])->name('notifications');
        Route::post('/notifications/{id}', [CaseController::class, 'read'])->name('notifications.read');
        Route::get('/audit', [CaseController::class, 'audit'])->name('audit');
        Route::get('/technical',[CaseController::class, 'technical'])->name('technical');
    });
});
