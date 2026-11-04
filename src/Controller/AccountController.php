<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 account.php: the customer dashboard. Administrators get 403, like v15 require_customer().
 */
final class AccountController extends AbstractController
{
    #[Route('/account', name: 'app_account', methods: ['GET'])]
    #[IsGranted('ROLE_CUSTOMER')]
    public function index(): Response
    {
        return $this->render('account/index.html.twig');
    }
}
