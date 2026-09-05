<?php

declare(strict_types=1);

namespace WordToMarkdown;

use RuntimeException;

final class LegacyDocConverter
{
    public function __construct(private readonly string $temporaryPath)
    {
    }

    public function convert(string $source): string
    {
        if (!is_file($source)) {
            throw new RuntimeException("Legacy document not found [{$source}].");
        }

        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if ($extension !== 'doc') {
            throw new RuntimeException("Unsupported legacy document extension [{$extension}].");
        }

        $binary = $this->findBinary(['soffice', 'libreoffice']);
        if ($binary === null) {
            throw new RuntimeException('Legacy .doc conversion requires LibreOffice (soffice) to be installed and available on PATH.');
        }

        if (!is_dir($this->temporaryPath) && !mkdir($this->temporaryPath, 0700, true) && !is_dir($this->temporaryPath)) {
            throw new RuntimeException("Unable to create temporary import directory [{$this->temporaryPath}].");
        }

        $workspace = rtrim($this->temporaryPath, '/\\') . DIRECTORY_SEPARATOR . 'doc-' . bin2hex(random_bytes(6));
        if (!mkdir($workspace, 0700, true) && !is_dir($workspace)) {
            throw new RuntimeException("Unable to create conversion workspace [{$workspace}].");
        }

        try {
            $command = sprintf(
                '%s --headless --convert-to docx --outdir %s %s 2>&1',
                escapeshellarg($binary),
                escapeshellarg($workspace),
                escapeshellarg($source),
            );

            exec($command, $output, $code);
            $converted = $workspace . DIRECTORY_SEPARATOR . pathinfo($source, PATHINFO_FILENAME) . '.docx';

            if ($code !== 0 || !is_file($converted)) {
                $message = trim(implode("\n", $output));
                throw new RuntimeException('LibreOffice failed to convert the legacy document.' . ($message !== '' ? " {$message}" : ''));
            }

            return $converted;
        } catch (\Throwable $error) {
            try {
                $this->removeDirectory($workspace);
            } catch (\Throwable $cleanupError) {
                throw new RuntimeException(
                    $error->getMessage() . ' Cleanup also failed: ' . $cleanupError->getMessage(),
                    0,
                    $error,
                );
            }
            throw $error;
        }
    }

    public function cleanup(string $converted): void
    {
        $workspace = dirname($converted);
        $temporaryPath = rtrim($this->temporaryPath, '/\\');
        if (dirname($workspace) !== $temporaryPath || !str_starts_with(basename($workspace), 'doc-')) {
            return;
        }

        $this->removeDirectory($workspace);
    }

    /** @param list<string> $candidates */
    private function findBinary(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
            if ($path !== '') {
                return $path;
            }
        }

        return null;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = @scandir($path);
        if (!is_array($items)) {
            throw new RuntimeException("Unable to inspect import cleanup directory [{$path}].");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($target)) {
                $this->removeDirectory($target);
            } elseif (!@unlink($target)) {
                throw new RuntimeException("Unable to cleanup import file [{$target}].");
            }
        }

        if (!@rmdir($path)) {
            throw new RuntimeException("Unable to cleanup import directory [{$path}].");
        }
    }
}
