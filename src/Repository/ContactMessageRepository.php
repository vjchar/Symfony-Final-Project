<?php

namespace App\Repository;

use App\Entity\ContactMessage;
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
     * @return list<ContactMessage>
     */
    public function findForInbox(string $status): array
    {
        return $this->findBy('all' === $status ? [] : ['status' => $status], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
