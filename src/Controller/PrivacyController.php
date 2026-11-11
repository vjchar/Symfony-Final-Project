<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\AccountClosureType;
use App\Service\AccountPrivacyManager;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A customer's privacy controls: download a copy of their data, or close
 * their account (AccountPrivacyManager says what is kept).
 */
#[IsGranted('ROLE_CUSTOMER')]
final class PrivacyController extends AbstractController
{
    #[Route('/account/privacy', name: 'app_account_privacy', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user, AccountPrivacyManager $privacy, Security $security): Response
    {
        try {
            $privacy->assertClosable($user);
            $blocked = null;
        } catch (\DomainException $error) {
            $blocked = $error->getMessage();
        }

        $form = $this->createForm(AccountClosureType::class);
        $form->handleRequest($request);
        if (null === $blocked && $form->isSubmitted() && $form->isValid()) {
            try {
                $privacy->close($user);
                $security->logout(false);

                return $this->redirectToRoute('app_home', ['account' => 'closed'], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $error) {
                $blocked = $error->getMessage();
            }
        }

        return $this->render('account/privacy.html.twig', [
            'form' => $form,
            'blocked' => $blocked,
            'retention' => AccountPrivacyManager::DOCUMENT_RETENTION,
        ]);
    }

    #[Route('/account/privacy/export', name: 'app_account_privacy_export', methods: ['GET'])]
    public function export(#[CurrentUser] User $user, AccountPrivacyManager $privacy, AuditLogger $auditLogger): JsonResponse
    {
        $response = new JsonResponse($privacy->export($user));
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'vj-car-rental-my-data.json'));
        $response->headers->set('Cache-Control', 'no-store, private');
        $auditLogger->log('account_data_exported', 'user', $user->getId());

        return $response;
    }
}
