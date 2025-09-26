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

use OpenCoreEMR\CLI\ImportCodes\Exception\OpenEMRConnectorException;

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
            throw new OpenEMRConnectorException("OpenEMR globals.php not found at: $globalsPath");
        }

        $standardTablesPath = $this->openemrPath . '/library/standard_tables_capture.inc.php';
        if (!file_exists($standardTablesPath)) {
            throw new OpenEMRConnectorException("OpenEMR standard_tables_capture.inc.php not found at: $standardTablesPath");
        }

        // Set up environment for CLI execution
        $_GET['site'] = $site;
        $ignoreAuth = true;
        $sessionAllowWrite = true;

        // Include OpenEMR environment
        require_once $globalsPath;
        require_once $standardTablesPath;

        // Verify database connection (using OpenEMR's own validation method)
        if (!isset($GLOBALS['dbh']) || !$GLOBALS['dbh']) {
            throw new OpenEMRConnectorException("OpenEMR database connection failed - check database configuration and ensure MySQL is running");
        }

        // Verify ADODB connection is working
        if (!isset($GLOBALS['adodb']['db']) || !$GLOBALS['adodb']['db']) {
            throw new OpenEMRConnectorException("OpenEMR ADODB database connection not established");
        }

        // Test connection with a simple query
        try {
            if (function_exists('sqlQuery')) {
                sqlQuery("SELECT 1");
            } else {
                throw new OpenEMRConnectorException("OpenEMR database functions not available");
            }
        } catch (\Exception $e) {
            throw new OpenEMRConnectorException("OpenEMR database connection test failed: " . $e->getMessage());
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
            throw new OpenEMRConnectorException("OpenEMR connector not initialized");
        }

        return $GLOBALS['temporary_files_dir'] ?? sys_get_temp_dir();
    }

    /**
     * Execute SQL statement using OpenEMR's database functions
     */
    public function executeSql(string $sql, array $params = []): mixed
    {
        if (!$this->initialized) {
            throw new OpenEMRConnectorException("OpenEMR connector not initialized");
        }

        if (function_exists('sqlStatement')) {
            return sqlStatement($sql, $params);
        }

        throw new OpenEMRConnectorException("OpenEMR database functions not available");
    }

    /**
     * Execute SQL query using OpenEMR's database functions
     */
    public function querySql(string $sql, array $params = []): mixed
    {
        if (!$this->initialized) {
            throw new OpenEMRConnectorException("OpenEMR connector not initialized");
        }

        if (function_exists('sqlQuery')) {
            return sqlQuery($sql, $params);
        }

        throw new OpenEMRConnectorException("OpenEMR database functions not available");
    }
}
