<?php

namespace App\MessageHandler;

use App\Message\SweepRentals;
use App\Service\Rental\RentalSweeper;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SweepRentalsHandler
{
    public function __construct(
        private readonly RentalSweeper $sweeper,
    ) {
    }

    public function __invoke(SweepRentals $message): void
    {
        $this->sweeper->sweep();
    }
}
