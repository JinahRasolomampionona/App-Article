<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\ArticleLockController;
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
|
| Espace partagé : tous les comptes actifs voient les mêmes sites et les
| mêmes articles. Les droits fins (modifier un article, gérer les comptes…)
| sont contrôlés par les policies et la porte `admin`.
|
*/

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    /* --- Sites WordPress (tous les comptes ; droits fins par la policy) --- */
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
    Route::get('/articles/assignments', [ArticleLockController::class, 'poll'])->name('articles.assignments');
    Route::post('/articles/bulk/audit', [ArticleController::class, 'bulkAudit'])->name('articles.bulk-audit');
    Route::get('/articles/{article}', [ArticleController::class, 'show'])->name('articles.show');
    Route::get('/articles/{article}/edit', [ArticleController::class, 'edit'])->name('articles.edit');
    Route::put('/articles/{article}', [ArticleController::class, 'update'])->name('articles.update');
    Route::post('/articles/{article}/audit', [ArticleController::class, 'auditArticle'])->name('articles.audit');
    Route::post('/articles/{article}/status', [ArticleController::class, 'updateStatus'])->name('articles.status');
    Route::post('/articles/{article}/refresh', [ArticleController::class, 'refresh'])->name('articles.refresh');
    Route::get('/articles/{article}/issues', [ArticleController::class, 'issues'])->name('articles.issues');

    /* --- Prise en charge (verrou) --- */
    Route::post('/articles/{article}/agent', [ArticleLockController::class, 'assign'])->name('articles.agent');
    Route::post('/articles/{article}/take', [ArticleLockController::class, 'take'])->name('articles.take');
    Route::post('/articles/{article}/release', [ArticleLockController::class, 'release'])->name('articles.release');
    Route::post('/articles/{article}/heartbeat', [ArticleLockController::class, 'heartbeat'])->name('articles.heartbeat');
    Route::post('/articles/{article}/finish', [ArticleLockController::class, 'finish'])->name('articles.finish');

    /* --- Audits --- */
    Route::get('/audits', [AuditController::class, 'index'])->name('audits.index');
    Route::post('/audits/run', [AuditController::class, 'runForSite'])->name('audits.run');

    /* --- Statistiques (Admin : globales · Agent : les siennes) --- */
    Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
    Route::get('/statistics/series', [StatisticsController::class, 'series'])->name('statistics.series');

    /*
    |----------------------------------------------------------------------
    | Administration
    |----------------------------------------------------------------------
    */

    Route::middleware('can:admin')->group(function () {
        Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');
        Route::get('/agents/create', [AgentController::class, 'create'])->name('agents.create');
        Route::post('/agents', [AgentController::class, 'store'])->name('agents.store');
        Route::get('/agents/{user}/edit', [AgentController::class, 'edit'])->name('agents.edit');
        Route::put('/agents/{user}', [AgentController::class, 'update'])->name('agents.update');
        Route::delete('/agents/{user}', [AgentController::class, 'destroy'])->name('agents.destroy');

        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });
});
