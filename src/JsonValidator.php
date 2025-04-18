<?php

/**
 * Made with love.
 */

declare(strict_types = 1);
namespace FallegaHQ\JsonTestUtils;

use DateTime;

class JsonValidator {
    private mixed $data;
    private array $errors   = [];
    private bool $validated = false;

    /**
     * @param array|string $data JSON string or array to validate
     */
    public function __construct(array|string $data) {
        if (is_string($data)) {
            $decoded    = json_decode($data, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new JsonValidationException('Invalid JSON string: '.json_last_error_msg());
            }
            $this->data = $decoded;
        }
        elseif (is_array($data)) {
            $this->data = $data;
        }
    }

    /**
     * Create a new validator instance
     *
     * @param array|string $data JSON string or array to validate
     */
    public static function validator(array|string $data): self {
        return new self($data);
    }

    /**
     * Validate that multiple keys are of specific types
     *
     * @param array $typeMap Key-type pairs
     *
     * @return $this
     */
    public function hasTypedItems(array $typeMap): static {
        foreach ($typeMap as $key => $type) {
            $this->isType($key, $type);
        }

        return $this;
    }

    /**
     * Validate that a key is of a specific type
     *
     * @param string $key  The key to check (dot notation supported)
     * @param string $type The expected type or class name
     *
     * @return $this
     */
    public function isType(string $key, string $type): static {
        if (! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return $this;
        }

        $value   = $this->getValue($key);

        $isValid = match ($type) {
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float', 'double' => is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array'  => is_array($value),
            'object' => is_object($value),
            'null'   => null === $value,
            default  => $value instanceof $type,
        };

        if (! $isValid) {
            $this->addError($key, "The '{$key}' must be of type: {$type}");
        }

        return $this;
    }

    /**
     * Validate that a key exists, but is optional
     *
     * @param string $key   The key to check (dot notation supported)
     * @param mixed  $value Value to validate against, if the key is present
     *
     * @return $this
     */
    public function optional(string $key, mixed $value): static {
        if (! $this->hasKey($key)) {
            return $this;
        }

        // Check the value
        return $this->hasWithValue($key, $value);
    }

    /**
     * Validate that a key equals a specific value
     *
     * @param string $key   The key to check (dot notation supported)
     * @param mixed  $value The value to compare against
     *
     * @return $this
     */
    public function hasWithValue(string $key, mixed $value): static {
        /**
         * @phpstan-ignore-next-line
         */
        if (! $this->hasKey($key) || $value != $this->getValue($key)) {
            $this->addError($key, "The '{$key}' must be exactly: ".$this->valueToString($value));
        }

        return $this;
    }

    /**
     * Validate that a key is optional, but if it exists, validate its type
     *
     * @param string $key  The key to check (dot notation supported)
     * @param string $type The expected type or class name
     *
     * @return $this
     */
    public function optionalWithType(string $key, string $type): static {
        if (! $this->hasKey($key)) {
            return $this;
        }

        // Check the type
        return $this->isType($key, $type);
    }

    /**
     * Validate that all keys in the array exist
     *
     * @param array $keys Array of keys that should all exist (dot notation supported)
     *
     * @return $this
     */
    public function hasAll(array $keys): static {
        foreach ($keys as $key) {
            $this->has($key);
        }

        return $this;
    }

    /**
     * Validate that a key exists
     *
     * @param string $key The key that should exist (dot notation supported)
     *
     * @return $this
     */
    public function has(string $key): static {
        if (! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");
        }

        return $this;
    }

    /**
     * Validate that none of the keys in the array exist
     *
     * @param array $keys Array of keys that should not exist (dot notation supported)
     *
     * @return $this
     */
    public function hasNoneOf(array $keys): static {
        foreach ($keys as $key) {
            $this->hasNot($key);
        }

        return $this;
    }

    /**
     * Validate that a key doesn't exist
     *
     * @param string $key The key that should not exist (dot notation supported)
     *
     * @return $this
     */
    public function hasNot(string $key): static {
        if ($this->hasKey($key)) {
            $this->addError($key, "The '{$key}' must not be present");
        }

        return $this;
    }

