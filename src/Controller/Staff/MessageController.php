<?php

namespace App\Controller\Staff;

use App\Controller\Admin\MessageController as AdminMessageController;
use App\Entity\ContactMessage;
use App\Entity\User;
use App\Repository\ContactMessageRepository;
use App\Service\ContactReplyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff answer the contact form enquiries and move them between queues.
 */
#[IsGranted('ROLE_STAFF')]
#[Route('/staff/messages')]
final class MessageController extends AbstractController
{
    #[Route('', name: 'app_staff_messages', methods: ['GET'])]
    public function index(ContactMessageRepository $messages, #[MapQueryParameter] string $status = 'new', #[MapQueryParameter] int $page = 1): Response
    {
        $filter = \in_array($status, AdminMessageController::FILTERS, true) ? $status : 'new';

        return $this->render('staff/message/index.html.twig', [
            'messages' => $messages->findForInbox($filter, $page),
            'filter' => $filter,
            'filters' => AdminMessageController::FILTERS,
            'statuses' => ContactMessage::STATUSES,
        ]);
    }

    #[Route('/{id}/reply', name: 'app_staff_message_reply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reply(int $id, Request $request, ContactMessageRepository $messages, ContactReplyManager $replies, #[CurrentUser] User $staff, #[MapQueryParameter] string $status = 'all'): Response
    {
        $message = $messages->find($id);
        $this->act($request, 'contact-reply-'.$id, $message, function (ContactMessage $message, string $reply) use ($replies, $staff): string {
            $replies->reply($message, $reply, $staff);

            return 'Reply sent to '.$message->getEmail().'.';
        }, 'reply');

        return $this->redirectToRoute('app_staff_messages', ['status' => $status], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/status', name: 'app_staff_message_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateStatus(int $id, Request $request, ContactMessageRepository $messages, ContactReplyManager $replies, #[CurrentUser] User $staff, #[MapQueryParameter] string $status = 'all'): Response
    {
        $message = $messages->find($id);
        $this->act($request, 'contact-message-'.$id, $message, function (ContactMessage $message, string $newStatus) use ($replies, $staff): string {
            $replies->updateStatus($message, $newStatus, $staff);

            return 'Enquiry status updated.';
        }, 'status');

        return $this->redirectToRoute('app_staff_messages', ['status' => $status], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param callable(ContactMessage, string): string $action gets the posted field and returns the success message
     */
    private function act(Request $request, string $tokenId, ?ContactMessage $message, callable $action, string $field): void
    {
        $payload = $request->getPayload();
        if (!$this->isCsrfTokenValid($tokenId, $payload->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');
        } elseif (null === $message) {
            $this->addFlash('danger', 'Contact message not found.');
        } else {
            try {
                $this->addFlash('success', $action($message, $payload->getString($field)));
            } catch (\InvalidArgumentException|\DomainException $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }
    }
}
