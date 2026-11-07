<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores fleet photos in public/images/cars, keeping the plain file names that
 * vehicle-images.css targets with [src$="..."] selectors (asset-mapper hashing would break them).
 */
final class VehicleImageStorage
{
    public const PUBLIC_PATH = 'images/cars/';
    public const PLACEHOLDER = 'images/placeholders/vehicle-placeholder.svg';

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/images/cars')]
        private readonly string $directory,
    ) {
    }

    public function exists(?string $filename): bool
    {
        $filename = basename((string) $filename);

        return '' !== $filename && is_file($this->directory.\DIRECTORY_SEPARATOR.$filename);
    }

    /**
     * Same naming as v15 upload_file(): "<slug>-<16 hex chars>.<ext>".
     */
    public function store(UploadedFile $file, string $slug): string
    {
        $prefix = preg_replace('/[^a-z0-9-]+/i', '-', $slug);
        $filename = sprintf('%s-%s.%s', $prefix, bin2hex(random_bytes(8)), $file->guessExtension() ?? 'png');

        $file->move($this->directory, $filename);

        return $filename;
    }

    public function publicPath(?string $filename): string
    {
        return $this->exists($filename) ? self::PUBLIC_PATH.basename((string) $filename) : self::PLACEHOLDER;
    }
}
