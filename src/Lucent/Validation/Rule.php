<?php

namespace Lucent\Validation;

use InvalidArgumentException;
use Lucent\Application;
use Lucent\Facades\Regex;
use Lucent\Http\Request;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionType;

/**
 * Abstract Rule Class
 *
 * Base class for defining validation rules in the Lucent Framework.
 * Provides a system for validating input data against a set of constraints.
 *
 * @package Lucent\Validation
 */
abstract class Rule
{
    /**
     * Custom regex patterns defined locally within this rule
     *
     * @var array
     */
    protected array $customRegexPatterns = [];

    /**
     * The validation rules to apply
     *
     * @var array
     */
    protected(set) array $rules = [];

    protected array $customMessages = [];

    /**
     * The request that initiated this validation, if available
     *
     * @var Request|null
     */
    protected ?Request $currentRequest = null;

    /**
     * Set up the validation rules
     *
     * Must be implemented by child classes to define validation rules.
     *
     * @return array Associative array of field names and validation rules
     */
    public abstract function setup() : array;

    /**
     * Validates a value against a regex pattern
     *
     * @param string $key The regex pattern key
     * @param string $value The value to validate
     * @return bool Whether the value matches the pattern
     * @throws InvalidArgumentException If the regex pattern doesn't exist
     */
    private function regex(string $key, string $value): bool
    {
        $patterns = $this->getRegexPatterns();

        if(!isset($patterns[$key])){
            throw new InvalidArgumentException("Regex {$key} does not exists");
        }

        if($patterns[$key]["message"] !== null){
            $this->customMessages["regex"] = $patterns[$key]["message"];
        }

        return preg_match($patterns[$key]["pattern"], $value);
    }

    /**
     * Validates that a string's length is at least the specified value
     *
     * @param int $min The minimum required length
     * @param string $value The value to validate
     * @return bool Whether the value meets the minimum length
     */
    private function min(int $min, string $value): bool
    {
        return strlen($value) >= $min;
    }

    /**
     * Validates that a numeric value is at least the specified minimum
     *
     * @param int $min The minimum required value
     * @param string $value The value to validate (as string)
     * @return bool Whether the value meets the minimum
     */
    private function min_num(int $min, string $value): bool
    {
        if (!is_numeric($value))
            return false;

        return intval($value, 10) >= $min;
    }

    /**
     * Validates that a string's length is at most the specified value
     *
     * @param int $max The maximum allowed length
     * @param string $value The value to validate
     * @return bool Whether the value doesn't exceed the maximum length
     */
    private function max(int $max, string $value): bool
    {
        return strlen($value) <= $max;
    }

    /**
     * Validates that a numeric value is at most the specified maximum
     *
     * @param int $max The maximum allowed value
     * @param string $value The value to validate (as string)
     * @return bool Whether the value doesn't exceed the maximum
     */
    private function max_num(int $max, string $value): bool
    {
        if (!is_numeric($value))
            return false;

        return intval($value, 10) <= $max;
    }

    /**
     * Validates that two values are identical
     *
     * @param string $first The first value
     * @param string $second The second value
     * @return bool Whether the values match
     */
    private function same(string $first, string $second): bool
    {
        return $first === $second;
    }

    /**
     * Validates that a value doesn't exist in the specified database table column
     *
     * @param string $table The model class name (without namespace)
     * @param string $column The column name to check
     * @param string $value The value to validate
     * @return bool True if the value doesn't exist in the table
     * @throws ReflectionException
     */
    private function unique(string $table, string $column, string $value): bool
    {
        $namespace = 'App\\Models\\';
        $class = new ReflectionClass($namespace . $table);
        $instance = $class->newInstanceWithoutConstructor();

        $model = $instance::where($column, $value)->getFirst();

        if ($model !== null) {
            $this->currentRequest->context[$table] = $model;
        }

        return $model === null;
    }

