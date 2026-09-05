<?php

declare(strict_types=1);

namespace WordToMarkdown;

final class ImportedDocument
{
    /**
     * @param array<string, string> $confidence
     * @param list<ImportedLink> $links
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $title,
        public readonly string $author,
        public readonly string $date,
        public readonly string $body,
        public readonly string $sourceFile,
        public readonly ?string $originalCategory = null,
        public readonly array $confidence = [],
        public readonly array $metadata = [],
        public readonly array $links = [],
    ) {
    }
}
