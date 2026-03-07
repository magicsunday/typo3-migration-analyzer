<?php

declare(strict_types=1);

namespace App\Dto;

readonly class CodeReference
{
    public function __construct(
        public string $className,
        public ?string $member,
        public CodeReferenceType $type,
    ) {
    }

    /**
     * Parse a `:php:` role value into a CodeReference.
     *
     * Supported formats:
     *   - `Vendor\Package\Class::method()`       → StaticMethod
     *   - `Vendor\Package\Class->method()`        → InstanceMethod
     *   - `Vendor\Package\Class->$property`       → Property
     *   - `Vendor\Package\Class::CONSTANT`        → ClassConstant (uppercase member)
     *   - `Vendor\Package\Class`                  → ClassName
     *
     * Returns null for non-FQCN values (no namespace separator or less than 2 segments).
     */
    public static function fromPhpRole(string $value): ?self
    {
        // Strip leading backslash
        $value = ltrim($value, '\\');

        if ('' === $value) {
            return null;
        }

        // Must contain at least one namespace separator (2+ segments) to be a FQCN
        if (!str_contains($value, '\\')) {
            return null;
        }

        // Instance member: Class->method() or Class->$property
        if (preg_match('/^(.+)->(.+)$/', $value, $matches)) {
            $className = $matches[1];
            $memberRaw = $matches[2];

            // Property: Class->$property
            if (str_starts_with($memberRaw, '$')) {
                return new self(
                    className: $className,
                    member: ltrim($memberRaw, '$'),
                    type: CodeReferenceType::Property,
                );
            }

            // Instance method: Class->method()
            return new self(
                className: $className,
                member: rtrim($memberRaw, '()'),
                type: CodeReferenceType::InstanceMethod,
            );
        }

        // Static member: Class::member
        if (str_contains($value, '::')) {
            $parts = explode('::', $value, 2);
            $className = $parts[0];
            $memberRaw = $parts[1];
            $member = rtrim($memberRaw, '()');

            // Class constant: all uppercase (with underscores/digits)
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $member)) {
                return new self(
                    className: $className,
                    member: $member,
                    type: CodeReferenceType::ClassConstant,
                );
            }

            // Static method
            return new self(
                className: $className,
                member: $member,
                type: CodeReferenceType::StaticMethod,
            );
        }

        // Plain FQCN (class name only)
        return new self(
            className: $value,
            member: null,
            type: CodeReferenceType::ClassName,
        );
    }
}
