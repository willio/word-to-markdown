<?php

declare(strict_types=1);

namespace WordToMarkdown\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordToMarkdown\FrontMatter;
use WordToMarkdown\ImportedDocument;
use WordToMarkdown\ImportedLink;
use WordToMarkdown\MarkdownDocumentWriter;

final class MarkdownDocumentWriterTest extends TestCase
{
    private string $root;
    private MarkdownDocumentWriter $writer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/word-to-markdown-writer-' . bin2hex(random_bytes(6));
        $this->writer = new MarkdownDocumentWriter($this->root . '/converted');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testWriterEmitsFlatMetadataAndClassifiedSocialLinks(): void
    {
        $result = $this->writer->write(new ImportedDocument(
            'Real title',
            'Real author',
            '2024-02-29',
            'Before [Hanny](https://instagram.com/hannykusumawati).',
            'original.docx',
            'LegacySection',
            ['title' => 'high', 'author' => 'high', 'date' => 'high'],
            [],
            [new ImportedLink('Hanny', 'https://instagram.com/hannykusumawati', 'instagram')],
        ));

        self::assertSame($this->root . '/converted/real-title.md', $result['path']);
        $parsed = FrontMatter::parse((string) file_get_contents($result['path']));
        self::assertSame(['https://instagram.com/hannykusumawati'], $parsed['meta']['social']);
        self::assertSame('LegacySection', $parsed['meta']['original_category']);
        self::assertStringContainsString('[Hanny](https://instagram.com/hannykusumawati)', $parsed['body']);
    }

    public function testDryRunDoesNotWriteAndExistingOutputIsProtected(): void
    {
        $document = new ImportedDocument('Dry run', 'Author', '2024-01-01', 'Body', 'source.docx');
        $preview = $this->writer->write($document, true);
        self::assertFileDoesNotExist($preview['path']);

        $this->writer->write($document);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $this->writer->write($document);
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
