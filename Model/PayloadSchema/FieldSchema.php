<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\PayloadSchema;

/**
 * esquema declaratiu, configurat a di.xml (equivalent a docs/contract/payload/*.json).
 *
 * $fields: nom => ['type' => string|int|number|bool|string[]|items, 'required' => bool]
 * Camps no declarats => error (additionalProperties: false).
 */
class FieldSchema implements PayloadSchemaInterface
{
    public function __construct(
        private readonly array $fields = []
    ) {
    }

    public function validate(array $payload): array
    {
        $errors = [];
        $fields = $this->fields + ['replay' => ['type' => 'bool', 'required' => false]];

        foreach ($fields as $name => $definition) {
            $required = filter_var($definition['required'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!array_key_exists($name, $payload)) {
                if ($required) {
                    $errors[] = sprintf('payload.%s is required', $name);
                }
                continue;
            }
            $error = $this->checkType($name, $payload[$name], (string)($definition['type'] ?? 'string'));
            if ($error !== null) {
                $errors[] = $error;
            }
        }
        foreach (array_diff(array_keys($payload), array_keys($fields)) as $unknown) {
            $errors[] = sprintf('payload.%s is not allowed', $unknown);
        }

        return $errors;
    }

    private function checkType(string $name, mixed $value, string $type): ?string
    {
        $valid = match ($type) {
            'string' => is_string($value) && $value !== '',
            'int' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'bool' => is_bool($value),
            'string[]' => is_array($value) && $value !== [] && array_is_list($value)
                && count(array_filter($value, static fn($v) => is_string($v) && $v !== '')) === count($value),
            'items' => is_array($value) && array_is_list($value) && $this->validItems($value),
            default => false,
        };

        return $valid ? null : sprintf('payload.%s must be %s', $name, $type);
    }

    private function validItems(array $items): bool
    {
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['sku'], $item['qty'])
                || !is_string($item['sku']) || !(is_int($item['qty']) || is_float($item['qty'])) || $item['qty'] <= 0
                || (isset($item['order_item_id']) && !is_int($item['order_item_id']))) {
                return false;
            }
        }
        return true;
    }
}
