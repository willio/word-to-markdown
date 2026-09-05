<?php

declare(strict_types=1);

namespace WordToMarkdown;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use RuntimeException;
use ZipArchive;

final class DocxDocumentParser
{
    private const MAX_XML_BYTES = 4 * 1024 * 1024;

    public function parse(
        string $path,
        ?string $fallbackAuthor = null,
        ?string $fallbackSourcePath = null,
    ): ImportedDocument
    {
        if (!is_file($path)) {
            throw new RuntimeException("Document not found [{$path}].");
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'docx') {
            throw new RuntimeException('DocxDocumentParser only accepts .docx files.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open DOCX archive [{$path}].");
        }

        try {
            $documentXml = $this->xmlEntry($zip, 'word/document.xml', true);
            $coreXml = $this->xmlEntry($zip, 'docProps/core.xml', false);
            $relationshipsXml = $this->xmlEntry($zip, 'word/_rels/document.xml.rels', false);
            $metadata = is_string($coreXml) ? $this->coreMetadata($coreXml) : [];
            $relationships = is_string($relationshipsXml) ? $this->relationships($relationshipsXml) : [];
            $paragraphs = $this->paragraphs($documentXml, $relationships);

            [$title, $titleConfidence, $titleIndex] = $this->detectTitle($metadata, $paragraphs, $path);
            [$author, $authorConfidence, $authorIndex] = $this->detectAuthor($metadata, $paragraphs, $fallbackAuthor);
            $originalCategory = $this->detectOriginalCategory($paragraphs)
                ?? $this->originalCategory($fallbackSourcePath ?? $path);
            [$date, $dateConfidence, $dateIndex] = $this->detectDate(
                $metadata,
                $paragraphs,
                $fallbackSourcePath ?? $path,
            );
            $links = [];
            foreach ($paragraphs as $paragraph) {
                foreach ($paragraph['links'] as $link) {
                    $links[] = $link;
                }
            }

            $skip = array_filter([$titleIndex, $authorIndex, $dateIndex], static fn (?int $value): bool => $value !== null);
            $body = $this->renderMarkdown($paragraphs, $skip);

            return new ImportedDocument(
                $title,
                $author,
                $date,
                $body,
                basename($path),
                $originalCategory,
                [
                    'title' => $titleConfidence,
                    'author' => $authorConfidence,
                    'date' => $dateConfidence,
                ],
                $metadata,
                $links,
            );
        } finally {
            $zip->close();
        }
    }

    private function originalCategory(string $path): ?string
    {
        $category = basename(dirname($path));

        return in_array(strtolower($category), ['', '.', 'legacy', 'import'], true)
            ? null
            : $category;
    }

    /** @param list<array{text:string,markdown:string,style:string,list:bool,quote:bool}> $paragraphs */
    private function detectOriginalCategory(array $paragraphs): ?string
    {
        foreach (array_slice($paragraphs, 0, 8) as $paragraph) {
            if (preg_match('/^(?:[^\p{L}]{0,2})?(\p{L}[\p{L}\p{M}-]*)\s+by\s+.+$/iu', $paragraph['text'], $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function xmlEntry(ZipArchive $zip, string $name, bool $required): ?string
    {
        $stat = $zip->statName($name, ZipArchive::FL_UNCHANGED);
        if (!is_array($stat)) {
            if ($required) {
                throw new RuntimeException("DOCX XML entry is missing [{$name}].");
            }
            return null;
        }

        $size = $stat['size'] ?? null;
        if (!is_int($size) || $size < 0) {
            throw new RuntimeException("DOCX XML entry size is invalid [{$name}].");
        }
        if ($size > self::MAX_XML_BYTES) {
            throw new RuntimeException(
                "DOCX XML entry [{$name}] exceeds the limit of " . self::MAX_XML_BYTES . ' bytes.',
            );
        }

        $xml = $zip->getFromName($name, 0, ZipArchive::FL_UNCHANGED);
        if (!is_string($xml) || ($required && $xml === '')) {
            throw new RuntimeException("Unable to extract DOCX XML entry [{$name}].");
        }
        return $xml;
    }

    /** @return array<string, string> */
    private function relationships(string $xml): array
    {
        $dom = $this->loadXml($xml);
        $relationships = [];
        foreach ($dom->getElementsByTagNameNS(
            'http://schemas.openxmlformats.org/package/2006/relationships',
            'Relationship',
        ) as $relationship) {
            if (!$relationship instanceof DOMElement) {
                continue;
            }

            $type = $relationship->getAttribute('Type');
            $targetMode = strtolower($relationship->getAttribute('TargetMode'));
            $target = trim($relationship->getAttribute('Target'));
            $id = trim($relationship->getAttribute('Id'));
            if ($id === '' || $target === '' || $targetMode !== 'external') {
                continue;
            }
            if (!str_ends_with($type, '/hyperlink') || !$this->isSafeLink($target)) {
                continue;
            }
            $relationships[$id] = $target;
        }
        return $relationships;
    }

    private function isSafeLink(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }

    /** @return array<string, string> */
    private function coreMetadata(string $xml): array
    {
        $dom = $this->loadXml($xml);
        $map = [
            'title' => ['http://purl.org/dc/elements/1.1/', 'title'],
            'creator' => ['http://purl.org/dc/elements/1.1/', 'creator'],
            'created' => ['http://purl.org/dc/terms/', 'created'],
            'modified' => ['http://purl.org/dc/terms/', 'modified'],
            'last_modified_by' => ['http://schemas.openxmlformats.org/package/2006/metadata/core-properties', 'lastModifiedBy'],
        ];

        $result = [];
        foreach ($map as $key => [$namespace, $local]) {
            $nodes = $dom->getElementsByTagNameNS($namespace, $local);
            if ($nodes->length > 0) {
                $value = trim((string) $nodes->item(0)?->textContent);
                if ($value !== '') {
                    $result[$key] = $value;
                }
            }
        }
        return $result;
    }

    /** @param array<string, string> $relationships @return list<array{text:string,markdown:string,style:string,list:bool,quote:bool,links:list<ImportedLink>}> */
    private function paragraphs(string $xml, array $relationships): array
    {
        $dom = $this->loadXml($xml);
        $paragraphs = [];
        foreach ($dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p') as $paragraph) {
            if (!$paragraph instanceof DOMElement) {
                continue;
            }

            [$text, $markdown, $links] = $this->paragraphContent($paragraph, $relationships);
            if ($text === '') {
                continue;
            }

            $style = '';
            $styleNodes = $paragraph->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'pStyle');
            if ($styleNodes->length > 0 && $styleNodes->item(0) instanceof DOMElement) {
                $style = strtolower((string) ($styleNodes->item(0)?->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val') ?: $styleNodes->item(0)?->getAttribute('w:val')));
            }

            $paragraphs[] = [
                'text' => $text,
                'markdown' => $markdown,
                'style' => $style,
                'list' => $paragraph->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'numPr')->length > 0,
                'quote' => str_contains($style, 'quote'),
                'links' => $links,
            ];
        }
        return $paragraphs;
    }

    /** @param array<string, string> $relationships @return array{string,string,list<ImportedLink>} */
    private function paragraphContent(DOMElement $paragraph, array $relationships): array
    {
        $plain = '';
        $markdown = '';
        $links = [];
        foreach ($paragraph->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 'r') {
                [$runPlain, $runMarkdown] = $this->runContent($child);
                $plain .= $runPlain;
                $markdown .= $runMarkdown;
                continue;
            }
            if ($child->localName !== 'hyperlink') {
                continue;
            }

            [$linkPlain, $linkMarkdown] = $this->hyperlinkContent($child);
            $plain .= $linkPlain;
            $relationshipId = $child->getAttributeNS(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'id',
            ) ?: $child->getAttribute('r:id');
            $url = $relationships[$relationshipId] ?? null;
            if ($url === null) {
                $markdown .= $linkMarkdown;
                continue;
            }
            $markdown .= '[' . $linkMarkdown . '](' . $this->markdownUrl($url) . ')';
            $links[] = new ImportedLink($this->cleanText($linkPlain), $url, $this->socialPlatform($url));
        }

        return [$this->cleanText($plain), $this->cleanText($markdown), $links];
    }

    /** @return array{string,string} */
    private function runContent(DOMElement $run): array
    {
        $text = '';
        foreach ($run->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 't') {
                $text .= $child->textContent;
            } elseif ($child->localName === 'tab') {
                $text .= "\t";
            } elseif ($child->localName === 'br') {
                $text .= "\n";
            }
        }

        $bold = $run->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'b')->length > 0;
        $italic = $run->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'i')->length > 0;
        return [$text, $this->formatRun($text, $bold, $italic)];
    }

    /** @return array{string,string} */
    private function hyperlinkContent(DOMElement $hyperlink): array
    {
        $plain = '';
        $markdown = '';
        foreach ($hyperlink->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'r') as $run) {
            if (!$run instanceof DOMElement) {
                continue;
            }
            [$runPlain, $runMarkdown] = $this->runContent($run);
            $plain .= $runPlain;
            $markdown .= $runMarkdown;
        }
        return [$plain, $markdown];
    }

