<?php

/**
 * ConfigAccessorInterface.php
 * Interface for accessing configuration values with type safety
 *
 * @package   OpenCoreEMR\CLI\ImportCodes
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Config;

interface ConfigAccessorInterface
{
    /**
     * Get a configuration value
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Check if a configuration key exists
     */
    public function has(string $key): bool;

    /**
     * Get a configuration value as a string
     */
    public function getString(string $key, string $default = ''): string;

    /**
     * Get a configuration value as a boolean
     */
    public function getBoolean(string $key, bool $default = false): bool;

    /**
     * Get a configuration value as an integer
     */
    public function getInt(string $key, int $default = 0): int;
}
