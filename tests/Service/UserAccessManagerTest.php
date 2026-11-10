<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\UserAccessManager;
use App\Tests\LogsInUsers;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserAccessManagerTest extends KernelTestCase
{
    use LogsInUsers;

    private UserAccessManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->manager = static::getContainer()->get(UserAccessManager::class);

        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ($em->getRepository(User::class)->findBy(['role' => User::ROLE_ADMIN]) as $admin) {
            $em->remove($admin);
        }
        $em->flush();
    }

    public function testLastActiveAdministratorMustRemain(): void
    {
        $onlyAdmin = $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN);
        $actingAdmin = $this->createUser('acting-admin@test.vj', User::ROLE_ADMIN, User::STATUS_INACTIVE);

        $this->expectExceptionObject(new \DomainException('At least one active administrator must remain.'));
        $this->manager->update($actingAdmin, $onlyAdmin->getId(), User::ROLE_CUSTOMER, User::STATUS_ACTIVE);
    }

    public function testRejectsUnknownRoleOrStatus(): void
    {
        $admin = $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN);

        $this->expectExceptionObject(new \DomainException('Choose a valid role and account status.'));
        $this->manager->update($admin, $admin->getId(), 'owner', User::STATUS_ACTIVE);
    }

    public function testPromotesCustomerToAdmin(): void
    {
        $admin = $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN);
        $customer = $this->createUser('juan@test.vj');

        $updated = $this->manager->update($admin, $customer->getId(), User::ROLE_ADMIN, User::STATUS_ACTIVE);

        self::assertTrue($updated->isAdmin());
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $updated->getRoles());

        $this->manager->update($admin, $customer->getId(), User::ROLE_CUSTOMER, User::STATUS_ACTIVE);
    }
}
