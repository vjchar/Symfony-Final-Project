<?php

namespace App\Controller\Staff;

use App\Controller\Admin\DocumentController as AdminDocumentController;
use App\Entity\CustomerDocument;
use App\Entity\User;
use App\Repository\CustomerDocumentRepository;
use App\Service\Document\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff check the customers' driver's licenses and IDs before a rental.
 */
#[IsGranted('ROLE_STAFF')]
#[Route('/staff/documents')]
final class DocumentController extends AbstractController
{
    #[Route('', name: 'app_staff_documents', methods: ['GET'])]
    public function index(CustomerDocumentRepository $documents, #[MapQueryParameter] string $status = CustomerDocument::STATUS_PENDING, #[MapQueryParameter] int $page = 1): Response
    {
        $filter = \in_array($status, AdminDocumentController::FILTERS, true) ? $status : CustomerDocument::STATUS_PENDING;

        return $this->render('staff/document/index.html.twig', [
            'documents' => $documents->findForAdmin($filter, $page),
            'filter' => $filter,
            'filters' => AdminDocumentController::FILTERS,
        ]);
    }

    #[Route('/{id}/review', name: 'app_staff_document_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function review(int $id, Request $request, #[CurrentUser] User $staff, CustomerDocumentRepository $documents, DocumentManager $manager): Response
    {
        $payload = $request->getPayload();
        $document = $documents->find($id);
        if (!$this->isCsrfTokenValid('review-document-'.$id, $payload->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');
        } elseif (null === $document) {
            $this->addFlash('danger', 'Document not found.');
        } else {
            try {
                $manager->review($document, $payload->getString('status'), $payload->getString('notes'), $staff);
                $this->addFlash('success', 'Document review saved.');
            } catch (\InvalidArgumentException|\DomainException $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_staff_documents', ['status' => $request->query->getString('status', CustomerDocument::STATUS_PENDING)], Response::HTTP_SEE_OTHER);
    }
}
