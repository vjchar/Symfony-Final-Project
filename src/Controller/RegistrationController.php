<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\LoginAuthenticator;
use App\Security\ReturnTo;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * v15 register.php (customer accounts) and setup.php (the first administrator).
 */
final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Security $security,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $returnTo = ReturnTo::sanitize($request->query->get('return_to') ?? $request->getPayload()->get('return_to'));

        if ($this->getUser()) {
            return $this->redirect($returnTo ?? $this->generateUrl('app_account'));
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->createAccount($user, $form);
            $this->addFlash('success', 'Your account is ready.');
            $this->security->login($user, LoginAuthenticator::class, 'main');

            return $this->redirect($returnTo ?? $this->generateUrl('app_account'), Response::HTTP_SEE_OTHER);
        }

        return $this->render('registration/register.html.twig', [
            'form' => $form,
            'return_to' => $returnTo,
        ]);
    }

    #[Route('/setup', name: 'app_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request, UserRepository $userRepository): Response
    {
        if ($userRepository->countActiveAdmins() > 0) {
            return $this->redirectToRoute($this->isGranted('ROLE_ADMIN') ? 'app_admin' : 'app_login');
        }

        $admin = (new User())->setRole(User::ROLE_ADMIN);
        $form = $this->createForm(RegistrationFormType::class, $admin, ['accept_terms' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->createAccount($admin, $form);
            $this->addFlash('success', 'Administrator account created.');
            $this->security->login($admin, LoginAuthenticator::class, 'main');

            return $this->redirectToRoute('app_admin', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('registration/setup.html.twig', [
            'form' => $form,
        ]);
    }

    private function createAccount(User $user, FormInterface $form): void
    {
        /** @var string $plainPassword */
        $plainPassword = $form->get('plainPassword')->getData();
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->auditLogger->log('register', 'user', $user->getId(), ['role' => $user->getRole()]);
    }
}
