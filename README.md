# JsonLinesImporter

Product import for Thelia 3 from a JSON Lines file (`.jsonl`, one product per line), through a five-step back-office wizard: upload and analysis, data selection, parameters (mappings and options), preview, execution. A console command replays an import with the parameters the wizard exports.

Each line is a product object validated against the JSON Schema `Config/schema/product-import-1.0.json` (draft 2020-12, `$id` `urn:thelia:json-lines-importer:product-import:1.0`): product and translations, sale elements and variants, prices by currency, tax rule, categories, brand, features, images, documents, videos, accessories, and the old address of the product page.

## Compatibility

- Thelia 3.0 or later, default-twig back-office
- PHP 8.3+
- `opis/json-schema` ^2.6
- Optional: Thelia Blocks (videos as blocks), TheliaLibrary (images of the HTML fields copied to the media library)

## Installation

A module of `local/modules` is not installed by Composer: add its dependency to the shop.

```bash
composer require opis/json-schema:^2.6
php Thelia module:refresh
php Thelia module:activate JsonLinesImporter
```

The activation creates four tables: `jsonl_import`, `jsonl_import_line`, `jsonl_import_mapping`, `jsonl_import_media`. Uploaded files are kept in `local/media/jsonl-imports/`.

## Rules of a line

- `ref` is the key: an existing product is updated, a missing one is created (both can be disabled).
- Keys at the root of a node target the default locale of the shop; an `i18n.<locale>` block wins over them. A locale absent from a line is left untouched.
- A collection present replaces the existing one, `null` empties it, an absent key leaves it unchanged. Replaying a file creates no duplicate.
- The product title is always written on creation (the reference when the line has none), and on an existing product only when its box is ticked at step 2 (unticked by default).
- Variants are matched by their `ref`: a matched sale element keeps its id, a sale element missing from the file is hidden, never deleted.
- Images and documents are matched by their source, remembered by the module: a known source is not downloaded again, and only the files the module imported are removed when they leave the file.
- Videos go to the video blocks of the product group (Thelia Blocks) or to a feature chosen at step 3 (one YouTube address per product).
- `url` is the old address of the page: it becomes a 301 redirection to the current URL of the product, stored decoded, with and without its trailing slash.
- Titles of tax rules, categories, brands, features and attributes are mapped at step 3 (associate, create hidden, or ignore); the choices are memorized for the next imports.

## Back-office

Catalog menu, entry "JSONL import" (`/admin/json_lines_importer`). Access is granted on the module itself: VIEW for the screens, UPDATE for the actions. The Catalog menu itself only shows to administrators allowed to view the categories.

The analysis and the import run in successive HTTP batches driven by the page (30 seconds at most each, less when `max_execution_time` is lower or when the module configuration value `batch_seconds` asks for it). Closing the page pauses the import; it resumes from the list of imports at the next line. Only one import runs at a time.

## Console

```bash
php Thelia jsonlinesimporter:import <file.jsonl> --params=<parameters.json> [--dry-run]
```

The parameters file is the one exported at step 3 ("Export the parameters"): selected data, options, excluded lines and mappings. A title missing from the exported mappings follows the memorized mapping, else the element with the same title, else it is ignored; the command never creates a referential element.

## Traps of the core this module works around

- `RewritingUrl::preInsert()` sanitizes the URL (non ASCII characters dropped) and `postInsert()` points every other redirection of the object at the new row: redirections are written in SQL.
- `FileManager::copyUploadedFile()` and the media library move the source file: they get a temporary copy.
- `Product::setDefaultCategory()` deletes the former default link: the category links are computed and written by the module.
- Thelia overwrites the position of images, documents and accessories on insert: it is applied once the row exists.
- `product_price.from_default_currency` defaults to 1: an explicit price writes it to 0.
- The media library finds the file of an image in the session or default locale only: library images are created in the default locale.

## Assets

The back-office scripts are Stimulus controllers built by webpack into `templates/backOffice/default-twig/assets/dist/` (versioned output):

```bash
cd Resources && npm ci && npm run build
```

## Tests

```bash
# Unit suite, from the module root
composer install
php vendor/bin/phpunit --testsuite unit

# Integration suite, from a shop with a test database where the module is active
php -d auto_prepend_file=./bootstrap.php vendor/bin/phpunit -c local/modules/JsonLinesImporter/phpunit.xml.dist --testsuite integration
```

`THELIA_VENDOR_AUTOLOAD` points the integration suite at the autoloader of the shop when PHPUnit comes from the module vendor.

## Author

Samuel Diakité, OpenStudio

## License

GPL-3.0-or-later
