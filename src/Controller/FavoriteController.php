<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Vehicle;
use App\Repository\FavoriteRepository;
use App\Security\ReturnTo;
use App\Service\FavoriteManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 favorites.php and the heart button on vehicle cards and details.
 */
#[IsGranted('ROLE_CUSTOMER')]
final class FavoriteController extends AbstractController
{
    #[Route('/account/favorites', name: 'app_favorites', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, FavoriteRepository $favorites): Response
    {
        return $this->render('favorite/index.html.twig', [
            'vehicles' => $favorites->findActiveVehiclesFor($user),
        ]);
    }

    #[Route('/favorites/{slug}', name: 'app_favorite_toggle', methods: ['POST'])]
    public function toggle(
        Request $request,
        #[CurrentUser] User $user,
        #[MapEntity(mapping: ['slug' => 'slug'])] Vehicle $vehicle,
        FavoriteManager $manager,
    ): Response {
        if (!$this->isCsrfTokenValid('favorite', $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', 'Your session expired. Refresh the page and try again.');
        } elseif (!$vehicle->isActive()) {
            $this->addFlash('danger', 'This vehicle is no longer available.');
        } else {
            $saved = $manager->toggle($user, $vehicle);
            $this->addFlash('success', $saved ? 'Vehicle saved to your favorites.' : 'Vehicle removed from your favorites.');
        }

        $back = ReturnTo::sanitize($request->getPayload()->getString('return_to'));

        return null !== $back
            ? $this->redirect($back, Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('app_favorites', [], Response::HTTP_SEE_OTHER);
    }
}
