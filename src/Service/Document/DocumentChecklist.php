<?php

namespace App\Service\Document;

use App\Entity\CustomerDocument;

/**
 * v15 booking_document_journey_state(): where each required document stands
 * (missing, pending, approved, rejected or expired), by its label.
 */
final readonly class DocumentChecklist
{
    /**
     * @param array<string, CustomerDocument> $documents keyed by document type
     * @param array<string, list<string>>     $labels    status => labels of the documents in it
     */
    private function __construct(
        public array $documents,
        private array $labels,
    ) {
    }

    /**
     * @param array<string, CustomerDocument> $documents keyed by document type
     */
    public static function fromDocuments(array $documents): self
    {
        $labels = ['missing' => [], 'pending' => [], 'approved' => [], 'rejected' => [], 'expired' => []];
        foreach (CustomerDocument::TYPES as $type => [$label]) {
            $status = isset($documents[$type]) ? $documents[$type]->getEffectiveStatus() : 'missing';
            $labels[\array_key_exists($status, $labels) ? $status : 'missing'][] = 'drivers_license' === $type ? 'Driver’s License' : 'Government ID';
        }

        return new self($documents, $labels);
    }

    /**
     * @return list<string>
     */
    public function labels(string $status): array
    {
        return $this->labels[$status] ?? [];
    }

    public function allSubmitted(): bool
    {
        return [] === $this->labels['missing'];
    }

    public function allApproved(): bool
    {
        return \count($this->labels['approved']) === \count(CustomerDocument::TYPES);
    }

    public function needsReplacement(): bool
    {
        return [] !== $this->labels['rejected'] || [] !== $this->labels['expired'];
    }
}
