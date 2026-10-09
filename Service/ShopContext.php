<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\TaxRuleQuery;

/**
 * Languages, currencies and defaults of the shop, read once per request.
 */
final class ShopContext
{
    /** @var array<string, bool>|null locale => active */
    private ?array $locales = null;

    /** @var array<string, int>|null code => id */
    private ?array $currencies = null;

    public function defaultLocale(): string
    {
        return Lang::getDefaultLanguage()->getLocale();
    }

    public function isKnownLocale(string $locale): bool
    {
        return \array_key_exists($locale, $this->locales());
    }

    public function isActiveLocale(string $locale): bool
    {
        return $this->locales()[$locale] ?? false;
    }

    /** @return list<string> */
    public function activeLocales(): array
    {
        return array_keys(array_filter($this->locales()));
    }

    public function currencyId(string $code): ?int
    {
        return $this->currencies()[$code] ?? null;
    }

    public function defaultCurrency(): Currency
    {
        return Currency::getDefaultCurrency();
    }

    public function defaultTaxRuleId(): ?int
    {
        $taxRule = TaxRuleQuery::create()->findOneByIsDefault(true);

        return null === $taxRule ? null : (int) $taxRule->getId();
    }

    /** @return array<string, bool> */
    private function locales(): array
    {
        if (null === $this->locales) {
            $this->locales = [];

            foreach (LangQuery::create()->find() as $lang) {
                $this->locales[(string) $lang->getLocale()] = (bool) $lang->getActive();
            }
        }

        return $this->locales;
    }

    /** @return array<string, int> */
    private function currencies(): array
    {
        if (null === $this->currencies) {
            $this->currencies = [];

            foreach (CurrencyQuery::create()->find() as $currency) {
                $this->currencies[(string) $currency->getCode()] = (int) $currency->getId();
            }
        }

        return $this->currencies;
    }
}
