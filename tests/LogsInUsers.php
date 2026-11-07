<?php

namespace App\Tests;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

trait LogsInUsers
{
    private const PASSWORD = 'Rental2026';
    private const ADMIN_EMAIL = 'admin@test.vj';

    private function createUser(string $email, string $role = User::ROLE_CUSTOMER, string $status = User::STATUS_ACTIVE, string $name = ''): User
    {
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]) ?? (new User())->setEmail($email);
        $user->setName('' !== $name ? $name : 'Test '.ucfirst($role))
            ->setRole($role)
            ->setStatus($status)
            ->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function loginAsAdmin(KernelBrowser $client): User
    {
        $admin = $this->createUser(self::ADMIN_EMAIL, User::ROLE_ADMIN, name: 'Vina Admin');
        $client->loginUser($admin);

        return $admin;
    }
}
