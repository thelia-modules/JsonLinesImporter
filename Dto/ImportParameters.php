<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

use JsonLinesImporter\Enum\VideoDestination;

/**
 * The choices made in the wizard (steps 2 to 4), stored as JSON on the import and exported for the console.
 */
final readonly class ImportParameters
{
    /**
     * @param list<string>|null $selectedKeys    null = not chosen yet (every key but the title)
     * @param list<string>|null $selectedLocales null = every locale known to the shop
     * @param list<int>         $excludedLines
     */
    public function __construct(
        public ?array $selectedKeys = null,
        public ?array $selectedLocales = null,
        public bool $createProducts = true,
        public bool $updateProducts = true,
        public bool $defaultVisible = false,
        public ?int $templateId = null,
        public ?int $fallbackCategoryId = null,
        public ?string $imagesDirectory = null,
        public ?string $documentsDirectory = null,
        public VideoDestination $videoDestination = VideoDestination::BLOCKS,
        public ?int $videoFeatureId = null,
        public bool $copyHtmlImages = false,
        public array $excludedLines = [],
        /** last wizard step reached, 1 to 5 */
        public int $step = 1,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            selectedKeys: isset($data['selected_keys']) && \is_array($data['selected_keys']) ? array_values(array_map('strval', $data['selected_keys'])) : null,
            selectedLocales: isset($data['selected_locales']) && \is_array($data['selected_locales']) ? array_values(array_map('strval', $data['selected_locales'])) : null,
            createProducts: (bool) ($data['create_products'] ?? true),
            updateProducts: (bool) ($data['update_products'] ?? true),
            defaultVisible: (bool) ($data['default_visible'] ?? false),
            templateId: self::intOrNull($data['template_id'] ?? null),
            fallbackCategoryId: self::intOrNull($data['fallback_category_id'] ?? null),
            imagesDirectory: self::stringOrNull($data['images_directory'] ?? null),
            documentsDirectory: self::stringOrNull($data['documents_directory'] ?? null),
            videoDestination: VideoDestination::tryFrom((string) ($data['video_destination'] ?? '')) ?? VideoDestination::BLOCKS,
            videoFeatureId: self::intOrNull($data['video_feature_id'] ?? null),
            copyHtmlImages: (bool) ($data['copy_html_images'] ?? false),
            excludedLines: array_values(array_map('intval', (array) ($data['excluded_lines'] ?? []))),
            step: max(1, min(5, (int) ($data['step'] ?? 1))),
        );
    }

    public function toArray(): array
    {
        return [
            'selected_keys' => $this->selectedKeys,
            'selected_locales' => $this->selectedLocales,
            'create_products' => $this->createProducts,
            'update_products' => $this->updateProducts,
            'default_visible' => $this->defaultVisible,
            'template_id' => $this->templateId,
            'fallback_category_id' => $this->fallbackCategoryId,
            'images_directory' => $this->imagesDirectory,
            'documents_directory' => $this->documentsDirectory,
            'video_destination' => $this->videoDestination->value,
            'video_feature_id' => $this->videoFeatureId,
            'copy_html_images' => $this->copyHtmlImages,
            'excluded_lines' => $this->excludedLines,
            'step' => $this->step,
        ];
    }

    public function with(array $changes): self
    {
        return self::fromArray(array_merge($this->toArray(), $changes));
    }

    public function isKeySelected(string $key): bool
    {
        if (null === $this->selectedKeys) {
            return 'title' !== $key;
        }

        return \in_array($key, $this->selectedKeys, true);
    }

    public function isLocaleSelected(string $locale): bool
    {
        return null === $this->selectedLocales || \in_array($locale, $this->selectedLocales, true);
    }

    public function isLineExcluded(int $lineNumber): bool
    {
        return \in_array($lineNumber, $this->excludedLines, true);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return null === $value || '' === $value ? null : (int) $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $value = null === $value ? null : trim((string) $value);

        return null === $value || '' === $value ? null : $value;
    }
}
