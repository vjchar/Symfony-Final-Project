<?php

namespace App\Service\Booking;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Unlike EntityManager::wrapInTransaction(), a refused change leaves the
 * entity manager open, so the page can still render the form with the message.
 *
 * @property EntityManagerInterface $entityManager
 */
trait RunsInTransaction
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transactional(callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }
}
