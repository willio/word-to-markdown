<?php

declare(strict_types=1);

namespace WordToMarkdown;

use RuntimeException;

final class LegacyDocumentImporter
{
    public function __construct(
        private readonly DocxDocumentParser $docx,
        private readonly MarkdownDocumentWriter $writer,
        private readonly LegacyDocConverter $legacy,
    ) {
    }

    /** @return array{path:string,slug:string,content:string,document:ImportedDocument} */
    public function import(string $path, ?string $author = null, bool $dryRun = false): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['doc', 'docx'], true)) {
            throw new RuntimeException("Unsupported document type [{$extension}]. Expected .docx or .doc.");
        }

        $converted = null;
        try {
            $source = $path;
            if ($extension === 'doc') {
                $converted = $this->legacy->convert($path);
                $source = $converted;
            }

            $document = $this->docx->parse(
                $source,
                $author,
                $extension === 'doc' ? $path : null,
            );
            if ($extension === 'doc') {
                $document = new ImportedDocument(
                    $document->title,
                    $document->author,
                    $document->date,
                    $document->body,
                    basename($path),
                    $document->originalCategory,
                    $document->confidence,
                    $document->metadata,
                    $document->links,
                );
            }
            $written = $this->writer->write($document, $dryRun);
            return $written + ['document' => $document];
        } finally {
            if (is_string($converted)) {
                $this->legacy->cleanup($converted);
            }
        }
    }
}