    private function markdownUrl(string $url): string
    {
        return str_replace(['\\', '(', ')', ' '], ['\\\\', '\\(', '\\)', '%20'], $url);
    }

    private function socialPlatform(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        return match ($host) {
            'instagram.com' => 'instagram',
            'threads.net' => 'threads',
            'x.com', 'twitter.com' => 'x',
            'facebook.com' => 'facebook',
            'linkedin.com' => 'linkedin',
            'youtube.com', 'youtu.be' => 'youtube',
            'tiktok.com' => 'tiktok',
            default => null,
        };
    }

    private function formatRun(string $text, bool $bold, bool $italic): string
    {
        if ((!$bold && !$italic) || trim($text) === '') {
            return $text;
        }

        preg_match('/^(\s*)(.*?)(\s*)$/us', $text, $matches);
        $marker = $bold && $italic ? '***' : ($bold ? '**' : '*');
        return ($matches[1] ?? '') . $marker . ($matches[2] ?? $text) . $marker . ($matches[3] ?? '');
    }

    /** @param array<string,string> $metadata @param list<array{text:string,markdown:string,style:string,list:bool,quote:bool}> $paragraphs @return array{string,string,?int} */
    private function detectTitle(array $metadata, array $paragraphs, string $path): array
    {
        if (($metadata['title'] ?? '') !== '') {
            return [$metadata['title'], 'high', null];
        }
        foreach ($paragraphs as $index => $paragraph) {
            if (str_contains($paragraph['style'], 'title') || str_contains($paragraph['style'], 'heading1')) {
                return [$paragraph['text'], 'high', $index];
            }
        }
        if ($paragraphs !== []) {
            return [$paragraphs[0]['text'], 'medium', 0];
        }
        return [$this->titleFromFilename($path), 'low', null];
    }

