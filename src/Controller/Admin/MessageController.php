<?php

namespace App\Controller\Admin;

use App\Entity\ContactMessage;
use App\Repository\ContactMessageRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-messages.php: the contact enquiry inbox, filtered by status.
 */
#[IsGranted('ROLE_ADMIN')]
final class MessageController extends AbstractController
{
    public const FILTERS = ['all', ...ContactMessage::STATUSES];

    #[Route('/admin/messages', name: 'app_admin_messages', methods: ['GET'])]
    public function index(ContactMessageRepository $messages, #[MapQueryParameter] string $status = 'new'): Response
    {
        // v15 falls back to the new queue for an unknown filter
        $filter = \in_array($status, self::FILTERS, true) ? $status : 'new';

        return $this->render('admin/message/index.html.twig', [
            'messages' => $messages->findForInbox($filter),
            'filter' => $filter,
            'filters' => self::FILTERS,
            'statuses' => ContactMessage::STATUSES,
        ]);
    }

    #[Route('/admin/messages', name: 'app_admin_messages_update', methods: ['POST'])]
    public function update(
        Request $request,
        ContactMessageRepository $messages,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
        #[MapQueryParameter] string $status = 'all',
    ): Response {
        $payload = $request->getPayload();
        $messageId = $payload->getInt('message_id');
        // v15 returns to the queue the form was sent from
        $back = $this->redirectToRoute('app_admin_messages', ['status' => $status], Response::HTTP_SEE_OTHER);

        if (!$this->isCsrfTokenValid('contact-message-'.$messageId, $payload->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');

            return $back;
        }

        $message = $messages->find($messageId);
        if (null === $message) {
            $this->addFlash('danger', 'Contact message not found.');

            return $back;
        }

        try {
            $newStatus = $payload->getString('status');
            $message->setStatus($newStatus);
            $entityManager->flush();
            $auditLogger->log('contact_message_updated', 'contact_message', $messageId, ['status' => $newStatus]);
            $this->addFlash('success', 'Enquiry status updated.');
        } catch (\DomainException $error) {
            $this->addFlash('danger', $error->getMessage());
        }

        return $back;
    }
}
