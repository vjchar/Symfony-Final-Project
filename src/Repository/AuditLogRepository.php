<?php

namespace App\Repository;

use App\Entity\AuditLog;
use App\Pagination\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * The admin audit trail, newest first: an action containing the text, one
     * record type, one record id, and who did it (name or email).
     *
     * @return Page<AuditLog>
     */
    public function search(string $action = '', string $entityType = '', ?int $entityId = null, string $actor = '', int $page = 1): Page
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('u')
            ->leftJoin('a.user', 'u')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');
        if ('' !== $action = trim($action)) {
            $qb->andWhere('a.action LIKE :action')->setParameter('action', '%'.addcslashes($action, '%_').'%');
        }
        if ('' !== $entityType) {
            $qb->andWhere('a.entityType = :type')->setParameter('type', $entityType);
        }
        if (null !== $entityId) {
            $qb->andWhere('a.entityId = :entityId')->setParameter('entityId', $entityId);
        }
        if ('' !== $actor = trim($actor)) {
            $qb->andWhere('LOWER(u.name) LIKE :actor OR LOWER(u.email) LIKE :actor')
                ->setParameter('actor', '%'.addcslashes(mb_strtolower($actor), '%_').'%');
        }

        return Page::of($qb, $page, 50);
    }

    /**
     * @return list<string> the record types that appear in the trail, A-Z
     */
    public function findEntityTypes(): array
    {
        return $this->createQueryBuilder('a')
            ->select('DISTINCT a.entityType')
            ->orderBy('a.entityType', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }
}