    /**
     * Validate that at least one of the keys exists
     *
     * @param string ...$keys Variable number of keys, at least one should exist (dot notation supported)
     *
     * @return $this
     */
    public function hasAnyOf(string ...$keys): static {
        $exists = false;
        foreach ($keys as $key) {
            if ($this->hasKey($key)) {
                $exists = true;
                break;
            }
        }

        if (! $exists) {
            $keysString = implode(', ', $keys);
            $this->addError('anyOf', "At least one of these keys must exist: {$keysString}");
        }

        return $this;
    }

    /**
     * Validate that a key represents a file path and the file exists
     *
     * @param string $key       The key containing a file path (dot notation supported)
     * @param bool   $mustExist Whether the file must exist (default: true)
     *
     * @return $this
     */
    public function isFile(string $key, bool $mustExist = true): static {
        if (! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return $this;
        }

        $value = $this->getValue($key);

        if (! is_string($value)) {
            $this->addError($key, "The '{$key}' must be a string representing a file path");

            return $this;
        }

        if ($mustExist && ! file_exists($value)) {
            $this->addError($key, "The file specified in '{$key}' does not exist: {$value}");
        }

        return $this;
    }

    /**
     * Validate that a key is a date in a specified format
     *
     * @param string $key    The key to check (dot notation supported)
     * @param string $format DateTime format (default: 'Y-m-d')
     *
     * @return $this
     */
    public function isDate(string $key, string $format = 'Y-m-d'): static {
        if (! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return $this;
        }

        $value = $this->getValue($key);

        if (! is_string($value)) {
            $this->addError($key, "The '{$key}' must be a string for date validation");

            return $this;
        }

        $date  = DateTime::createFromFormat($format, $value);

        if (false === $date || $date->format($format) !== $value) {
            $this->addError($key, "The '{$key}' must be a valid date in format: {$format}");
        }

        return $this;
    }

    /**
     * Validate that a key is a valid email address
     *
     * @param string $key The key to check (dot notation supported)
     *
     * @return $this
     */
    public function isEmail(string $key): static {
        return $this->passes($key, function ($value) {
            return is_string($value) && false !== filter_var($value, FILTER_VALIDATE_EMAIL);
        }, $key.' must be a valid email address');
    }

    /**
     * Validate that a key matches a custom condition
     *
     * @param string      $key     The key to check (dot notation supported)
     * @param callable    $check   Function that returns true if valid
     * @param string|null $message Optional custom error message
     *
     * @return $this
     */
    public function passes(string $key, callable $check, ?string $message = null): static {
        if (! $this->hasKey($key) || ! $check($this->getValue($key))) {
            $this->addError($key, $message ?? "The '{$key}' doesn't match the required condition");
        }

        return $this;
    }

    /**
     * Validate that a key is a valid URL
     *
     * @param string $key The key to check (dot notation supported)
     *
     * @return $this
     */
    public function isURL(string $key): static {
        return $this->passes($key, function ($value) {
            return is_string($value) && false !== filter_var($value, FILTER_VALIDATE_URL);
        }, $key.' must be a valid URL');
    }

    /**
     * Validate that a key is a valid IP address
     *
     * @param string   $key   The key to check (dot notation supported)
     * @param int|null $flags FILTER_FLAG_IPV4 or FILTER_FLAG_IPV6 to limit to a specific version
     *
     * @return $this
     */
    public function isIP(string $key, ?int $flags = null): static {

        if (! in_array($flags, [
            null,
            FILTER_FLAG_IPV4,
            FILTER_FLAG_IPV6,
        ], true, )) {
            $this->addError($key, 'Invalid flag: '.$flags);

            return $this;
        }

        return $this->passes($key, function ($value) use ($flags) {
            $options = null                   !== $flags ? [
                'flags' => $flags,
            ] : [];

            return is_string($value) && false !== filter_var($value, FILTER_VALIDATE_IP, $options);
        }, $key.' must be a valid IP address');
    }

