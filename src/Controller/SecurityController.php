<?php

namespace App\Controller;

use App\Security\ReturnTo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, #[MapQueryParameter('return_to')] ?string $returnTo = null): Response
    {
        $returnTo = ReturnTo::sanitize($returnTo);

        if ($this->getUser()) {
            return $this->isGranted('ROLE_ADMIN')
                ? $this->redirectToRoute('app_admin')
                : $this->redirect($returnTo ?? $this->generateUrl('app_account'));
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error_message' => $this->v15Message($authenticationUtils->getLastAuthenticationError()),
            'return_to' => $returnTo,
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    /**
     * The wording v15 login.php shows for each kind of failed sign-in.
     */
    private function v15Message(?AuthenticationException $error): ?string
    {
        return match (true) {
            null === $error => null,
            $error instanceof TooManyLoginAttemptsAuthenticationException => 'Too many unsuccessful attempts. Try again in 15 minutes.',
            $error instanceof CustomUserMessageAuthenticationException, $error instanceof CustomUserMessageAccountStatusException => $error->getMessageKey(),
            default => 'The email or password is incorrect.',
        };
    }
}
