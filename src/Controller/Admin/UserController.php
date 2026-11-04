<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserAccessManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-users.php: search accounts and change their role and access status.
 */
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    #[Route('/admin/users', name: 'app_admin_users', methods: ['GET'])]
    public function index(UserRepository $userRepository, #[MapQueryParameter] string $q = ''): Response
    {
        return $this->render('admin/user/index.html.twig', [
            'users' => $userRepository->search($q),
            'query' => trim($q),
        ]);
    }

    #[Route('/admin/users', name: 'app_admin_users_update', methods: ['POST'])]
    public function update(Request $request, UserAccessManager $accessManager, #[CurrentUser] User $admin): Response
    {
        $payload = $request->getPayload();
        $userId = $payload->getInt('user_id');

        if (!$this->isCsrfTokenValid('user-access-'.$userId, $payload->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');

            return $this->redirectToRoute('app_admin_users', [], Response::HTTP_SEE_OTHER);
        }

        try {
            $user = $accessManager->update($admin, $userId, $payload->getString('role'), $payload->getString('status'));
            $this->addFlash('success', 'Account access updated for '.$user->getName().'.');
        } catch (\DomainException $error) {
            $this->addFlash('danger', $error->getMessage());
        }

        return $this->redirectToRoute('app_admin_users', [], Response::HTTP_SEE_OTHER);
    }
}