    /**
     * Validate that a string contains a specific substring
     *
     * @param string $key           The key to check (dot notation supported)
     * @param string $needle        The substring to search for
     * @param bool   $caseSensitive Whether the search is case-sensitive (default: true)
     *
     * @return $this
     */
    public function contains(string $key, string $needle, bool $caseSensitive = true): static {
        if (! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return $this;
        }

        $value = $this->getValue($key);

        if (! is_string($value)) {
            $this->addError($key, "The '{$key}' must be a string");

            return $this;
        }

        $found = $caseSensitive ? str_contains($value, $needle) : false !== stripos($value, $needle);

        if (! $found) {
            $this->addError($key, "The '{$key}' must contain '{$needle}'".($caseSensitive ? '' : ' (case insensitive)'));
        }

        return $this;
    }

    /**
     * Validate that an array contains items of a specific type
     *
     * @param string $key  The key to check (dot notation supported)
     * @param string $type The expected type of items
     *
     * @return $this
     */
    public function arrayOfType(string $key, string $type): static {
        if (! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return $this;
        }

        $value = $this->getValue($key);

        if (! is_array($value)) {
            $this->addError($key, "The '{$key}' must be an array");

            return $this;
        }

        foreach ($value as $index => $item) {
            $this->isType($key.'.'.$index, $type);
        }

        return $this;
    }

    /**
     * Validate that an array's items all pass a validation callback
     *
     * @param string   $key      The key to check (dot notation supported)
     * @param callable $callback Function to validate each item, returning true if valid or error message if invalid
     *
     * @return $this
     */
    public function passesEach(string $key, callable $callback): static {
        if (! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return $this;
        }

        $value = $this->getValue($key);

        if (! is_array($value)) {
            $this->addError($key, "The '{$key}' must be an array");

            return $this;
        }

        foreach ($value as $index => $item) {
            $result = $callback($item, $index);

            if (true !== $result) {
                $message = is_string($result) ? $result : "Item at index {$index} failed validation";
                $this->addError("{$key}.{$index}", $message);
            }
        }

        return $this;
    }

    /**
     * Validate that a string or array is not empty
     *
     * @param string $key The key to check (dot notation supported)
     *
     * @return $this
     */
    public function isNotEmpty(string $key): static {
        if (! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return $this;
        }

        $value = $this->getValue($key);

        if ('' === $value || (is_array($value) && 0 === count($value))) {
            $type = is_string($value) ? 'string' : 'array';
            $this->addError($key, "The '{$key}' {$type} must not be empty");
        }

        return $this;
    }

    /**
     * Validate against a predefined schema
     *
     * @param string $key    The key to check (dot notation supported, empty for root)
     * @param array  $schema The schema definition
     *
     * @return $this
     */
    public function passesSchema(string $key, array $schema): static {
        $data = '' === $key ? $this->data : $this->getValue($key);

        if ('' !== $key && ! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return $this;
        }

        if (! is_array($data)) {
            $this->addError($key, 'The '.('' === $key ? 'data' : "'{$key}'").' must be an object or array');

            return $this;
        }

        foreach ($schema as $schemaKey => $validationRules) {
            $fullKey = '' === $key ? $schemaKey : "{$key}.{$schemaKey}";

            if (is_callable($validationRules)) {
                // If it's a callback, execute the validation function
                $validationRules($this, $fullKey);
            }
            elseif (is_array($validationRules)) {
                // If it's a nested schema
                if (isset($validationRules['type'])) {
                    // This is a property definition with rules
                    $this->applySchemaRules($fullKey, $validationRules);
                }
                else {
                    // This is a nested schema
                    $this->passesSchema($fullKey, $validationRules);
                }
            }
            elseif (is_string($validationRules)) {
                // Simple type validation
                $this->isType($fullKey, $validationRules);
            }
        }

        return $this;
    }

