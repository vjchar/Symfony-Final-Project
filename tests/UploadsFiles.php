<?php

namespace App\Tests;

use App\Entity\CustomerDocument;
use App\Entity\Favorite;
use App\Entity\Notification;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Browser-like uploads for the payment, document and review forms, and
 * cleanup of the customer records they create.
 */
trait UploadsFiles
{
    // a 1×1 PNG, so the real content type is detected as image/png
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private function png(string $name = 'proof.png'): UploadedFile
    {
        return $this->upload(base64_decode(self::PNG), $name, 'image/png');
    }

    private function upload(string $contents, string $name, string $clientType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vj-upload-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, $clientType, null, true);
    }

    private function clearCustomerRecords(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ([Notification::class, CustomerDocument::class, Favorite::class] as $class) {
            $em->createQuery('DELETE FROM '.$class)->execute();
        }
    }
}
