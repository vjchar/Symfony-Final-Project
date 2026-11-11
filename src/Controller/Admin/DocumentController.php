<?php

namespace App\Controller\Admin;

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
 * v15 admin-documents.php: approve or reject the customers' rental documents.
 */
#[IsGranted('ROLE_ADMIN')]
final class DocumentController extends AbstractController
{
    public const FILTERS = ['all', CustomerDocument::STATUS_PENDING, CustomerDocument::STATUS_APPROVED, CustomerDocument::STATUS_REJECTED];

    #[Route('/admin/documents', name: 'app_admin_documents', methods: ['GET'])]
    public function index(CustomerDocumentRepository $documents, #[MapQueryParameter] string $status = 'all', #[MapQueryParameter] int $page = 1): Response
    {
        $filter = \in_array($status, self::FILTERS, true) ? $status : 'all';

        return $this->render('admin/document/index.html.twig', [
            'documents' => $documents->findForAdmin($filter, $page),
            'filter' => $filter,
            'filters' => self::FILTERS,
        ]);
    }

    #[Route('/admin/documents/{id}/review', name: 'app_admin_document_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function review(int $id, Request $request, #[CurrentUser] User $admin, CustomerDocumentRepository $documents, DocumentManager $manager): Response
    {
        $payload = $request->getPayload();
        $document = $documents->find($id);
        if (!$this->isCsrfTokenValid('review-document-'.$id, $payload->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');
        } elseif (null === $document) {
            $this->addFlash('danger', 'Document not found.');
        } else {
            try {
                $manager->review($document, $payload->getString('status'), $payload->getString('admin_notes'), $admin);
                $this->addFlash('success', 'Document review saved.');
            } catch (\InvalidArgumentException|\DomainException $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_admin_documents', ['status' => $request->query->getString('status', 'all')], Response::HTTP_SEE_OTHER);
    }
}
