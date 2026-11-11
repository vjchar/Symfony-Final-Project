<?php

namespace App;

use App\Message\SweepRentals;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The app's recurring work. Run it with a worker:
 *     php bin/console messenger:consume scheduler_default
 * (or, without a worker, call app:rentals:sweep from cron every few minutes).
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            // expire unpaid bookings, flag overdue rentals and uncollected pickups
            ->add(RecurringMessage::every('10 minutes', new SweepRentals()))
        ;
    }
}
