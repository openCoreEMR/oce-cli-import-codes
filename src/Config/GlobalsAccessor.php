<?php

/**
 * GlobalsAccessor.php
 * Centralized accessor for OpenEMR $GLOBALS with type-safe getters
 *
 * @package   OpenCoreEMR\CLI\ImportCodes
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Config;

class GlobalsAccessor implements ConfigAccessorInterface
{
    /**
     * @inheritDoc
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $GLOBALS[$key] ?? $default;
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        return isset($GLOBALS[$key]);
    }

    /**
     * @inheritDoc
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        if (is_string($value)) {
            return $value;
        }
        return is_scalar($value) || $value === null ? (string) $value : $default;
    }

    /**
     * @inheritDoc
     */
    public function getBoolean(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @inheritDoc
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        if (is_int($value)) {
            return $value;
        }
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Get all globals (use sparingly)
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $GLOBALS;
    }
}
