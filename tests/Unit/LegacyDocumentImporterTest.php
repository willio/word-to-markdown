<?php

declare(strict_types=1);

namespace WordToMarkdown\Tests\Unit;

use WordToMarkdown\FrontMatter;
use WordToMarkdown\DocxDocumentParser;
use WordToMarkdown\MarkdownDocumentWriter;
use WordToMarkdown\LegacyDocConverter;
use WordToMarkdown\LegacyDocumentImporter;
use PHPUnit\Framework\TestCase;

final class LegacyDocumentImporterTest extends TestCase
{
    private string $root;
    private string $originalPath;
    private string|false $originalFixture;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/word-to-markdown-legacy-import-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/bin', 0775, true);
        $this->originalPath = (string) getenv('PATH');
        $this->originalFixture = getenv('WORD_TO_MARKDOWN_TEST_DOCX');

        $fixture = DocxFixture::minimal($this->root . '/converted-template.docx');
        putenv('WORD_TO_MARKDOWN_TEST_DOCX=' . $fixture);
        $script = '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
$outIndex = array_search('--outdir', $argv, true);
$out = $outIndex === false ? '' : ($argv[$outIndex + 1] ?? '');
$source = $argv[count($argv) - 1] ?? '';
$target = $out . DIRECTORY_SEPARATOR . pathinfo($source, PATHINFO_FILENAME) . '.docx';
exit(copy((string) getenv('WORD_TO_MARKDOWN_TEST_DOCX'), $target) ? 0 : 1);
PHP;
        file_put_contents($this->root . '/bin/soffice', $script);
        chmod($this->root . '/bin/soffice', 0775);
        putenv('PATH=' . $this->root . '/bin:' . $this->originalPath);
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
        $this->originalFixture === false
            ? putenv('WORD_TO_MARKDOWN_TEST_DOCX')
            : putenv('WORD_TO_MARKDOWN_TEST_DOCX=' . $this->originalFixture);
        $this->remove($this->root);
    }

    public function testConversionsWithTheSameFilenameUseIndependentWorkspaces(): void
    {
        mkdir($this->root . '/source-a', 0775, true);
        mkdir($this->root . '/source-b', 0775, true);
        $sourceA = $this->root . '/source-a/original.doc';
        $sourceB = $this->root . '/source-b/original.doc';
        file_put_contents($sourceA, 'legacy-a');
        file_put_contents($sourceB, 'legacy-b');
        $converter = new LegacyDocConverter($this->root . '/conversion');

        $convertedA = $converter->convert($sourceA);
        $convertedB = $converter->convert($sourceB);

        self::assertNotSame($convertedA, $convertedB);
        self::assertNotSame(dirname($convertedA), dirname($convertedB));
        self::assertFileExists($convertedA);
        self::assertFileExists($convertedB);
        self::assertSame(0700, fileperms(dirname($convertedA)) & 0777);
        self::assertSame(0700, fileperms(dirname($convertedB)) & 0777);

        $converter->cleanup($convertedA);
        $converter->cleanup($convertedB);
        self::assertDirectoryDoesNotExist(dirname($convertedA));
        self::assertDirectoryDoesNotExist(dirname($convertedB));
    }

    public function testLegacyImportPreservesOriginalDocProvenanceUntilParsingCompletes(): void
    {
        $source = $this->root . '/Original source.doc';
        file_put_contents($source, 'legacy');
        $drafts = $this->root . '/drafts';
        $converter = new LegacyDocConverter($this->root . '/conversion');
        $writer = new MarkdownDocumentWriter($drafts);
        $importer = new LegacyDocumentImporter(new DocxDocumentParser(), $writer, $converter);

        $result = $importer->import($source, null, true);

        self::assertSame('Original source.doc', $result['document']->sourceFile);
        $parsed = FrontMatter::parse($result['content']);
        self::assertSame('Original source.doc', $parsed['meta']['source_file']);
        self::assertSame([], glob($this->root . '/conversion/doc-*') ?: []);
    }

    public function testLegacyImportUsesOriginalDocMtimeForAnUndatedDocument(): void
    {
        $fixture = DocxFixture::undated($this->root . '/undated-template.docx');
        touch($fixture, strtotime('2026-08-01T00:00:00Z'));
        putenv('WORD_TO_MARKDOWN_TEST_DOCX=' . $fixture);
        $source = $this->root . '/Undated source.doc';
        file_put_contents($source, 'legacy');
        touch($source, strtotime('2019-06-15T12:00:00Z'));

        $result = $this->importer()->import($source, null, true);

        self::assertSame('2019-06-15', $result['document']->date);
        self::assertSame('low', $result['document']->confidence['date']);
    }

    public function testCleanupFailureIsSurfaced(): void
    {
        $source = $this->root . '/cleanup.doc';
        file_put_contents($source, 'legacy');
        $converter = new LegacyDocConverter($this->root . '/conversion');
        $converted = $converter->convert($source);
        $workspace = dirname($converted);
        chmod($workspace, 0500);
        $error = null;

        try {
            $converter->cleanup($converted);
        } catch (\RuntimeException $caught) {
            $error = $caught;
        } finally {
            chmod($workspace, 0700);
            $converter->cleanup($converted);
        }

        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertStringContainsString('cleanup', strtolower($error->getMessage()));
    }

    private function importer(): LegacyDocumentImporter
    {
        $drafts = $this->root . '/drafts';
        return new LegacyDocumentImporter(
            new DocxDocumentParser(),
            new MarkdownDocumentWriter($drafts),
            new LegacyDocConverter($this->root . '/conversion'),
        );
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
