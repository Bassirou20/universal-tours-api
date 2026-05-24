<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Scan des factures impayées échues — chaque jour à 09h00
        $schedule->command('notifications:scan-overdue --days=7')
            ->dailyAt('09:00')
            ->withoutOverlapping();

        // Récap quotidien — chaque jour à 19h00
        $schedule->command('notifications:daily-summary')
            ->dailyAt('19:00')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
