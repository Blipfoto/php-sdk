<?php

declare(strict_types=1);

namespace Blipfoto\Tests;

use Blipfoto\Api\File;
use Blipfoto\Exceptions\FileException;
use PHPUnit\Framework\TestCase;

final class FileTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = __DIR__ . '/Fixtures';
    }

    public function testConstructAcceptsAValidJpegOfSufficientSize(): void
    {
        $file = new File($this->fixtures . '/valid.jpg');
        $this->assertSame('valid.jpg', $file->name());
    }

    /**
     * KNOWN BUG (pre-existing, not introduced by this test suite):
     * When the path doesn't exist, realpath() returns false, and
     * File::verify() does `@getimagesize($full_path)` on that false value.
     * On PHP versions that harden getimagesize() to reject an empty path
     * string, this raises an uncaught \ValueError instead of the documented
     * FileException - the `@` operator only suppresses warnings/notices, not
     * thrown Errors. Worth fixing during modernization (e.g. check
     * realpath() explicitly and throw FileException before calling
     * getimagesize() at all).
     */
    public function testConstructThrowsWhenPathDoesNotExist(): void
    {
        $this->expectException(\ValueError::class);
        new File($this->fixtures . '/does-not-exist.jpg');
    }

    public function testConstructThrowsFileExceptionCode240WhenNotAJpeg(): void
    {
        // This fixture is a PNG saved with a .jpg extension.
        $this->expectException(FileException::class);
        $this->expectExceptionCode(240);
        new File($this->fixtures . '/not-a-jpg.jpg');
    }

    public function testConstructThrowsFileExceptionCode241WhenTooSmall(): void
    {
        // 200x200, below the 600x600 minimum.
        $this->expectException(FileException::class);
        $this->expectExceptionCode(241);
        new File($this->fixtures . '/too-small.jpg');
    }

    public function testNameReturnsBasenameIncludingExtension(): void
    {
        $file = new File($this->fixtures . '/valid.jpg');
        $this->assertSame('valid.jpg', $file->name());
    }

    public function testPathReturnsRealpathOfTheFile(): void
    {
        $file = new File($this->fixtures . '/valid.jpg');
        $this->assertSame(realpath($this->fixtures . '/valid.jpg'), $file->path());
    }

    public function testContentsReturnsRawFileBytes(): void
    {
        $file = new File($this->fixtures . '/valid.jpg');
        $this->assertSame(
            file_get_contents($this->fixtures . '/valid.jpg'),
            $file->contents()
        );
    }
}
