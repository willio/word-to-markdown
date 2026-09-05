# Word to Markdown

Convert legacy Microsoft Word documents into clean, portable Markdown.

Word to Markdown is designed for writers, editors, and archivists moving an
old `.doc` or `.docx` collection to the open web. It recovers useful document
metadata, keeps the authored text and basic formatting, preserves safe
hyperlinks, and records source provenance so every conversion can be reviewed.

## What it does

- Converts `.docx` documents directly.
- Converts legacy `.doc` documents through LibreOffice when it is installed.
- Detects titles, explicit bylines, publication dates, and legacy category labels.
- Preserves headings, bold, italic, lists, quotes, and external links.
- Classifies explicit links to Instagram, Threads, X/Twitter, Facebook,
  LinkedIn, YouTube, and TikTok for optional downstream enrichment.
- Rejects unsafe link schemes instead of emitting executable URLs.
- Supports dry runs, recursive directory batches, collision protection, and
  isolated failures.
- Emits ordinary Markdown with flat, inspectable front matter.

It does not guess a social profile from a person's name or a bare `@handle`.
The source document must contain the actual hyperlink for a social link to be
retained or classified.

## Install

Requirements:

- PHP 8.1 or later
- PHP DOM and ZIP extensions
- Composer for the test suite and library autoloading
- LibreOffice (`soffice`) only when converting `.doc` files

```bash
composer install
```

## Use

Preview one document without writing a file:

```bash
bin/word-to-markdown archive/article.docx --dry-run
```

Convert a document to `converted/`:

```bash
bin/word-to-markdown archive/article.docx --output=converted
```

Convert a directory recursively. Each document is isolated, so one bad file
does not stop the batch:

```bash
bin/word-to-markdown archive/ --recursive --output=converted
```

If a document has no usable byline, provide a fallback:

```bash
bin/word-to-markdown article.docx --author="Archive editor"
```

The PHP API returns an `ImportedDocument` with `links`, including each link's
visible text, safe URL, and optional social platform classification. The
Markdown writer places classified social URLs in a flat `social` metadata list.

## Deliberate limits

This is an archive migration tool, not a pixel-perfect Word renderer. Tables,
images, embedded objects, tracked changes, and hyperlink relationship targets
that are not external web/mail links are not converted. Review the dry-run
output before publishing.

## Development

```bash
composer test
```

The project originated in a private editorial archive. No private editorial
content, mailbox data, or deployment state belongs in this repository.

## License

GPL-3.0-only. See [LICENSE](LICENSE).
