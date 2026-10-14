<?php

declare(strict_types=1);

namespace JsonLinesImporter\Enum;

enum ReferentialType: string
{
    case TAX_RULE = 'tax_rule';
    case CATEGORY = 'category';
    case BRAND = 'brand';
    case FEATURE = 'feature';
    case FEATURE_VALUE = 'feature_av';
    case ATTRIBUTE = 'attribute';
    case ATTRIBUTE_VALUE = 'attribute_av';

    public function label(): string
    {
        return match ($this) {
            self::TAX_RULE => 'Tax rules',
            self::CATEGORY => 'Categories',
            self::BRAND => 'Brands',
            self::FEATURE => 'Features',
            self::FEATURE_VALUE => 'Feature values',
            self::ATTRIBUTE => 'Attributes',
            self::ATTRIBUTE_VALUE => 'Attribute values',
        };
    }

    public function parentType(): ?self
    {
        return match ($this) {
            self::FEATURE_VALUE => self::FEATURE,
            self::ATTRIBUTE_VALUE => self::ATTRIBUTE,
            default => null,
        };
    }

    public function isValue(): bool
    {
        return null !== $this->parentType();
    }

    public function canBeCreated(): bool
    {
        return self::TAX_RULE !== $this;
    }

    /** @return list<self> the mapping pages, in the order of the wizard */
    public static function pages(): array
    {
        return [self::TAX_RULE, self::CATEGORY, self::BRAND, self::FEATURE, self::FEATURE_VALUE, self::ATTRIBUTE, self::ATTRIBUTE_VALUE];
    }
}
