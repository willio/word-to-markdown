<?php

declare(strict_types=1);

namespace WordToMarkdown\Tests\Unit;

use RuntimeException;
use ZipArchive;

final class DocxFixture
{
    public static function rich(string $path, string $url = 'https://instagram.com/hannykusumawati'): string
    {
        return self::create(
            $path,
            <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <w:body>
    <w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t xml:space="preserve">Section </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>heading</w:t></w:r></w:p>
    <w:p>
      <w:r><w:t xml:space="preserve">This is </w:t></w:r>
      <w:r><w:rPr><w:b/></w:rPr><w:t>bold</w:t></w:r>
      <w:r><w:t xml:space="preserve"> and </w:t></w:r>
      <w:r><w:rPr><w:i/></w:rPr><w:t>italic</w:t></w:r>
      <w:r><w:t>.</w:t></w:r>
    </w:p>
    <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t>List item</w:t></w:r></w:p>
    <w:p><w:pPr><w:pStyle w:val="Quote"/></w:pPr><w:r><w:rPr><w:i/></w:rPr><w:t>Quoted line</w:t></w:r></w:p>
    <w:p>
      <w:r><w:t xml:space="preserve">Before </w:t></w:r>
      <w:hyperlink r:id="rId1"><w:r><w:t>linked text</w:t></w:r></w:hyperlink>
      <w:r><w:t xml:space="preserve"> after.</w:t></w:r>
    </w:p>
            </w:body>
</w:document>
XML,
            self::core('Real title', 'Real author', '2024-02-29T10:30:00Z'),
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="'
            . htmlspecialchars($url, ENT_XML1)
            . '" TargetMode="External"/></Relationships>',
        );
    }

    public static function minimal(
        string $path,
        string $title = 'Real title',
        string $author = 'Real author',
    ): string
    {
        return self::create(
            $path,
            <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body><w:p><w:r><w:t>Imported body.</w:t></w:r></w:p></w:body>
</w:document>
XML,
            self::core($title, $author, '2024-02-29T10:30:00Z'),
        );
    }

    public static function invalidDate(string $path): string
    {
        return self::create(
            $path,
            <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p><w:r><w:t>31/02/2024</w:t></w:r></w:p>
    <w:p><w:r><w:t>Imported body.</w:t></w:r></w:p>
  </w:body>
</w:document>
XML,
            self::core('Invalid date example', 'Real author', null),
        );
    }

    public static function byline(string $path, string $byline, string $creator, string $prefix = ''): string
    {
        return self::create(
            $path,
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p><w:r><w:t>' . htmlspecialchars($prefix, ENT_XML1) . 'LegacySection By ' . htmlspecialchars($byline, ENT_XML1) . '</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Imported body.</w:t></w:r></w:p></w:body></w:document>',
            self::core('Byline example', $creator, '2024-02-29T10:30:00Z'),
        );
    }

    public static function undated(string $path, string $title = 'Undated document'): string
    {
        return self::create(
            $path,
            <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body><w:p><w:r><w:t>Imported body.</w:t></w:r></w:p></w:body>
</w:document>
XML,
            self::core($title, 'Real author', null),
        );
    }

    public static function claimEntrySize(string $path, string $entry, int $size): void
    {
        $archive = file_get_contents($path);
        if (!is_string($archive)) {
            throw new RuntimeException("Unable to read DOCX fixture [{$path}].");
        }

        $offset = 0;
        while (($header = strpos($archive, "PK\x01\x02", $offset)) !== false) {
            $nameLength = unpack('vlength', substr($archive, $header + 28, 2));
            $length = (int) ($nameLength['length'] ?? 0);
            $name = substr($archive, $header + 46, $length);
            if ($name === $entry) {
                $archive = substr_replace($archive, pack('V', $size), $header + 24, 4);
                if (file_put_contents($path, $archive) === false) {
                    throw new RuntimeException("Unable to update DOCX fixture [{$path}].");
                }
                return;
            }
            $offset = $header + 46 + $length;
        }

        throw new RuntimeException("DOCX fixture entry not found [{$entry}].");
    }

    private static function create(string $path, string $documentXml, string $coreXml, ?string $relationshipsXml = null): string
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create DOCX fixture directory [{$directory}].");
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create DOCX fixture [{$path}].");
        }

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
</Types>
XML);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('docProps/core.xml', $coreXml);
        if ($relationshipsXml !== null) {
            $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
        }
        $zip->close();

        return $path;
    }

    private static function core(string $title, string $author, ?string $created): string
    {
        $createdXml = $created === null ? '' : '<dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>';

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>{$title}</dc:title>
  <dc:creator>{$author}</dc:creator>
  {$createdXml}
</cp:coreProperties>
XML;
    }
}
