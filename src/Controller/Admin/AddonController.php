<?php

namespace App\Controller\Admin;

use App\Entity\Addon;
use App\Form\AddonType;
use App\Repository\AddonRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-addons.php: add-on list beside a create/edit form.
 */
#[IsGranted('ROLE_ADMIN')]
final class AddonController extends AbstractController
{
    #[Route('/admin/addons', name: 'app_admin_addons', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        AddonRepository $addonRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
        #[MapQueryParameter] int $edit = 0,
    ): Response {
        $editing = $edit > 0 ? $addonRepository->find($edit) : null;
        $addon = $editing ?? new Addon();

        $form = $this->createForm(AddonType::class, $addon, [
            'action' => $this->generateUrl('app_admin_addons', array_filter(['edit' => $editing?->getId()])),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($addon);
            $entityManager->flush();
            $auditLogger->log('addon_saved', 'addon', $addon->getId(), ['key' => $addon->getAddonKey(), 'active' => $addon->isActive()]);
            $this->addFlash('success', 'Rental add-on saved.');

            return $this->redirectToRoute('app_admin_addons', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/addon/index.html.twig', [
            'addons' => $addonRepository->findBy([], ['id' => 'ASC']),
            'editing' => $editing,
            'form' => $form,
        ]);
    }
}
