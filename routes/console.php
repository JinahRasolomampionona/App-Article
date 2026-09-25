<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Verrous de traitement expirés (navigateur fermé sans libérer l'article).
Schedule::command('articles:release-expired')->everyMinute()->withoutOverlapping();
