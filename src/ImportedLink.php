<?php

declare(strict_types=1);

namespace WordToMarkdown;

final class ImportedLink
{
    public function __construct(
        public readonly string $text,
        public readonly string $url,
        public readonly ?string $socialPlatform = null,
    ) {
    }
}
