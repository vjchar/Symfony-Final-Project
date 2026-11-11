<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The audit trail AuditLogger writes for every account, booking, payment and
 * fleet change, searchable by action, record and who made the change.
 */
#[IsGranted('ROLE_ADMIN')]
final class AuditController extends AbstractController
{
    #[Route('/admin/audit', name: 'app_admin_audit', methods: ['GET'])]
    public function index(
        AuditLogRepository $logs,
        #[MapQueryParameter] string $action = '',
        #[MapQueryParameter] string $type = '',
        #[MapQueryParameter] string $record = '',
        #[MapQueryParameter] string $actor = '',
        #[MapQueryParameter] string $role = '',
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $types = $logs->findEntityTypes();
        $type = \in_array($type, $types, true) ? $type : '';
        $recordId = ctype_digit($record) ? (int) $record : null;
        $role = \in_array($role, User::ROLES, true) ? $role : '';

        return $this->render('admin/audit/index.html.twig', [
            'logs' => $logs->search($action, $type, $recordId, $actor, $page, $role),
            'types' => $types,
            'filters' => ['action' => trim($action), 'type' => $type, 'record' => null === $recordId ? '' : (string) $recordId, 'actor' => trim($actor), 'role' => $role],
        ]);
    }
}
