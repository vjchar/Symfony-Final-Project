<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\AdminUserType;
use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use App\Service\AdminUserManager;
use App\Service\UserAccessManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-users.php: search accounts and change their role and access status,
 * plus creating accounts of any role, editing details, setting a new password
 * and deleting accounts (AdminUserManager).
 */
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    #[Route('/admin/users/new', name: 'app_admin_users_new', methods: ['GET', 'POST'])]
    public function new(Request $request, AdminUserManager $manager): Response
    {
        $user = new User();
        $form = $this->createForm(AdminUserType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager->create($user, (string) $form->get('plainPassword')->getData());
            $this->addFlash('success', 'Account created for '.$user->getName().'.');

            return $this->redirectToRoute('app_admin_users', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/form.html.twig', [
            'form' => $form,
            'user' => null,
        ]);
    }

    #[Route('/admin/users/{id}/edit', name: 'app_admin_users_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user, AdminUserManager $manager): Response
    {
        $form = $this->createForm(AdminUserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager->update($user);
            $this->addFlash('success', 'Account details updated for '.$user->getName().'.');

            return $this->redirectToRoute('app_admin_users', [], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted()) {
            // the rejected values are already on the entity; when it is the signed-in
            // admin, the session would keep them, so put the stored ones back
            $manager->discardChanges($user);
        }

        return $this->render('admin/user/form.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    #[Route('/admin/users/{id}/password', name: 'app_admin_users_password', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function password(Request $request, User $user, AdminUserManager $manager): Response
    {
        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager->resetPassword($user, (string) $form->get('plainPassword')->getData());
            $this->addFlash('success', 'Password reset for '.$user->getName().'.');

            return $this->redirectToRoute('app_admin_users', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/password.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    /**
     * GET asks "are you sure?" (or explains why the account must stay); only
     * the POST from that page deletes.
     */
    #[Route('/admin/users/{id}/delete', name: 'app_admin_users_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(Request $request, User $user, AdminUserManager $manager, #[CurrentUser] User $admin): Response
    {
        if (!$request->isMethod('POST')) {
            try {
                $manager->assertDeletable($admin, $user);
                $blocked = null;
            } catch (\DomainException $error) {
                $blocked = $error->getMessage();
            }

            return $this->render('admin/user/delete.html.twig', [
                'user' => $user,
                'blocked' => $blocked,
            ]);
        }

        if (!$this->isCsrfTokenValid('user-delete-'.$user->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');

            return $this->redirectToRoute('app_admin_users_delete', ['id' => $user->getId()], Response::HTTP_SEE_OTHER);
        }

        try {
            $name = $user->getName();
            $manager->delete($admin, $user);
            $this->addFlash('success', 'Account deleted for '.$name.'.');
        } catch (\DomainException $error) {
            $this->addFlash('danger', $error->getMessage());
        }

        return $this->redirectToRoute('app_admin_users', [], Response::HTTP_SEE_OTHER);
    }

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
