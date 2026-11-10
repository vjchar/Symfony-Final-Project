<?php

namespace App\Controller;

use App\Form\ForgotPasswordType;
use App\Form\ResetPasswordType;
use App\Service\PasswordResetManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * v15 forgot-password.php and reset-password.php.
 */
final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetManager $resets,
    ) {
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        #[Autowire('%kernel.environment%')] string $environment,
        #[Target('password_reset_request')] RateLimiterFactoryInterface $resetLimiter,
    ): Response {
        $form = $this->createForm(ForgotPasswordType::class);
        $form->handleRequest($request);

        $message = null;
        $localResetLink = null;
        $status = Response::HTTP_OK;
        if ($form->isSubmitted()) {
            if (!$resetLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $message = 'Too many reset requests from your network. Please try again in a few minutes.';
                $status = Response::HTTP_TOO_MANY_REQUESTS;
                $form = $this->createForm(ForgotPasswordType::class);
            } elseif ($form->isValid()) {
                $link = $this->resets->request($form->get('email')->getData());
                // the same answer whether or not the account exists
                $message = 'If that account exists, a password-reset link has been prepared.';
                // v15 shows the link on the page during local development, where no
                // mail goes out. Showing it to anyone else would let them reset any
                // account, so it also requires a request from this machine.
                $isLocal = IpUtils::checkIp((string) $request->getClientIp(), ['127.0.0.1', '::1']);
                $localResetLink = 'prod' !== $environment && $isLocal ? $link : null;
                $form = $this->createForm(ForgotPasswordType::class);
            } else {
                $message = $form->get('email')->getErrors()->current()->getMessage();
            }
        }

        return $this->render('security/forgot_password.html.twig', [
            'form' => $form,
            'message' => $message,
            'local_reset_link' => $localResetLink,
        ], Response::HTTP_OK === $status ? null : new Response(status: $status));
    }

    #[Route('/reset-password', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, #[MapQueryParameter] string $token = ''): Response
    {
        $reset = $this->resets->find($token);
        $form = $this->createForm(ResetPasswordType::class);

        if (null !== $reset) {
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $this->resets->complete($reset, $form->get('plainPassword')->getData());
                $this->addFlash('success', 'Password updated. You can now sign in.');

                return $this->redirectToRoute('app_login', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('security/reset_password.html.twig', [
            'form' => $form,
            'reset' => $reset,
        ]);
    }
}