    /** @param array<string,string> $metadata @param list<array{text:string,markdown:string,style:string,list:bool,quote:bool}> $paragraphs @return array{string,string,?int} */
    private function detectAuthor(array $metadata, array $paragraphs, ?string $fallback): array
    {
        foreach (array_slice($paragraphs, 0, 8, true) as $index => $paragraph) {
            if (preg_match('/^(?:(?:.{0,2})?(?:\p{L}[\p{L}\p{M}-]*\s+)?by|oleh|written by|author)\s*[:\-]?\s*(.+)$/iu', $paragraph['text'], $matches) === 1) {
                // The document's explicit byline identifies the contributor;
                // Word's creator field commonly identifies the editor/publisher.
                return [trim($matches[1]), 'high', (int) $index];
            }
        }
        if (($metadata['creator'] ?? '') !== '') {
            return [$metadata['creator'], 'medium', null];
        }
        if ($fallback !== null && trim($fallback) !== '') {
            return [trim($fallback), 'low', null];
        }
        return ['unknown', 'low', null];
    }

    /** @param array<string,string> $metadata @param list<array{text:string,markdown:string,style:string,list:bool,quote:bool}> $paragraphs @return array{string,string,?int} */
    private function detectDate(array $metadata, array $paragraphs, string $path): array
    {
        foreach ($paragraphs as $index => $paragraph) {
            if (!preg_match('/\bpublished\s+on\b/iu', $paragraph['text'])) {
                continue;
            }
            $date = $this->parseDate($paragraph['text']);
            if ($date !== null) {
                return [$date, 'high', (int) $index];
            }
        }
        foreach (array_slice($paragraphs, 0, 10, true) as $index => $paragraph) {
            $date = $this->parseDate($paragraph['text']);
            if ($date !== null) {
                return [$date, 'high', (int) $index];
            }
        }
        if (($metadata['created'] ?? '') !== '') {
            try {
                $date = new DateTimeImmutable($metadata['created']);
                if ($this->dateHasNoErrors()) {
                    return [$date->format('Y-m-d'), 'medium', null];
                }
            } catch (\Throwable) {
            }
        }
        if (preg_match('/(19|20)\d{2}[-_.](0[1-9]|1[0-2])[-_.](0[1-9]|[12]\d|3[01])/', basename($path), $matches) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', str_replace(['_', '.'], '-', $matches[0]));
            if ($date instanceof DateTimeImmutable && $this->dateHasNoErrors()) {
                return [$date->format('Y-m-d'), 'high', null];
            }
        }
        $modified = filemtime($path);
        return [date('Y-m-d', is_int($modified) ? $modified : time()), 'low', null];
    }

