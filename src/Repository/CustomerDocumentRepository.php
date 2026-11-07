<?php

namespace App\Repository;

use App\Entity\CustomerDocument;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomerDocument>
 */
class CustomerDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerDocument::class);
    }

    /**
     * v15 admin_documents(): pending first, then the most recently updated.
     *
     * @return list<CustomerDocument>
     */
    public function findForAdmin(string $status = 'all'): array
    {
        $qb = $this->createQueryBuilder('d')
            ->addSelect('u')
            ->addSelect("CASE WHEN d.status = 'pending' THEN 0 ELSE 1 END AS HIDDEN queue")
            ->join('d.user', 'u')
            ->orderBy('queue', 'ASC')
            ->addOrderBy('d.updatedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC');
        if ('all' !== $status) {
            $qb->where('d.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * v15 customer_documents(): keyed by document type.
     *
     * @return array<string, CustomerDocument>
     */
    public function findForUser(User $user): array
    {
        $documents = [];
        foreach ($this->findBy(['user' => $user], ['documentType' => 'ASC']) as $document) {
            $documents[$document->getDocumentType()] = $document;
        }

        return $documents;
    }
}
