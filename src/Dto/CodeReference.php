<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

use function explode;
use function in_array;
use function ltrim;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;

final readonly class CodeReference
{
    public function __construct(
        public string $className,
        public ?string $member,
        public CodeReferenceType $type,
        public float $resolutionConfidence = 1.0,
    ) {
    }

    /**
     * Parse a `:php:` role value into a CodeReference.
     *
     * Supported FQCN formats (confidence 1.0):
     *   - `Vendor\Package\Class::method()`       → StaticMethod
     *   - `Vendor\Package\Class->method()`        → InstanceMethod
     *   - `Vendor\Package\Class->$property`       → Property
     *   - `Vendor\Package\Class::CONSTANT`        → ClassConstant (uppercase member)
     *   - `Vendor\Package\Class`                  → ClassName
     *
     * Non-FQCN formats (lower confidence):
     *   - `$property`                             → Property (0.6)
     *   - `methodName()`                          → UnqualifiedMethod (0.5)
     *   - `ALL_UPPER_CASE`                        → ClassConstant (0.6)
     *   - `config.key` or `some/path`             → ConfigKey (0.4)
     *   - `ShortClassName`                        → ShortClassName (0.7)
     *   - anything else                           → UnqualifiedMethod (0.3)
     *
     * Returns null for PHP keywords, literals, and empty strings.
     */
    public static function fromPhpRole(string $value): ?self
    {
        // Strip leading backslash
        $value = ltrim($value, '\\');

        if ($value === '') {
            return null;
        }

        // Non-FQCN values are handled separately with lower confidence
        if (!str_contains($value, '\\')) {
            return self::fromNonFqcn($value);
        }

        // Instance member: Class->method() or Class->$property
        if (preg_match('/^(.+)->(.+)$/', $value, $matches) === 1) {
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
            $parts     = explode('::', $value, 2);
            $className = $parts[0];
            $memberRaw = $parts[1];

            // Explicit method call indicated by trailing parentheses
            $isMethodCall = str_ends_with($memberRaw, '()');
            $member       = rtrim($memberRaw, '()');

            // Class constant: all uppercase (with underscores/digits) and no parentheses
            if (!$isMethodCall && preg_match('/^[A-Z][A-Z0-9_]*$/', $member) === 1) {
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

    /** PHP keywords and literals that should not be treated as code references. */
    private const array IGNORED_VALUES = [
        'true', 'false', 'null', 'array', 'mixed', 'string', 'int', 'float',
        'bool', 'void', 'never', 'self', 'static', 'parent', 'callable',
        'iterable', 'object', 'new', '@internal',
    ];

    /**
     * Parse a non-FQCN value into a CodeReference with reduced confidence.
     *
     * Handles short class names with members, unqualified methods, properties,
     * constants, and config keys.
     */
    private static function fromNonFqcn(string $value): ?self
    {
        if (in_array(strtolower($value), self::IGNORED_VALUES, true)) {
            return null;
        }

        // Short class with instance member: ShortClass->method() or ShortClass->$prop
        if (preg_match('/^([A-Za-z]\w*)(?:->)(.+)$/', $value, $matches) === 1) {
            $className = $matches[1];
            $memberRaw = $matches[2];

            if (str_starts_with($memberRaw, '$')) {
                return new self(
                    className: $className,
                    member: ltrim($memberRaw, '$'),
                    type: CodeReferenceType::Property,
                    resolutionConfidence: 0.5,
                );
            }

            return new self(
                className: $className,
                member: rtrim($memberRaw, '()'),
                type: CodeReferenceType::InstanceMethod,
                resolutionConfidence: 0.5,
            );
        }

        // Short class with static member: ShortClass::method() or ShortClass::CONST
        if (str_contains($value, '::')) {
            $parts     = explode('::', $value, 2);
            $className = $parts[0];
            $memberRaw = $parts[1];

            $isMethodCall = str_ends_with($memberRaw, '()');
            $member       = rtrim($memberRaw, '()');

            if (!$isMethodCall && preg_match('/^[A-Z][A-Z0-9_]*$/', $member) === 1) {
                return new self(
                    className: $className,
                    member: $member,
                    type: CodeReferenceType::ClassConstant,
                    resolutionConfidence: 0.5,
                );
            }

            return new self(
                className: $className,
                member: $member,
                type: CodeReferenceType::StaticMethod,
                resolutionConfidence: 0.5,
            );
        }

        // Property: $property
        if (str_starts_with($value, '$')) {
            return new self(
                className: '',
                member: ltrim($value, '$'),
                type: CodeReferenceType::Property,
                resolutionConfidence: 0.6,
            );
        }

        // Method: methodName()
        if (str_ends_with($value, '()')) {
            return new self(
                className: '',
                member: rtrim($value, '()'),
                type: CodeReferenceType::UnqualifiedMethod,
                resolutionConfidence: 0.5,
            );
        }

        // Constant: ALL_UPPER_CASE (3+ chars, all uppercase with underscores/digits)
        if (preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $value) === 1) {
            return new self(
                className: '',
                member: $value,
                type: CodeReferenceType::ClassConstant,
                resolutionConfidence: 0.6,
            );
        }

        // Config key: contains dots or slashes
        if (str_contains($value, '.') || str_contains($value, '/')) {
            return new self(
                className: $value,
                member: null,
                type: CodeReferenceType::ConfigKey,
                resolutionConfidence: 0.4,
            );
        }

        // Short class name: starts with uppercase letter
        if (preg_match('/^[A-Z][a-zA-Z0-9]+$/', $value) === 1) {
            return new self(
                className: $value,
                member: null,
                type: CodeReferenceType::ShortClassName,
                resolutionConfidence: 0.7,
            );
        }

        // Anything else: treat as unqualified method/identifier
        return new self(
            className: '',
            member: $value,
            type: CodeReferenceType::UnqualifiedMethod,
            resolutionConfidence: 0.3,
        );
    }
}