    private function parseDate(string $value): ?string
    {
        if (preg_match('/\b(0?[1-9]|[12]\d|3[01])\s+([A-Za-z]+)\s+((?:19|20)\d{2})\b/', $value, $matches) === 1) {
            $date = DateTimeImmutable::createFromFormat('!j F Y', $matches[0]);
            if ($date instanceof DateTimeImmutable && $this->dateHasNoErrors()) {
                return $date->format('Y-m-d');
            }
        }
        $patterns = [
            '/\b(19|20)\d{2}[-\/.](0?[1-9]|1[0-2])[-\/.](0?[1-9]|[12]\d|3[01])\b/',
            '/\b(0?[1-9]|[12]\d|3[01])[-\/.](0?[1-9]|1[0-2])[-\/.](19|20)\d{2}\b/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value, $matches) !== 1) {
                continue;
            }
            $normalized = str_replace(['/', '.'], '-', $matches[0]);
            foreach (['Y-n-j', 'j-n-Y'] as $format) {
                $date = DateTimeImmutable::createFromFormat('!' . $format, $normalized);
                if ($date instanceof DateTimeImmutable && $this->dateHasNoErrors()) {
                    return $date->format('Y-m-d');
                }
            }
        }
        try {
            $date = new DateTimeImmutable($value);
            if ($this->dateHasNoErrors() && (int) $date->format('Y') >= 1900) {
                return $date->format('Y-m-d');
            }
        } catch (\Throwable) {
        }
        return null;
    }

    private function dateHasNoErrors(): bool
    {
        $errors = DateTimeImmutable::getLastErrors();
        return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
    }

    /** @param list<array{text:string,markdown:string,style:string,list:bool,quote:bool}> $paragraphs @param list<int> $skip */
    private function renderMarkdown(array $paragraphs, array $skip): string
    {
        $lines = [];
        foreach ($paragraphs as $index => $paragraph) {
            if (in_array($index, $skip, true)) {
                continue;
            }
            $text = $paragraph['markdown'];

            if (preg_match('/heading\s*([1-6])|heading([1-6])/', $paragraph['style'], $matches) === 1) {
                $level = (int) ($matches[1] !== '' ? $matches[1] : $matches[2]);
                $lines[] = str_repeat('#', max(2, $level)) . ' ' . trim($text);
            } elseif ($paragraph['list']) {
                $lines[] = '- ' . trim($text);
            } elseif ($paragraph['quote']) {
                $lines[] = '> ' . trim($text);
            } else {
                $lines[] = $text;
            }
        }

        $body = implode("\n\n", array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== '')));
        return trim(preg_replace("/\n{3,}/", "\n\n", $body) ?? $body) . "\n";
    }

    private function cleanText(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\u{200B}", "\r"], [' ', '', ''], $text);
        $text = preg_replace('/\\\\(?=\d)/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function titleFromFilename(string $path): string
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        $name = preg_replace('/^\d{4}[-_.]\d{2}[-_.]\d{2}[-_. ]*/', '', $name) ?? $name;
        $name = str_replace(['_', '-'], ' ', $name);
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    private function loadXml(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('Unable to parse DOCX XML.');
            }
            return $dom;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
