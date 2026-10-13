# JsonLinesImporter

Product import for Thelia 3 from a JSON Lines file (`.jsonl`, one product per line), through a five-step back-office wizard: upload and analysis, data selection, parameters (mappings and options), preview, execution. A console command replays an import with the parameters the wizard exports.

Each line is a product object validated against the JSON Schema `Config/schema/product-import-1.0.json` (draft 2020-12, `$id` `urn:thelia:json-lines-importer:product-import:1.0`): product and translations, sale elements and variants, prices by currency, tax rule, categories, brand, features, images, documents, videos, accessories, and the old address of the product page.

## Compatibility

- Thelia 3.2 or later (typed product relations, product videos), default-twig back-office
- PHP 8.3+
- `opis/json-schema` ^2.6
- Optional: TheliaLibrary (images of the HTML fields copied to the media library)

## Installation

The module is available on Packagist as `thelia/json-lines-importer-module`. Composer installs it in `vendor/thelia/modules/JsonLinesImporter` with its dependencies.

```bash
composer require thelia/json-lines-importer-module
php Thelia module:refresh
php Thelia module:activate JsonLinesImporter
```

A copy placed by hand in `local/modules/JsonLinesImporter` is not installed by Composer: add its dependency to the shop first (`composer require opis/json-schema:^2.6`).

The activation creates four tables: `jsonl_import`, `jsonl_import_line`, `jsonl_import_mapping`, `jsonl_import_media`. Uploaded files are kept in `local/media/jsonl-imports/`.

## Rules of a line

- The file is UTF-8, one JSON object per line. A line that is not valid UTF-8 (a Windows-1252 export, for instance) is reported as invalid and the analysis goes on.
- `ref` is the key: an existing product is updated, a missing one is created (both can be disabled).
- Keys at the root of a node target the default locale of the shop; an `i18n.<locale>` block wins over them. A locale absent from a line is left untouched.
- A collection present replaces the existing one, `null` empties it, an absent key leaves it unchanged. Replaying a file creates no duplicate.
- The product title is always written on creation (the reference when the line has none), and on an existing product only when its box is ticked at step 2 (unticked by default).
- A sale field (`ean_code`, `quantity`, `weight`, `newness`, `promo`, `price`, `promo_price`, `prices`) has one box at step 2, listed as soon as the root or a variant carries it. Unticked, it is left unchanged on the product and on every variant.
- Variants are matched by their `ref`: a matched sale element keeps its id, a sale element missing from the file is hidden, never deleted.
- Images and documents are matched by their source, remembered by the module: a known source is not downloaded again, and only the files the module imported are removed when they leave the file.
- Media files are downloaded from public addresses only (private, loopback and link-local addresses are refused, redirections included), read from the server only inside the directory declared at step 3, and stored with the extension of their detected type (JPEG, PNG, GIF, WebP, PDF, MP4, WebM, Ogg), never the one of their source name. An image or a document weighs 20 MB at most and is fetched in 60 seconds at most; a video file 200 MB and 10 minutes.
- Videos become the product videos of the core (`product_video`), shown in the product gallery after the images. A source is a YouTube, Vimeo or Dailymotion address (the platforms the shop enables, configuration variable `video_providers`), or a video file: a public address whose path ends with `.mp4`, `.m4v`, `.webm`, `.ogv` or `.ogg`, or a path in the directory of the videos declared at step 3. A file is kept only if its detected type is accepted by the shop (`video_upload_allowed_mime_types`, MP4, WebM and Ogg by default). Any other address is reported and ignored.
- A platform video is matched by its platform and identifier (`youtu.be/x` and `watch?v=x` are the same video), a file by its source. As for images, a known video is not fetched again, a video the module imported is removed when it leaves the file (its hosted file and the link that publishes it included), and a video added by hand is never touched. A list whose videos are all invalid leaves the current videos in place.
- Images and videos share one order on the product sheet: the videos of a line come after the images and the videos added by hand, in the order of their `position`.
- The `group` key of a video, used by the former Thelia Blocks destination, is still accepted and ignored. Videos written by the former destinations (video blocks of Thelia Blocks, value of a feature) are no longer managed by the module: delete them by hand if needed. The former video feature is no longer left out of the features of a line, so its value is removed at the next import of a line carrying `features`.
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
- Thelia overwrites the position of images, documents, videos and accessories on insert: it is applied once the row exists.
- Images and videos share one sequence of positions (`ProductMediaOrder`): deleting one closes the gap in both tables, so the videos that leave a line are removed before the positions of the others are written.
- Deleting a `ProductVideo` row removes neither its hosted file nor the link of the web cache that serves it: the module deletes both once the line is committed.
- The `accessory` table holds every relation between products (accessory, cross-selling, up-selling, types declared by the merchant) under a required `type_id`: the module writes and replaces the links of the `accessory` type only.
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
php -d auto_prepend_file=./bootstrap.php vendor/bin/phpunit -c vendor/thelia/modules/JsonLinesImporter/phpunit.xml.dist --testsuite integration
```

For a copy in `local/modules`, pass `-c local/modules/JsonLinesImporter/phpunit.xml.dist`. The CI runs both suites on a fresh Thelia 3 shop (`.github/workflows/ci.yml`).

`THELIA_VENDOR_AUTOLOAD` points the integration suite at the autoloader of the shop when PHPUnit comes from the module vendor.

The test database must be at the level of the core code (its `product_video` tables come with Thelia 3.2): a database stamped with the right version but created before a table was added to the update script of that version misses it, and the video tests fail on an unknown table. The video tests read their files from a local directory and need no network.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Author

Samuel Diakité, OpenStudio

## License

GPL-3.0-or-later
