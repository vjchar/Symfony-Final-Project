<?php

namespace App\Tests\Security;

use App\Security\ReturnTo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReturnToTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function sameSitePaths(): iterable
    {
        yield 'page' => ['/vehicles/porsche-911'];
        yield 'query string' => ['/booking?vehicle=porsche-911&draft=3'];
        yield 'encoded tab stays encoded' => ['/search?q=%09'];
    }

    #[DataProvider('sameSitePaths')]
    public function testKeepsSameSitePaths(string $path): void
    {
        self::assertSame($path, ReturnTo::sanitize($path));
    }

    /** @return iterable<string, array{mixed}> */
    public static function unsafeValues(): iterable
    {
        yield 'empty' => [''];
        yield 'not a string' => [['/account']];
        yield 'absolute URL' => ['https://evil.example/'];
        yield 'relative path' => ['account'];
        yield 'protocol-relative' => ['//evil.example'];
        yield 'backslash' => ['/\\evil.example'];
        // browsers drop tabs and newlines, turning these into //evil.example
        yield 'tab' => ["/\t/evil.example"];
        yield 'newline' => ["/\n/evil.example"];
        yield 'carriage return' => ["/\r/evil.example"];
        yield 'null byte' => ["/\0/evil.example"];
        yield 'delete' => ["/\x7F/evil.example"];
    }

    #[DataProvider('unsafeValues')]
    public function testRejectsAnythingThatCouldLeaveTheSite(mixed $value): void
    {
        self::assertNull(ReturnTo::sanitize($value));
    }
}
