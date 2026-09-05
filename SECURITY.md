# Security policy

Please do not report suspected vulnerabilities in public issues. Email the
maintainer privately with a reproducible description and affected version.

The converter treats Word files as untrusted input. It limits XML expansion,
disables network access during XML parsing, allows only safe hyperlink schemes,
uses isolated owner-only workspaces for LibreOffice conversion, and refuses to
overwrite an existing output file.

Do not send real private documents when reporting a problem. Build a minimal
synthetic fixture instead.
