<?php

declare(strict_types=1);

namespace WordToMarkdown;

final class MarkdownDocument
{
    /** @param array<string, mixed> $metadata */
    public static function render(array $metadata, string $body): string
    {
        $lines = ['---'];
        foreach ($metadata as $key => $value) {
            if ($value === null || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $lines[] = $key . ': [' . implode(', ', array_map(self::scalar(...), $value)) . ']';
                continue;
            }
            $lines[] = $key . ': ' . self::scalar($value);
        }
        $lines[] = '---';
        $lines[] = '';
        $lines[] = rtrim($body);
        return implode("\n", $lines) . "\n";
    }

    private static function scalar(mixed $value): string
    {
        $value = (string) $value;
        if ($value !== '' && preg_match('/^[a-zA-Z0-9_.@\/:?-]+$/', $value)) {
            return $value;
        }
        if (str_contains($value, '"') && !str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value) . '"';
    }
}
