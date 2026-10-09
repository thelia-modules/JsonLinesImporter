<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Dto\Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase
{
    #[Test]
    public function anAbsentKeyIsNeitherNullNorAValue(): void
    {
        $field = Field::from(['other' => 1], 'key');

        self::assertTrue($field->isAbsent());
        self::assertFalse($field->isNull());
        self::assertFalse($field->hasValue());
    }

    #[Test]
    public function aNullKeyIsPresentAndNull(): void
    {
        $field = Field::from(['key' => null], 'key', static fn (mixed $value): string => 'mapped');

        self::assertTrue($field->present);
        self::assertTrue($field->isNull());
        self::assertSame('fallback', $field->or('fallback'));
    }

    #[Test]
    public function aValueIsMapped(): void
    {
        $field = Field::from(['key' => '12'], 'key', static fn (mixed $value): int => (int) $value);

        self::assertTrue($field->hasValue());
        self::assertSame(12, $field->value);
    }
}
