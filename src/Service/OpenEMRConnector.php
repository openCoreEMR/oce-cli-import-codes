<?php

/**
 * OpenEMRConnector.php
 * Service for connecting to and initializing OpenEMR environment
 *
 * @package   OpenCoreEMR\CLI\ImportCodes
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Service;

class OpenEMRConnector
{
    private string $openemrPath;
    private string $site;
    private bool $initialized = false;

    /**
     * Initialize connection to OpenEMR
     */
    public function initialize(string $openemrPath, string $site = 'default'): void
    {
        $this->openemrPath = rtrim($openemrPath, '/');
        $this->site = $site;

        // Validate OpenEMR installation
        $globalsPath = $this->openemrPath . '/interface/globals.php';
        if (!file_exists($globalsPath)) {
            throw new \Exception("OpenEMR globals.php not found at: $globalsPath");
        }

        $standardTablesPath = $this->openemrPath . '/library/standard_tables_capture.inc.php';
        if (!file_exists($standardTablesPath)) {
            throw new \Exception("OpenEMR standard_tables_capture.inc.php not found at: $standardTablesPath");
        }

        // Set up environment for CLI execution
        $_GET['site'] = $site;
        $ignoreAuth = true;
        $sessionAllowWrite = true;

        // Include OpenEMR environment
        require_once $globalsPath;
        require_once $standardTablesPath;

        // Verify database connection
        if (!isset($GLOBALS['dbase']) || empty($GLOBALS['dbase'])) {
            throw new \Exception("OpenEMR database configuration not found");
        }

        $this->initialized = true;
    }

    /**
     * Check if connector is initialized
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * Get OpenEMR path
     */
    public function getOpenEMRPath(): string
    {
        return $this->openemrPath;
    }

    /**
     * Get site name
     */
    public function getSite(): string
    {
        return $this->site;
    }

    /**
     * Get temporary files directory from OpenEMR globals
     */
    public function getTempDir(): string
    {
        if (!$this->initialized) {
            throw new \Exception("OpenEMR connector not initialized");
        }

        return $GLOBALS['temporary_files_dir'] ?? sys_get_temp_dir();
    }

    /**
     * Execute SQL statement using OpenEMR's database functions
     */
    public function executeSql(string $sql, array $params = []): mixed
    {
        if (!$this->initialized) {
            throw new \Exception("OpenEMR connector not initialized");
        }

        if (function_exists('sqlStatement')) {
            return sqlStatement($sql, $params);
        }

        throw new \Exception("OpenEMR database functions not available");
    }

    /**
     * Execute SQL query using OpenEMR's database functions
     */
    public function querySql(string $sql, array $params = []): mixed
    {
        if (!$this->initialized) {
            throw new \Exception("OpenEMR connector not initialized");
        }

        if (function_exists('sqlQuery')) {
            return sqlQuery($sql, $params);
        }

        throw new \Exception("OpenEMR database functions not available");
    }
}