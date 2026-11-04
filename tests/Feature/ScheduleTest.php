<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Taches planifiees (routes/console.php) : aucune ne demande pcntl_signal. Le php.ini du serveur (HestiaCP) desactive
 * cette fonction, et Laravel l'appelle avant chaque tache sinon : "Call to undefined function pcntl_signal", tache jamais faite.
 */
class ScheduleTest extends TestCase
{
    public function test_no_scheduled_task_needs_pcntl_signal(): void
    {
        Artisan::call('schedule:list');
        $events = app(Schedule::class)->events();

        $this->assertEqualsCanonicalizing(['nettoyage-demandes', 'merge-ai'], array_map(fn ($e) => $e->description, $events));
        foreach ($events as $event) {
            $this->assertFalse($event->releaseOnTerminationSignals, $event->description);
            $this->assertTrue($event->withoutOverlapping, $event->description);
        }
    }
}
