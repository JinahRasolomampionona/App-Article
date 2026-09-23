<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\StatisticsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Invités
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
});

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

/*
|--------------------------------------------------------------------------
| Application (authentification obligatoire)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    /* --- Sites WordPress --- */
    Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
    Route::get('/sites/create', [SiteController::class, 'create'])->name('sites.create');
    Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
    Route::get('/sites/{site}/edit', [SiteController::class, 'edit'])->name('sites.edit');
    Route::put('/sites/{site}', [SiteController::class, 'update'])->name('sites.update');
    Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');

    Route::post('/sites/{site}/test', [SiteController::class, 'test'])->name('sites.test');
    Route::post('/sites/{site}/sync', [SiteController::class, 'sync'])->name('sites.sync');
    Route::get('/sites/{site}/sync-status', [SiteController::class, 'syncStatus'])->name('sites.sync-status');
    Route::post('/sites/{site}/select', [SiteController::class, 'select'])->name('sites.select');

    /* --- Médiathèque --- */
    Route::get('/sites/{site}/media', [MediaController::class, 'index'])->name('sites.media.index');
    Route::post('/sites/{site}/media', [MediaController::class, 'store'])->name('sites.media.store');
    Route::get('/sites/{site}/media/{media}', [MediaController::class, 'show'])
        ->whereNumber('media')->name('sites.media.show');
    Route::put('/sites/{site}/media/{media}', [MediaController::class, 'update'])
        ->whereNumber('media')->name('sites.media.update');

    /* --- Articles --- */
    Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
    Route::post('/articles/bulk/audit', [ArticleController::class, 'bulkAudit'])->name('articles.bulk-audit');
    Route::get('/articles/{article}', [ArticleController::class, 'show'])->name('articles.show');
    Route::get('/articles/{article}/edit', [ArticleController::class, 'edit'])->name('articles.edit');
    Route::put('/articles/{article}', [ArticleController::class, 'update'])->name('articles.update');
    Route::post('/articles/{article}/audit', [ArticleController::class, 'auditArticle'])->name('articles.audit');
    Route::post('/articles/{article}/status', [ArticleController::class, 'updateStatus'])->name('articles.status');
    Route::post('/articles/{article}/agent', [ArticleController::class, 'updateAgent'])->name('articles.agent');
    Route::post('/articles/{article}/refresh', [ArticleController::class, 'refresh'])->name('articles.refresh');
    Route::get('/articles/{article}/issues', [ArticleController::class, 'issues'])->name('articles.issues');

    /* --- Audits --- */
    Route::get('/audits', [AuditController::class, 'index'])->name('audits.index');

    /* --- Statistiques --- */
    Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
    Route::get('/statistics/series', [StatisticsController::class, 'series'])->name('statistics.series');
    Route::post('/audits/run', [AuditController::class, 'runForSite'])->name('audits.run');

    /* --- Paramètres --- */
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});
