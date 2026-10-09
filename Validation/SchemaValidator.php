<?php

declare(strict_types=1);

namespace JsonLinesImporter\Validation;

use JsonLinesImporter\JsonLinesImporter;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates a line against the JSON Schema of the format (draft 2020-12).
 */
final class SchemaValidator
{
    private const MAX_ERRORS = 20;

    private ?Validator $validator = null;

    private readonly string $schemaPath;

    public function __construct(?string $schemaPath = null)
    {
        $this->schemaPath = $schemaPath ?? \dirname(__DIR__).'/Config/schema/product-import-1.0.json';
    }

    /**
     * @param \stdClass $line the line decoded as objects, the way JSON Schema sees it
     *
     * @return list<string> "path: message", empty when the line is valid
     */
    public function validate(mixed $line): array
    {
        $result = $this->validator()->validate($line, JsonLinesImporter::SCHEMA_ID);

        if ($result->isValid()) {
            return [];
        }

        $messages = [];

        foreach ((new ErrorFormatter())->format($result->error()) as $path => $pathMessages) {
            foreach ((array) $pathMessages as $message) {
                $messages[] = ('' === $path || '/' === $path ? '(root)' : $path).': '.self::explain((string) $path, (string) $message);
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * The "not severalDefaults" rule surfaces as a bare negation: say what it means.
     */
    private static function explain(string $path, string $message): string
    {
        if (\in_array($path, ['/variants', '/categories'], true) && str_contains($message, 'must not match')) {
            return 'only one item can be marked "default": true';
        }

        return $message;
    }

    private function validator(): Validator
    {
        if (null === $this->validator) {
            $this->validator = new Validator();
            $this->validator->setMaxErrors(self::MAX_ERRORS);
            $this->validator->resolver()->registerFile(JsonLinesImporter::SCHEMA_ID, $this->schemaPath);
        }

        return $this->validator;
    }
}
