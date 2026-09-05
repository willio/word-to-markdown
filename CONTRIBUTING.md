# Contributing

Thank you for helping make document migration safer and more useful.

Before opening a pull request:

1. Run `composer install` and `composer test`.
2. Add or update synthetic fixtures for parser behavior.
3. Run a dry run against representative documents and inspect the Markdown.
4. Do not commit real editorial documents, personal data, credentials, or
   generated conversion output.
5. Explain any change to metadata precedence or safety limits.

Keep pull requests focused. Parser behavior that changes output should include
an explicit test and a short README or documentation update.