    /**
     * Validate that a key's value is in a list of allowed values
     *
     * @param string       $key           The key to check (dot notation supported)
     * @param array|string $allowedValues Array of allowed values or enum class name
     *
     * @return $this
     */
    public function isIn(string $key, array|string $allowedValues): static {
        if (! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return $this;
        }

        $value = $this->getValue($key);

        if (is_string($allowedValues) && class_exists($allowedValues)) {
            // Check if it's a PHP 8.1+ enum
            if (method_exists($allowedValues, 'cases')) {
                $enumCases = $allowedValues::cases();

                // Check if it's a backed enum first
                if (property_exists($enumCases[0], 'value')) {
                    $allowedValues = array_map(fn ($case) => $case->value, $enumCases);
                }
                else {
                    // For pure enums, use the name property
                    $allowedValues = array_map(fn ($case) => $case->name, $enumCases);
                }
            }
        }

        if (is_array($allowedValues) && ! in_array($value, $allowedValues, true)) {
            $allowedValuesString = implode(', ', array_map([
                $this,
                'valueToString',
            ], $allowedValues, ), );
            $this->addError($key, "The '{$key}' must be one of: {$allowedValuesString}");
        }

        return $this;
    }

    /**
     * Validate that a numeric value is between a min and max
     *
     * @param string    $key The key to check (dot notation supported)
     * @param float|int $min Minimum value (inclusive)
     * @param float|int $max Maximum value (inclusive)
     *
     * @return $this
     */
    public function isBetween(string $key, float|int $min, float|int $max): static {
        if (! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return $this;
        }

        $value = $this->getValue($key);

        if (! is_numeric($value)) {
            $this->addError($key, "The '{$key}' must be numeric");

            return $this;
        }

        if ($value < $min || $value > $max) {
            $this->addError($key, "The '{$key}' must be between {$min} and {$max}");
        }

        return $this;
    }

    /**
     * Validate that a string or array has a specific length
     *
     * @param string   $key   The key to check (dot notation supported)
     * @param int|null $exact Exact length required (or null if using min/max)
     * @param int|null $min   Minimum length (inclusive)
     * @param int|null $max   Maximum length (inclusive)
     *
     * @return $this
     */
    public function hasLength(string $key, ?int $exact = null, ?int $min = null, ?int $max = null): static {
        if (! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return $this;
        }

        $value  = $this->getValue($key);

        if (! is_string($value) && ! is_array($value)) {
            $this->addError($key, "The '{$key}' must be a string or array");

            return $this;
        }

        $length = is_string($value) ? strlen($value) : count($value);
        $type   = is_string($value) ? 'string' : 'array';

        if (null !== $exact && $length !== $exact) {
            $this->addError($key, "The '{$key}' {$type} must be exactly {$exact} ".('string' === $type ? 'characters' : 'items').' long');
        }

        if (null !== $min && $length < $min) {
            $this->addError($key, "The '{$key}' {$type} must be at least {$min} ".('string' === $type ? 'characters' : 'items').' long');
        }

        if (null !== $max && $length > $max) {
            $this->addError($key, "The '{$key}' {$type} must not exceed {$max} ".('string' === $type ? 'characters' : 'items').' long');
        }

        return $this;
    }

    /**
     * Validate that a key matches a regex pattern
     *
     * @param string $key      The key to check (dot notation supported)
     * @param string $pattern  Regex pattern
     * @param bool   $matchAll Whether to use preg_match_all (default: false)
     *
     * @return $this
     */
    public function matchesRegex(string $key, string $pattern, bool $matchAll = false): static {
        if (! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return $this;
        }

        $value         = $this->getValue($key);

        if (! is_string($value)) {
            $this->addError($key, "The '{$key}' must be a string for regex validation");

            return $this;
        }

        $matchFunction = $matchAll ? 'preg_match_all' : 'preg_match';

        if ($matchFunction($pattern, $value) <= 0) {
            $this->addError($key, "The '{$key}' must match the pattern: {$pattern}");
        }

        return $this;
    }

