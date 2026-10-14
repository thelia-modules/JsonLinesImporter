# Changelog

All notable changes to this module are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] 2026-10-13

First public version. Requires Thelia 3.2 and PHP 8.3.

### Added
- Product import from a JSON Lines file (one product per line), each line validated against the JSON Schema `Config/schema/product-import-1.0.json` (draft 2020-12).
- Five-step back-office wizard on the default-twig theme (Catalog menu, "JSONL import"): upload and analysis, data selection, parameters (mappings and options), preview, execution. Access follows the VIEW and UPDATE rights granted on the module.
- Products matched by `ref`: an existing product is updated, a missing one is created, and replaying a file creates no duplicate. Root keys target the default locale of the shop, an `i18n` block wins over them.
- Sale elements and variants matched by their `ref`, prices by currency, tax rule, categories, brand, features and attribute combinations.
- Titles of tax rules, categories, brands, features and attributes mapped at step 3 (associate, create hidden, or ignore), memorized for the next imports.
- Images and documents fetched from public addresses or from a directory of `local/import/` declared at step 3, stored with the extension of their detected type, with size and time limits.
- Videos written as product videos of the core: YouTube, Vimeo and Dailymotion addresses, or video files.
- Accessories written with their relation type, leaving the other product relations alone.
- HTML of the descriptions cleaned of what could run in a browser, unless the file is trusted at step 3.
- Former address of the product page written as a 301 redirection, never on an address a page of the shop answers.
- Analysis and import run in resumable HTTP batches, one import at a time.
- Console command `jsonlinesimporter:import` replaying an import with the parameters exported by the wizard, with a `--dry-run` option.

[1.0.0]: https://github.com/thelia-modules/JsonLinesImporter/releases/tag/1.0.0
