<?php

namespace App\Command;

use App\Service\Rental\RentalSweeper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:rentals:sweep', description: 'Expire unpaid bookings and flag overdue rentals and uncollected pickups')]
final class SweepRentalsCommand
{
    public function __construct(
        private readonly RentalSweeper $sweeper,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->sweeper->sweep();
        if (null === $result) {
            $io->warning('Another sweep is already running.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d unpaid booking(s) expired, %d rental(s) flagged overdue, %d uncollected pickup(s) flagged.',
            $result->expiredBookings,
            $result->overdueRentals,
            $result->missedPickups,
        ));

        return Command::SUCCESS;
    }
}
