<?php

declare(strict_types=1);

namespace WordToMarkdown;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use UnexpectedValueException;

final class DirectoryDocumentImporter
{
    public function __construct(private readonly LegacyDocumentImporter $documents)
    {
    }

    /**
     * @return array{imported:int,previewed:int,failed:int,results:list<array{source:string,status:string,path:?string,error:?string}>}
     */
    public function import(
        string $directory,
        ?string $author = null,
        bool $dryRun = false,
        bool $recursive = false,
    ): array {
        if (!is_dir($directory)) {
            throw new RuntimeException("Import directory not found [{$directory}].");
        }
        if (!is_readable($directory)) {
            throw new RuntimeException("Import directory is not readable [{$directory}].");
        }

        $files = $recursive
            ? $this->recursiveFiles($directory)
            : $this->directFiles($directory);

        $results = [];
        $imported = 0;
        $previewed = 0;
        $failed = 0;

        foreach ($files as $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($extension, ['doc', 'docx'], true)) {
                continue;
            }

            try {
                $result = $this->documents->import($path, $author, $dryRun);
                $results[] = [
                    'source' => $path,
                    'status' => $dryRun ? 'previewed' : 'imported',
                    'path' => $result['path'],
                    'error' => null,
                ];
                $dryRun ? $previewed++ : $imported++;
            } catch (\Throwable $error) {
                $results[] = [
                    'source' => $path,
                    'status' => 'failed',
                    'path' => null,
                    'error' => $error->getMessage(),
                ];
                $failed++;
            }
        }

        return [
            'imported' => $imported,
            'previewed' => $previewed,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /** @return list<string> */
    private function directFiles(string $directory): array
    {
        $items = @scandir($directory);
        if (!is_array($items)) {
            throw new RuntimeException("Unable to read import directory [{$directory}].");
        }

        $files = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $item;
            if (is_file($path)) {
                $files[] = $path;
            }
        }
        sort($files);
        return $files;
    }

    /** @return list<string> */
    private function recursiveFiles(string $directory): array
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
        } catch (UnexpectedValueException $error) {
            throw new RuntimeException("Unable to read import directory [{$directory}].", 0, $error);
        }

        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }
}
