<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\User;
use App\Form\CustomerDocumentType;
use App\Repository\BookingRepository;
use App\Service\Booking\BookingJourneyResolver;
use App\Service\Document\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 documents.php: upload the driver's license and government ID and
 * follow their verification.
 */
#[IsGranted('ROLE_CUSTOMER')]
final class DocumentController extends AbstractController
{
    #[Route('/account/documents', name: 'app_documents', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        BookingRepository $bookings,
        DocumentManager $manager,
        BookingJourneyResolver $journeys,
    ): Response {
        $reference = trim($request->isMethod('POST') ? $request->getPayload()->getString('booking_reference') : $request->query->getString('reference'));
        $booking = '' !== $reference ? $bookings->findOneBy(['reference' => $reference]) : null;
        if (null !== $booking && !$booking->isOwnedBy($user)) {
            $booking = null;
            $reference = '';
        }
        if (null === $booking && '' === $reference) {
            // like v15: the documents belong to the account, shown against its open booking
            foreach ($bookings->findForUser($user) as $candidate) {
                if (\in_array($candidate->getStatus(), Booking::PRE_PICKUP_STATUSES, true)) {
                    $booking = $candidate;
                    $reference = $candidate->getReference();
                    break;
                }
            }
        }

        $form = $this->createForm(CustomerDocumentType::class);
        $form->handleRequest($request);
        $errors = [];
        if ($form->isSubmitted()) {
            try {
                if (!$form->isValid()) {
                    throw new \InvalidArgumentException('Your session expired. Refresh the page and try again.');
                }
                $file = $form->get('document_file')->getData();
                $manager->submit(
                    $user,
                    (string) $form->get('document_type')->getData(),
                    (string) $form->get('document_number')->getData(),
                    (string) $form->get('expiry_date')->getData(),
                    $file instanceof UploadedFile ? $file : null,
                );
                $this->addFlash('success', 'Document uploaded securely and sent for verification.');

                return $this->redirectToRoute('app_documents', '' === $reference ? [] : ['reference' => $reference, 'uploaded' => 1], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $error) {
                $errors[] = $error->getMessage();
            }
        }

        return $this->render('document/index.html.twig', [
            'documents' => $manager->checklist($user)->documents,
            'booking' => $booking,
            'reference' => $reference,
            'journey' => null === $booking ? null : $journeys->resolve($booking),
            'form' => $form->createView(),
            'errors' => $errors,
            'payment_submitted' => $request->query->has('payment_submitted'),
            'uploaded' => $request->query->has('uploaded'),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
