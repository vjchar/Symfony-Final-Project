<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Customer uploads that must never be public (v15 storage/): rental
 * documents, payment proofs and review photos. Files live outside public/
 * and are only served through SecureFileController.
 */
final class PrivateFileStorage
{
    public const DOCUMENTS = 'documents';
    public const PAYMENT_PROOFS = 'payment-proofs';
    public const REVIEWS = 'reviews';

    // v15 upload_file() allow-lists: detected MIME type => saved extension
    public const DOCUMENT_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    public const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const AREAS = [self::DOCUMENTS, self::PAYMENT_PROOFS, self::REVIEWS];

    public function __construct(
        #[Autowire('%app.private_storage_dir%')]
        private readonly string $directory,
    ) {
    }

    /**
     * v15 upload_file(): checks the real content type and size, then saves
     * the file as "<prefix>-<16 hex chars>.<ext>".
     *
     * @param array<string, string> $allowedTypes
     *
     * @throws \InvalidArgumentException with the v15 message when the file is refused
     */
    public function store(UploadedFile $file, string $area, string $prefix, array $allowedTypes, int $maxBytes): string
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('The upload could not be completed.');
        }
        $size = (int) $file->getSize();
        if ($size < 1 || $size > $maxBytes) {
            throw new \InvalidArgumentException('The uploaded file is larger than allowed.');
        }
        $extension = $allowedTypes[(string) $file->getMimeType()] ?? null;
        if (null === $extension) {
            throw new \InvalidArgumentException('The uploaded file type is not allowed.');
        }

        $filename = sprintf('%s-%s.%s', preg_replace('/[^a-z0-9-]+/i', '-', $prefix), bin2hex(random_bytes(8)), $extension);
        $file->move($this->areaDirectory($area), $filename);

        return $filename;
    }

    /**
     * Absolute path of a stored file, or null when the name is unsafe or the file is gone.
     */
    public function path(string $area, string $filename): ?string
    {
        if ('' === $filename || basename($filename) !== $filename) {
            return null;
        }
        $path = $this->areaDirectory($area).\DIRECTORY_SEPARATOR.$filename;

        return is_file($path) ? $path : null;
    }

    public function delete(string $area, string $filename): void
    {
        $path = $this->path($area, $filename);
        if (null !== $path) {
            @unlink($path);
        }
    }

    private function areaDirectory(string $area): string
    {
        if (!\in_array($area, self::AREAS, true)) {
            throw new \LogicException(sprintf('Unknown private storage area "%s".', $area));
        }

        return $this->directory.\DIRECTORY_SEPARATOR.$area;
    }
}
