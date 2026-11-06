<?php

namespace App\Controller\Admin;

use App\Entity\Promo;
use App\Form\PromoType;
use App\Repository\PromoRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-promos.php: promotion codes beside a create/edit form. Only unused
 * codes can be deleted; used ones are deactivated to keep their history.
 */
#[IsGranted('ROLE_ADMIN')]
final class PromoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/admin/promos', name: 'app_admin_promos', methods: ['GET', 'POST'])]
    public function index(Request $request, PromoRepository $promoRepository, #[MapQueryParameter] int $edit = 0): Response
    {
        $editing = $edit > 0 ? $promoRepository->find($edit) : null;
        $promo = $editing ?? new Promo();

        $form = $this->createForm(PromoType::class, $promo, [
            'action' => $this->generateUrl('app_admin_promos', array_filter(['edit' => $editing?->getId()])),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($promo);
            $this->entityManager->flush();
            $this->auditLogger->log('promotion_saved', 'promo', $promo->getId(), ['code' => $promo->getCode(), 'active' => $promo->isActive()]);
            $this->addFlash('success', 'Promotion saved.');

            return $this->redirectToRoute('app_admin_promos', [], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && null !== $editing) {
            // the rejected values stay in the form; the table and heading show the saved ones
            $view = $form->createView();
            $this->entityManager->refresh($editing);
        }

        return $this->render('admin/promo/index.html.twig', [
            'promos' => $promoRepository->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']),
            'editing' => $editing,
            'form' => $view ?? $form->createView(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/admin/promos/{id}/delete', name: 'app_admin_promos_delete', methods: ['POST'])]
    public function delete(Request $request, Promo $promo): Response
    {
        if (!$this->isCsrfTokenValid('delete-promo-'.$promo->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');
        } elseif (!$promo->isDeletable()) {
            $this->addFlash('danger', 'A promotion that has already been used cannot be deleted. Deactivate it instead.');
        } else {
            $id = $promo->getId();
            $code = $promo->getCode();
            $this->entityManager->remove($promo);
            $this->entityManager->flush();
            $this->auditLogger->log('promotion_deleted', 'promo', $id, ['code' => $code]);
            $this->addFlash('success', 'Promotion '.$code.' was deleted.');
        }

        return $this->redirectToRoute('app_admin_promos', [], Response::HTTP_SEE_OTHER);
    }
}
