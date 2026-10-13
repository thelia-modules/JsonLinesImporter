<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Dto\I18nBag;
use JsonLinesImporter\Service\Writer\HtmlImageWriter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Removes from the HTML fields of a file what could run in a browser (scripts, event attributes,
 * `javascript:` links, frames, forms): the front theme prints them as is, and a file is written by a
 * supplier or a PIM, not by an administrator of the shop.
 */
final readonly class HtmlFieldSanitizer
{
    /** What the sanitizer removes and the report tells; its other rewritings (quotes, entities) are not worth a warning. */
    private const UNSAFE = '~<\s*(script|iframe|object|embed|style|form|frame|frameset|base|meta|link)\b|\son[a-z]+\s*=|(javascript|vbscript)\s*:~i';

    private HtmlSanitizerInterface $sanitizer;

    public function __construct()
    {
        $this->sanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig())
                ->allowSafeElements()
                // Classes style the descriptions in the theme and run nothing.
                ->allowAttribute('class', '*')
                ->allowRelativeLinks()
                ->allowRelativeMedias()
                ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                ->allowMediaSchemes(['http', 'https'])
                // 20 000 characters by default: a longer description would be cut.
                ->withMaxInputLength(-1),
        );
    }

    /** @param string $subject what the fields belong to, for the report */
    public function sanitize(I18nBag $i18n, LineReport $report, string $subject): I18nBag
    {
        $values = $i18n->values;

        foreach ($values as $locale => $fields) {
            foreach (HtmlImageWriter::HTML_FIELDS as $field) {
                $html = $fields[$field] ?? null;

                if (null === $html || '' === $html) {
                    continue;
                }

                $values[$locale][$field] = $this->sanitizer->sanitize($html);

                if (1 === preg_match(self::UNSAFE, $html)) {
                    $report->warn(\sprintf('%s (%s) of %s: unsafe HTML removed', $field, $locale, $subject));
                }
            }
        }

        return new I18nBag($values);
    }
}
