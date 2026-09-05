# Word to Markdown development notes

Word to Markdown is a small PHP library and CLI for migrating `.docx` and
legacy `.doc` archives into reviewable Markdown.

## Commands

```bash
composer install
composer test
bin/word-to-markdown path/to/archive.docx --dry-run
```

## Architecture

- `src/DocxDocumentParser.php` reads WordprocessingML safely from a DOCX ZIP.
- `src/LegacyDocConverter.php` isolates optional LibreOffice conversion.
- `src/LegacyDocumentImporter.php` coordinates `.doc`/`.docx` handling.
- `src/MarkdownDocumentWriter.php` writes collision-safe Markdown files.
- `src/ImportedDocument.php` and `src/ImportedLink.php` are the public result
  objects.
- `src/DirectoryDocumentImporter.php` provides isolated batch processing.

Markdown is an output format, not a database. Keep the parser independent of
any application framework. Never add real editorial documents to fixtures.

## Safety boundaries

- Permit only `http`, `https`, and `mailto` hyperlink targets.
- Do not infer social URLs from names or handles.
- Keep the 4 MiB per-XML-entry expansion limit.
- Keep `.doc` conversion workspaces owner-only and clean them up on success or
  failure.
- Preserve source provenance in output metadata.
