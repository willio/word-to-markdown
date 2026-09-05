<?php

declare(strict_types=1);

namespace WordToMarkdown\Tests\Unit;

use WordToMarkdown\DirectoryDocumentImporter;
use WordToMarkdown\DocxDocumentParser;
use WordToMarkdown\MarkdownDocumentWriter;
use WordToMarkdown\LegacyDocConverter;
use WordToMarkdown\LegacyDocumentImporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DirectoryDocumentImporterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/word-to-markdown-directory-import-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/incoming/nested', 0775, true);
        DocxFixture::minimal($this->root . '/incoming/direct.docx', 'Direct document');
        DocxFixture::minimal($this->root . '/incoming/nested/nested.docx', 'Nested document');
        file_put_contents($this->root . '/incoming/ignored.txt', 'ignored');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDryRunCountsPreviewsSeparatelyAndHonorsRecursiveDiscovery(): void
    {
        $result = $this->importer()->import($this->root . '/incoming', null, true, true);

        self::assertSame(0, $result['imported']);
        self::assertSame(2, $result['previewed']);
        self::assertSame(0, $result['failed']);
        self::assertSame(['previewed', 'previewed'], array_column($result['results'], 'status'));
        self::assertDirectoryDoesNotExist($this->root . '/drafts');
    }

    public function testNonRecursiveImportPersistsOnlyDirectDocuments(): void
    {
        $result = $this->importer()->import($this->root . '/incoming');

        self::assertSame(1, $result['imported']);
        self::assertSame(0, $result['previewed']);
        self::assertSame(0, $result['failed']);
        self::assertFileExists($this->root . '/drafts/direct-document.md');
        self::assertFileDoesNotExist($this->root . '/drafts/nested-document.md');
    }

    public function testMixedBatchContinuesAfterOneDocumentFails(): void
    {
        file_put_contents($this->root . '/incoming/broken.docx', 'not a zip archive');

        $result = $this->importer()->import($this->root . '/incoming');

        self::assertSame(1, $result['imported']);
        self::assertSame(0, $result['previewed']);
        self::assertSame(1, $result['failed']);
        self::assertSame(['failed', 'imported'], array_column($result['results'], 'status'));
        self::assertSame('broken.docx', basename($result['results'][0]['source']));
        self::assertNotNull($result['results'][0]['error']);
        self::assertFileExists($this->root . '/drafts/direct-document.md');
    }

    public function testUnreadableNonRecursiveDirectoryFailsExplicitly(): void
    {
        $directory = $this->root . '/unreadable';
        mkdir($directory, 0700, true);
        chmod($directory, 0000);
        clearstatcache(true, $directory);

        if (is_readable($directory)) {
            chmod($directory, 0700);
            self::markTestSkipped('Current process can bypass directory permissions.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Import directory is not readable');
            $this->importer()->import($directory);
        } finally {
            chmod($directory, 0700);
        }
    }

    private function importer(): DirectoryDocumentImporter
    {
        $drafts = $this->root . '/drafts';
        return new DirectoryDocumentImporter(new LegacyDocumentImporter(
            new DocxDocumentParser(),
            new MarkdownDocumentWriter($drafts),
            new LegacyDocConverter($this->root . '/conversion'),
        ));
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $target = $path . '/' . $entry;
            is_dir($target) ? $this->remove($target) : unlink($target);
        }
        rmdir($path);
    }
}
