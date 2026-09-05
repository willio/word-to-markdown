<?php

declare(strict_types=1);

namespace WordToMarkdown;

/**
 * A minimal front matter parser.
 *
 * Supports the subset of YAML that front matter actually needs:
 * scalar strings/numbers/booleans/null, inline lists (`tags: [a, b]`),
 * and simple block lists (`tags:` followed by `- item` lines). It
 * does not support nested maps, multi-line strings, or anchors —
 * see ADR 0005 for why a full YAML parser was deliberately avoided.
 *
 * This is the only place in the Content Engine that parses raw
 * Markdown text; everything downstream works with the resulting
 * structured data instead.
 */
final class FrontMatter
{
    /**
     * @return array{meta: array<string, mixed>, body: string}
     */
    public static function parse(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);

        if ($raw !== '---' && !str_starts_with($raw, "---\n")) {
            return ['meta' => [], 'body' => ltrim($raw, "\n")];
        }

        $lines = explode("\n", $raw);
        array_shift($lines); // drop the opening "---"

        $metaLines = [];
        $bodyLines = [];
        $closed = false;

        foreach ($lines as $index => $line) {
            if (trim($line) === '---') {
                $closed = true;
                $bodyLines = array_slice($lines, $index + 1);
                break;
            }

            $metaLines[] = $line;
        }

        if (!$closed) {
            // No closing delimiter: treat the whole thing as body.
            return ['meta' => [], 'body' => ltrim($raw, "\n")];
        }

        return [
            'meta' => self::parseLines($metaLines),
            'body' => ltrim(implode("\n", $bodyLines), "\n"),
        ];
    }

    /**
     * @param array<int, string> $lines
     * @return array<string, mixed>
     */
    private static function parseLines(array $lines): array
    {
        $meta = [];
        $pendingKey = null;
        $pendingList = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if ($pendingKey !== null && preg_match('/^-\s?(.*)$/', $trimmed, $m)) {
                $pendingList[] = self::castScalar(trim($m[1]));
                continue;
            }

            if ($pendingKey !== null) {
                // A non-list line ends the pending block list.
                $meta[$pendingKey] = $pendingList;
                $pendingKey = null;
                $pendingList = [];
            }

            if (!preg_match('/^([A-Za-z0-9_.-]+):\s?(.*)$/', $trimmed, $m)) {
                continue; // ignore lines we can't parse, rather than throwing
            }

            $key = $m[1];
            $value = trim($m[2]);

            if ($value === '') {
                $pendingKey = $key;
                $pendingList = [];
                continue;
            }

            if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $inner = trim(substr($value, 1, -1));
                $meta[$key] = $inner === ''
                    ? []
                    : array_map(
                        static fn (string $item): string|int|float|bool|null => self::castScalar(trim($item)),
                        explode(',', $inner),
                    );
                continue;
            }

            $meta[$key] = self::castScalar($value);
        }

        if ($pendingKey !== null) {
            $meta[$pendingKey] = $pendingList;
        }

        return $meta;
    }

    private static function castScalar(string $value): string|int|float|bool|null
    {
        if ($value === '') {
            return '';
        }

        if (
            str_starts_with($value, '"') && str_ends_with($value, '"')
        ) {
            $value = substr($value, 1, -1);
            return preg_replace_callback(
                '/\\\\(.)/s',
                static fn (array $match): string => match ($match[1]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"' => '"',
                    '\\' => '\\',
                    default => $match[1],
                },
                $value,
            ) ?? $value;
        }

        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null', '~' => null,
            default => is_numeric($value)
                ? (str_contains($value, '.') ? (float) $value : (int) $value)
                : $value,
        };
    }
}
