<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * The single v15 sign-in for customers and administrators (pages/auth/login.php).
 */
class LoginAuthenticator extends AbstractLoginFormAuthenticator
{
    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $email = strtolower(trim($request->getPayload()->getString('email')));
        $password = $request->getPayload()->getString('password');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL) || '' === $password) {
            throw new CustomUserMessageAuthenticationException('Enter a valid email address and password.');
        }

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($password),
            [new CsrfTokenBadge('authenticate', $request->getPayload()->getString('_csrf_token'))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        if ($user instanceof User && $user->isAdmin()) {
            $this->flash($request, 'success', 'Administrator access granted.');

            return new RedirectResponse($this->urlGenerator->generate('app_admin'), Response::HTTP_SEE_OTHER);
        }

        // staff have no customer pages, so a customer return_to would only end in a 403
        if ($user instanceof User && $user->isStaff()) {
            $this->flash($request, 'success', 'Welcome back.');

            return new RedirectResponse($this->urlGenerator->generate('app_staff_maintenance'), Response::HTTP_SEE_OTHER);
        }

        $this->flash($request, 'success', 'Welcome back.');

        return new RedirectResponse(
            ReturnTo::sanitize($request->getPayload()->get('return_to')) ?? $this->urlGenerator->generate('app_account'),
            Response::HTTP_SEE_OTHER,
        );
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->loginUrl(ReturnTo::sanitize($request->getPayload()->get('return_to'))));
    }

    /**
     * v15 require_auth(): "Please sign in to continue." and come back here afterwards.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $this->flash($request, 'warning', 'Please sign in to continue.');

        return new RedirectResponse($this->loginUrl($request->getRequestUri()));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    private function loginUrl(?string $returnTo): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE, array_filter(['return_to' => $returnTo]));
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
