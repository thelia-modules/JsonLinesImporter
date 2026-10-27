-- ---------------------------------------------------------------------
-- 1.1.0: the file each source gave
--
-- Thelia 3.3 stores the file of an image per language (product_image_i18n.file):
-- the module remembers the file of each source, so that a language whose source
-- did not change is not fetched again. The rows already there take the file of
-- the default language (the core copied the former file into every language),
-- or the file of the document. Replaying the script changes nothing.
-- ---------------------------------------------------------------------

SET @jsonl_media_file := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'jsonl_import_media' AND `COLUMN_NAME` = 'file');
SET @statement := IF(@jsonl_media_file, 'DO 0', 'ALTER TABLE `jsonl_import_media` ADD `file` VARCHAR(255) NULL AFTER `source_hash`');
PREPARE jsonl_media_statement FROM @statement;
EXECUTE jsonl_media_statement;
DEALLOCATE PREPARE jsonl_media_statement;

UPDATE `jsonl_import_media` `media`
    INNER JOIN `product_image_i18n` `translation` ON `translation`.`id` = `media`.`object_id`
    INNER JOIN `lang` ON `lang`.`locale` = `translation`.`locale` AND `lang`.`by_default` = 1
SET `media`.`file` = `translation`.`file`
WHERE `media`.`media_type` = 'product_image' AND `media`.`file` IS NULL AND CHAR_LENGTH(`translation`.`file`) > 0;

UPDATE `jsonl_import_media` `media`
    INNER JOIN `product_document` `document` ON `document`.`id` = `media`.`object_id`
SET `media`.`file` = `document`.`file`
WHERE `media`.`media_type` = 'product_document' AND `media`.`file` IS NULL AND CHAR_LENGTH(`document`.`file`) > 0;
