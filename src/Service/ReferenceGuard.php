<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Proxy;

/**
 * Tells whether deleting an entity would orphan business records.
 *
 * A row that points at the entity without ON DELETE CASCADE / SET NULL is a
 * record that must keep its reference (a booking's vehicle, a payment's
 * customer, a booking's add-on line), so the entity cannot be deleted while
 * one exists. The relations are read from the Doctrine mappings, so a new one
 * is covered without changing this class.
 */
final class ReferenceGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isReferenced(object $entity): bool
    {
        $class = $entity instanceof Proxy ? get_parent_class($entity) : $entity::class;

        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }

            foreach ($metadata->getAssociationMappings() as $field => $mapping) {
                if ($class !== $mapping->targetEntity || !$mapping->isToOneOwningSide()) {
                    continue;
                }

                $onDelete = strtoupper((string) ($mapping->joinColumns[0]->onDelete ?? ''));
                if (\in_array($onDelete, ['CASCADE', 'SET NULL'], true)) {
                    continue;
                }

                $count = (int) $this->entityManager->createQueryBuilder()
                    ->select('COUNT(e)')
                    ->from($metadata->getName(), 'e')
                    ->where(\sprintf('e.%s = :target', $field))
                    ->setParameter('target', $entity)
                    ->getQuery()
                    ->getSingleScalarResult();

                if ($count > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
