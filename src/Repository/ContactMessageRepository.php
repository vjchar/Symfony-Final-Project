<?php

namespace App\Repository;

use App\Entity\ContactMessage;
use App\Pagination\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactMessage>
 */
class ContactMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMessage::class);
    }

    public function countNew(): int
    {
        return $this->count(['status' => 'new']);
    }

    /**
     * v15 contact_messages(): the inbox for one status, or 'all', newest first.
     *
     * @return Page<ContactMessage>
     */
    public function findForInbox(string $status, int $page = 1): Page
    {
        $qb = $this->createQueryBuilder('m')->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC');
        if ('all' !== $status) {
            $qb->where('m.status = :status')->setParameter('status', $status);
        }

        return Page::of($qb, $page);
    }
}
