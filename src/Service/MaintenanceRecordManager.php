<?php

namespace App\Service;

use App\Entity\MaintenanceRecord;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saves, edits and deletes maintenance records, with an audit trail entry for each.
 */
final class MaintenanceRecordManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(MaintenanceRecord $record, User $author): void
    {
        $record->setCreatedBy($author);
        $this->entityManager->persist($record);
        $this->entityManager->flush();
        $this->auditLogger->log('maintenance_created', 'maintenance_record', $record->getId(), self::details($record) + AuditLogger::actor($author), $author);
    }

    public function update(MaintenanceRecord $record): void
    {
        $this->entityManager->flush();
        $this->auditLogger->log('maintenance_updated', 'maintenance_record', $record->getId(), self::details($record));
    }

    /**
     * Work still in progress has taken its vehicle offline, so it is cancelled
     * first to put the vehicle back into availability.
     */
    public function delete(MaintenanceRecord $record): void
    {
        $id = $record->getId();
        $details = self::details($record);

        if (MaintenanceRecord::STATUS_IN_PROGRESS === $record->getStatus()) {
            $record->changeStatus(MaintenanceRecord::STATUS_CANCELLED);
        }

        $this->entityManager->remove($record);
        $this->entityManager->flush();
        $this->auditLogger->log('maintenance_deleted', 'maintenance_record', $id, $details);
    }

    /**
     * Which vehicle and service a record is about, for the audit trail.
     *
     * @return array<string, int|string|null>
     */
    private static function details(MaintenanceRecord $record): array
    {
        return [
            'vehicle_id' => $record->getVehicle()?->getId(),
            'title' => $record->getTitle(),
            'status' => $record->getStatus(),
            'cost' => $record->getCost(),
        ];
    }
}