    /**
     * Get validation errors
     *
     * @return array Validation errors
     */
    public function getErrors(): array {
        if (! $this->validated) {
            $this->validated();
        }

        return $this->errors;
    }

    /**
     * Execute the validation and return true if valid
     *
     * @return bool Whether validation passed
     */
    public function validated(): bool {
        $this->validated = true;

        return empty($this->errors);
    }

    /**
     * Get the validated data
     *
     * @return array|null The validated data or null if validation failed
     */
    public function getValidData(): ?array {
        return $this->validated() ? $this->data : null;
    }

    /**
     * Execute the validation and throw an exception if invalid
     *
     * @throws \JsonException
     * @throws JsonValidationException If validation fails
     * @return bool                    Whether the validation was successful
     */
    public function validatedStrict(): bool {
        if ($this->failed()) {
            throw new JsonValidationException('Validation failed: '.json_encode($this->errors, JSON_THROW_ON_ERROR));
        }

        return true;
    }

    /**
     * Execute the validation and return false if valid
     *
     * @return bool Whether validation failed
     */
    public function failed(): bool {
        return ! $this->validated();
    }

    /**
     * Check if a key exists in the data (supports dot notation)
     *
     * @param string $key The key to check
     *
     * @return bool Whether the key exists
     */
    private function hasKey(string $key): bool {
        if (! str_contains($key, '.')) {
            return array_key_exists($key, $this->data);
        }

        $value = $this->getNestedValue($this->data, explode('.', $key));

        return null !== $value;
    }

    /**
     * Get a nested value from an array using an array of keys
     *
     * @param array $array The array to search in
     * @param array $keys  Array of nested keys
     *
     * @return mixed|null The nested value or null if not found
     */
    private function getNestedValue(array $array, array $keys): mixed {
        $current = $array;

        foreach ($keys as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * Get value for a key (supports dot notation)
     *
     * @param string $key The key to retrieve
     *
     * @return mixed The value
     */
    private function getValue(string $key): mixed {
        if (! str_contains($key, '.')) {
            return $this->data[$key] ?? null;
        }

        return $this->getNestedValue($this->data, explode('.', $key));
    }

    /**
     * Add an error for a key
     *
     * @param string $key     The key with the error
     * @param string $message The error message
     */
    private function addError(string $key, string $message): void {
        if (! isset($this->errors[$key])) {
            $this->errors[$key] = [];
        }
        $this->errors[$key][] = $message;
    }

    /**
     * Convert a value to a string representation
     *
     * @param mixed $value The value to convert
     *
     * @return string String representation
     */
    private function valueToString(mixed $value): string {
        if (null === $value) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return '[array]';
        }
        if (is_object($value)) {
            return '[object]';
        }

        return (string) $value;

    }

    /**
     * Apply schema rules to a field
     *
     * @param string $key   The key to apply rules to
     * @param array  $rules The rules to apply
     */
    private function applySchemaRules(string $key, array $rules): void {
        // Required field?
        if (isset($rules['required']) && $rules['required'] && ! $this->hasKey($key)) {
            $this->addError($key, "The '{$key}' is required");

            return;
        }

        // Skip if field doesn't exist and is not required
        if (! $this->hasKey($key)) {
            return;
        }

        // Check type
        if (isset($rules['type'])) {
            $this->isType($key, $rules['type']);
        }

        // Check enum values
        if (isset($rules['enum'])) {
            $this->isIn($key, $rules['enum']);
        }

        // Check min/max for numbers
        if (isset($rules['min']) || isset($rules['max'])) {
            $min = $rules['min'] ?? PHP_FLOAT_MIN;
            $max = $rules['max'] ?? PHP_FLOAT_MAX;
            $this->isBetween($key, $min, $max);
        }

        // Check string length
        if (isset($rules['minLength']) || isset($rules['maxLength'])) {
            $this->hasLength($key, null, $rules['minLength'] ?? null, $rules['maxLength'] ?? null);
        }

        // Check pattern
        if (isset($rules['pattern'])) {
            $this->matchesRegex($key, $rules['pattern']);
        }
    }
}
