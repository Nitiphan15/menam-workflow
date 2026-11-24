<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** po:clean-temp-pdfs only removes old PDF/PNG temp files in the two PO temp folders. */
class CleanPoTempPdfsTest extends TestCase
{
    private string $storage;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = $this->app->storagePath();
        // Never touch the real storage folder.
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'po-clean-' . uniqid();
        File::ensureDirectoryExists($this->storage . '/app/tmp/po-pdf-merge');
        File::ensureDirectoryExists($this->storage . '/app/tmp/po-pdf');
        File::ensureDirectoryExists($this->storage . '/app/tmp/other');
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function makeTempFile(string $relative, int $ageHours): string
    {
        $path = $this->storage . '/app/' . $relative;
        file_put_contents($path, '%PDF-1.4 test');
        touch($path, time() - $ageHours * 3600);

        return $path;
    }

    public function test_removes_only_old_pdf_and_png_in_po_temp_folders(): void
    {
        $oldMerge = $this->makeTempFile('tmp/po-pdf-merge/old.pdf', 30);
        $oldSignature = $this->makeTempFile('tmp/po-pdf/old-signature.png', 30);
        $fresh = $this->makeTempFile('tmp/po-pdf-merge/fresh.pdf', 2);
        $oldText = $this->makeTempFile('tmp/po-pdf/notes.txt', 30);
        $otherFolder = $this->makeTempFile('tmp/other/old.pdf', 30);

        $this->artisan('po:clean-temp-pdfs')->assertSuccessful();

        $this->assertFileDoesNotExist($oldMerge);
        $this->assertFileDoesNotExist($oldSignature);
        $this->assertFileExists($fresh);
        $this->assertFileExists($oldText);
        $this->assertFileExists($otherFolder);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $old = $this->makeTempFile('tmp/po-pdf-merge/old.pdf', 30);

        $this->artisan('po:clean-temp-pdfs', ['--dry-run' => true])->assertSuccessful();

        $this->assertFileExists($old);
    }
}
