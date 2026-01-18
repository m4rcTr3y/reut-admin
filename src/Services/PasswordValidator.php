<?php
declare(strict_types=1);

namespace Reut\Admin\Services;

/**
 * Password Validator Service
 * Validates password strength according to security policy
 */
class PasswordValidator
{
    private const MIN_LENGTH = 12;
    private const REQUIRE_UPPERCASE = true;
    private const REQUIRE_LOWERCASE = true;
    private const REQUIRE_NUMBER = true;
    private const REQUIRE_SPECIAL = true;

    /**
     * Common weak passwords to check against
     */
    private const COMMON_PASSWORDS = [
        'password', 'password123', 'admin', 'admin123', '12345678',
        'qwerty', 'abc123', 'letmein', 'welcome', 'monkey',
        '1234567890', 'password1', 'admin1234', 'root', 'toor'
    ];

    /**
     * Validate password strength
     * 
     * @param string $password The password to validate
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validate(string $password): array
    {
        $errors = [];

        // Check minimum length
        if (strlen($password) < self::MIN_LENGTH) {
            $errors[] = "Password must be at least " . self::MIN_LENGTH . " characters long";
        }

        // Check for uppercase letter
        if (self::REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }

        // Check for lowercase letter
        if (self::REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }

        // Check for number
        if (self::REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }

        // Check for special character
        if (self::REQUIRE_SPECIAL && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }

        // Check against common passwords
        $passwordLower = strtolower($password);
        foreach (self::COMMON_PASSWORDS as $common) {
            if ($passwordLower === $common || strpos($passwordLower, $common) !== false) {
                $errors[] = "Password is too common or contains common password patterns";
                break;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get password requirements as a human-readable string
     * 
     * @return string
     */
    public function getRequirements(): string
    {
        $requirements = [
            "At least " . self::MIN_LENGTH . " characters long"
        ];

        if (self::REQUIRE_UPPERCASE) {
            $requirements[] = "One uppercase letter";
        }
        if (self::REQUIRE_LOWERCASE) {
            $requirements[] = "One lowercase letter";
        }
        if (self::REQUIRE_NUMBER) {
            $requirements[] = "One number";
        }
        if (self::REQUIRE_SPECIAL) {
            $requirements[] = "One special character";
        }

        return implode(", ", $requirements);
    }
}

