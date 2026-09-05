<?php

declare(strict_types=1);

namespace WordToMarkdown;

use DateTimeImmutable;
use RuntimeException;

final class MarkdownDocumentWriter
{
    public function __construct(private readonly string $outputDirectory)
    {
    }

    /** @return array{path:string,slug:string,content:string} */
    public function write(ImportedDocument $document, bool $dryRun = false): array
    {
        $slug = $this->slug($document->title);
        if ($slug === '') {
            $slug = 'imported-' . substr(hash('sha256', $document->sourceFile), 0, 12);
        }

        $directory = rtrim($this->outputDirectory, '/\\');
        $path = $directory . DIRECTORY_SEPARATOR . $slug . '.md';
        $content = MarkdownDocument::render($this->metadata($document), $document->body);

        if (!$dryRun) {
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException("Unable to create output directory [{$directory}].");
            }
            if (is_file($path)) {
                throw new RuntimeException("Output already exists [{$path}].");
            }
            $temporary = tempnam($directory, '.import-');
            if ($temporary === false || file_put_contents($temporary, $content) === false || !rename($temporary, $path)) {
                if (is_string($temporary)) {
                    @unlink($temporary);
                }
                throw new RuntimeException("Unable to write converted Markdown [{$path}].");
            }
            chmod($path, 0640);
        }

        return ['path' => $path, 'slug' => $slug, 'content' => $content];
    }

    /** @return array<string, mixed> */
    private function metadata(ImportedDocument $document): array
    {
        $metadata = [
            'title' => trim($document->title),
            'author' => $document->author,
            'source_file' => $document->sourceFile,
            'source_date' => $document->date,
            'converted_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            'confidence_title' => $document->confidence['title'] ?? 'low',
            'confidence_author' => $document->confidence['author'] ?? 'low',
            'confidence_date' => $document->confidence['date'] ?? 'low',
        ];

        if ($document->originalCategory !== null) {
            $metadata['original_category'] = $document->originalCategory;
            $metadata['original_published'] = $document->date;
        }

        $social = array_values(array_unique(array_map(
            static fn (ImportedLink $link): string => $link->url,
            array_filter($document->links, static fn (ImportedLink $link): bool => $link->socialPlatform !== null),
        )));
        if ($social !== []) {
            $metadata['social'] = $social;
        }

        return $metadata;
    }

    private function slug(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}
