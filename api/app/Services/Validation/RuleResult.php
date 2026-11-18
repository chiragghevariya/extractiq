<?php

namespace App\Services\Validation;

final class RuleResult
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(
        public readonly string $rule,
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $field = null,
    ) {}

    public static function pass(string $rule, string $message, ?string $field = null): self
    {
        return new self($rule, self::PASS, $message, $field);
    }

    public static function warn(string $rule, string $message, ?string $field = null): self
    {
        return new self($rule, self::WARN, $message, $field);
    }

    public static function fail(string $rule, string $message, ?string $field = null): self
    {
        return new self($rule, self::FAIL, $message, $field);
    }

    /** @return array{rule: string, status: string, message: string, field: ?string} */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'status' => $this->status, 'message' => $this->message, 'field' => $this->field];
    }
}
