<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('line:schedule-images-prune')
    ->dailyAt('03:30')
    ->timezone('Asia/Taipei')
    ->onOneServer()
    ->withoutOverlapping();
