<?php

namespace App\Controller\Admin;

use App\Entity\Booking;
use App\Entity\PaymentRefund;
use App\Repository\VehicleRepository;
use App\Service\Admin\RentalCalendar;
use App\Service\Admin\RentalReport;
use App\Service\Admin\SystemHealth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * v15 admin-calendar.php, admin-reports.php and health.php: read-only views
 * of the schedule, the business results and the deployment.
 */
#[IsGranted('ROLE_ADMIN')]
final class OperationsController extends AbstractController
{
    #[Route('/admin/calendar', name: 'app_admin_calendar', methods: ['GET'])]
    public function calendar(
        RentalCalendar $calendar,
        VehicleRepository $vehicles,
        #[MapQueryParameter] string $view = 'month',
        #[MapQueryParameter] string $date = '',
        #[MapQueryParameter] string $type = 'all',
        #[MapQueryParameter] string $vehicle = '',
    ): Response {
        $view = \in_array($view, ['month', 'week', 'day'], true) ? $view : 'month';
        $anchor = $this->date($date) ?? new \DateTimeImmutable('today');
        $type = 'all' === $type || isset(RentalCalendar::TYPES[$type]) ? $type : 'all';
        $selectedVehicle = ctype_digit($vehicle) ? $vehicles->find((int) $vehicle) : null;

        [$start, $end] = match ($view) {
            'day' => [$anchor, $anchor->modify('+1 day')],
            'week' => [$start = $anchor->modify('monday this week'), $start->modify('+7 days')],
            default => [
                $anchor->modify('first day of this month')->modify('monday this week'),
                $anchor->modify('last day of this month')->modify('sunday this week')->modify('+1 day'),
            ],
        };
        $events = $calendar->events($start, $end, $type, $selectedVehicle);
        $byDay = [];
        foreach ($events as $event) {
            $byDay[$event->day()][] = $event;
        }
        $step = ['month' => '1 month', 'week' => '7 days', 'day' => '1 day'][$view];
        $days = [];
        for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify('+1 day')) {
            $days[] = $cursor;
        }

        return $this->render('admin/calendar/index.html.twig', [
            'view' => $view,
            'anchor' => $anchor,
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'events' => $events,
            'events_by_day' => $byDay,
            'type' => $type,
            'types' => RentalCalendar::TYPES,
            'vehicles' => $vehicles->findBy([], ['name' => 'ASC']),
            'selected_vehicle' => $selectedVehicle,
            'previous' => $anchor->modify('-'.$step),
            'next' => $anchor->modify('+'.$step),
        ]);
    }

    #[Route('/admin/reports', name: 'app_admin_reports', methods: ['GET'])]
    public function reports(
        RentalReport $report,
        #[MapQueryParameter] string $from = '',
        #[MapQueryParameter] string $to = '',
        #[MapQueryParameter] string $export = '',
    ): Response {
        $fromDate = $this->date($from);
        $toDate = $this->date($to);
        // v15 falls back to this month so far for a missing or reversed range
        if (null === $fromDate || null === $toDate || $fromDate > $toDate) {
            $fromDate = new \DateTimeImmutable('first day of this month midnight');
            $toDate = new \DateTimeImmutable('today');
        }
        $data = $report->build($fromDate, $toDate);

        if ('csv' === $export) {
            $lines = $report->csvRows($data['rows']);
            $response = new StreamedResponse(static function () use ($lines): void {
                $output = fopen('php://output', 'wb');
                foreach ($lines as $line) {
                    fputcsv($output, $line, escape: '');
                }
                fclose($output);
            });
            $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
            $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                sprintf('rental-report-%s-to-%s.csv', $fromDate->format('Y-m-d'), $toDate->format('Y-m-d')),
            ));

            return $response;
        }

        return $this->render('admin/report/index.html.twig', $data + [
            'from' => $fromDate,
            'to' => $toDate,
            'booking_statuses' => Booking::STATUSES,
            'refund_type_labels' => PaymentRefund::TYPE_LABELS,
        ]);
    }

    #[Route('/admin/health', name: 'app_admin_health', methods: ['GET'])]
    public function health(SystemHealth $health): Response
    {
        $checks = $health->checks();

        return $this->render('admin/health/index.html.twig', [
            'checks' => $checks,
            'healthy' => SystemHealth::allPassed($checks),
        ]);
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
