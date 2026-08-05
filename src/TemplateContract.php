<?php

declare(strict_types=1);

namespace XtScript;

use XtScript\Contract\TemplateContractInterface;
use XtScript\Exception\TemplateContractException;

/**
 * Optional host-side context schema. Type names are intentionally small and
 * portable: mixed, string, int, float, number, bool, array, list, iterable,
 * scalar, object. Prefix with ? to allow null.
 */
final readonly class TemplateContract implements TemplateContractInterface
{
    /**
     * @param array<string, string> $types
     * @param array<string, mixed> $defaults
     */
    public function __construct(
        private array $types,
        private array $defaults = [],
        private bool $allowExtra = true,
    ) {
    }

    public function validate(array $variables): array
    {
        $normalized = [];
        foreach ($variables as $name => $value) {
            $normalized[ltrim((string) $name, '$')] = $value;
        }
        $defaults = [];
        foreach ($this->defaults as $name => $value) {
            $defaults[ltrim((string) $name, '$')] = $value;
        }

        $result = $normalized;
        foreach ($this->types as $rawName => $rawType) {
            $name = ltrim((string) $rawName, '$');
            $type = trim($rawType);
            if (!array_key_exists($name, $result)) {
                if (array_key_exists($name, $defaults)) {
                    $result[$name] = $defaults[$name];
                } else {
                    throw new TemplateContractException(sprintf('Required template variable "$%s" is missing.', $name));
                }
            }
            if (!$this->matches($result[$name], $type)) {
                throw new TemplateContractException(sprintf(
                    'Template variable "$%s" must be %s; %s given.',
                    $name,
                    $type,
                    get_debug_type($result[$name]),
                ));
            }
        }

        if (!$this->allowExtra) {
            $known = array_map(static fn (string $name): string => ltrim($name, '$'), array_keys($this->types));
            foreach (array_keys($result) as $name) {
                if (!in_array($name, $known, true)) {
                    throw new TemplateContractException(sprintf('Unexpected template variable "$%s".', $name));
                }
            }
        }

        return $result;
    }

    private function matches(mixed $value, string $type): bool
    {
        $nullable = str_starts_with($type, '?');
        if ($nullable) {
            $type = substr($type, 1);
            if ($value === null) return true;
        }

        return match (strtolower($type)) {
            'mixed' => true,
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float' => is_float($value),
            'number', 'numeric' => is_int($value) || is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'list' => is_array($value) && array_is_list($value),
            'iterable' => is_iterable($value),
            'scalar' => is_scalar($value),
            'object' => is_object($value),
            'null' => $value === null,
            default => throw new TemplateContractException(sprintf('Unknown template contract type "%s".', $type)),
        };
    }
}