    /**
     * Validates the given data against the defined rules
     *
     * Processes each field against its validation rules and collects any validation errors.
     * Supports rule negation with '!' prefix.
     *
     * @param array $data The data to validate
     * @return array Array of validation errors, empty if validation passed
     * @throws InvalidArgumentException|ReflectionException If an unknown validation rule is encountered
     */
    public function validate(array $data): array
    {
        $output = [];

        if ($this->rules === []) {
            $this->rules = $this->setup();
        }

        foreach ($this->rules as $key => $rules) {

            //Check if only a single rule has been provided as a string, if so transform it into an array.
            if (gettype($rules) === "string") {
                $new = [];
                array_push($new, $rules);
                $rules = $new;
            }

            //If the key is not set then set it to a blanks string.
            if (!isset($data[$key])) {
                $data[$key] = "";
            }else{
                $data[$key] = trim($data[$key]);
            }

            // Extract special flags like nullable
            $isNullable = in_array("nullable", $rules);
            // Remove nullable from a rule array so it's not processed as a validation rule
            $rules = array_filter($rules, fn($rule) => $rule !== "nullable");

            // Skip validation entirely if field is empty and nullable
            if ($isNullable && ($data[$key] === "" || !isset($data[$key]))) {
                continue;
            }

            foreach ($rules as $rule) {

                $parts = explode(":", $rule);
                $methodName = $parts[0];
                $isNegatedRule = false;

                //Check if we are passing a '!', if so set negated to true
                if(str_starts_with($methodName, "!")){
                    $methodName = substr($methodName, 1);
                    $isNegatedRule = true;
                }

                if($methodName === "unique" || $methodName === "!unique"){
                    $parts[] = $key;
                }

                if (method_exists($this, $methodName)) {

                    $method = new ReflectionMethod($this, $methodName);
                    $params = $method->getParameters();

                    $parts = array_slice($parts, 1);
                    $parts = array_merge($parts,[$data[$key]]);

                    $args = [];

                    foreach ($params as $index => $param) {
                        if (isset($parts[$index])) {
                            $value = $this->processVariable($parts[$index],$data);
                            $args[] = $this->castToType($value, $param->getType());
                        } else {
                            // If parameter has default value and no corresponding value provided
                            if ($param->isDefaultValueAvailable()) {
                                $args[] = $param->getDefaultValue();
                            }
                        }
                    }

                    // Store the result of method invocation to avoid calling it twice
                    $outcome = $method->invokeArgs($this, $args);

                    // If not is true, we want to flip the outcome
                    if ((!$outcome && !$isNegatedRule) || ($outcome && $isNegatedRule)) {

                        $messages = array_merge(Application::getInstance()->getValidationMessages(),$this->customMessages);

                        if(array_key_exists($method->getName(), $messages)) {

                            if($methodName === "same"){
                                $args[1] = substr($parts[0], 1);
                            }

                            $message = $messages[$method->getName()];
                            $output[trim($key)] = $this->translateMessage($message, $key, $method, $args);
                        } else {
                            $output[$key] = $key . " failed " . str_replace('_', ' ', $method->getName()) . " validation rule";
                        }
                    }

                } else {
                    // Unknown validation rule - throw exception
                    throw new InvalidArgumentException("Unknown validation rule '{$parts[0]}' in field '{$key}'");
                }
            }
        }

        return $output;
    }

    /**
     * Gets the validation rules defined in setup
     *
     * Used primarily by the documentation generator
     *
     * @return array The validation rules
     */
    public function getRules(): array
    {
        return $this->setup();
    }

    /**
     * Extracts a variable from a rule string
     *
     * @param string $rule The rule string
     * @return string The extracted variable
     */
    private function getVar(string $rule): string
    {
        if (str_contains($rule, ":")) {
            return explode(":", $rule)[1];
        } else {
            return $rule;
        }
    }

    /**
     * Sets the request that triggered this validation
     *
     * @param Request $request The current HTTP request
     * @return void
     */
    public function setCallingRequest(Request $request): void
    {
        $this->currentRequest = $request;
    }

    /**
     * Casts a value to the appropriate type based on the method parameter type
     *
     * @param mixed $value The value to cast
     * @param ReflectionType|null $type The target type
     * @return mixed The casted value
     */
    private function castToType(mixed $value, ?ReflectionType $type): mixed
    {
        if (!$type) {
            return $value;
        }

        $typeName = $type->getName();

        return match($typeName) {
            'int' => (int)$value,
            'float' => (float)$value,
            'bool' => (bool)$value,
            'string' => (string)$value,
            'array' => (array)$value,
            default => $value
        };
    }

    /**
     * Processes variables that may reference other input fields
     *
     * If a variable starts with '@', it's treated as a reference to another field in the data array.
     *
     * @param mixed $value The value to process
     * @param array $data The complete input data array
     * @return mixed The processed value
     */
    private function processVariable(mixed $value, array $data): mixed
    {

        if(str_starts_with($value, "@")) {

            $fieldName = substr($value, 1);

            return $data[$fieldName] ?? null;

        } else {

            return $value;
        }
    }

    /**
     * Translates a validation message by replacing placeholders with actual values
     *
     * @param string $message The message template with placeholders
     * @param string $attribute The field name being validated
     * @param ReflectionMethod $method The validation method reflection
     * @param array $paramValues The parameter values used in the validation rule
     * @return string The translated message
     */
    private function translateMessage(string $message, string $attribute, ReflectionMethod $method, array $paramValues = []): string
    {
        // Replace the :attribute placeholder with the field name
        $message = str_replace(':attribute', $attribute, $message);

        // Get the parameter names from the method
        $params = $method->getParameters();

        // Loop through parameters and replace placeholders
        foreach ($params as $index => $param) {
            $paramName = $param->getName();
            if (isset($paramValues[$index]) && str_contains($message, ':' . $paramName)) {
                $message = str_replace(':' . $paramName, $paramValues[$index], $message);
            }
        }

        return trim($message);
    }

    /**
     * Gets all available regex patterns (global and local)
     *
     * Merges patterns from the global Regex facade with local patterns.
     *
     * @return array Array of regex patterns
     */
    public function getRegexPatterns(): array
    {
        return array_merge(Regex::all(), $this->customRegexPatterns);
    }

    /**
     * Adds a custom regex pattern to this rule
     *
     * @param string $name Pattern name
     * @param string $pattern The regex pattern
     * @param string|null $message Optional custom error message
     * @return void
     */
    public function addRegexPattern(string $name, string $pattern, ?string $message = null): void
    {
        $this->customRegexPatterns[$name] = ["pattern"=>$pattern, "message"=>$message];
    }

    public function overrideRuleMessage(string $rule, string $message): void
    {
        $this->customMessages[$rule] = $message;
    }
}