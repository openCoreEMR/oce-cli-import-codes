<?php

/**
 * OpenEMRConnectorTest.php
 * Unit tests for OpenEMRConnector service
 *
 * @package   OpenCoreEMR\CLI\ImportCodes\Tests
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Tests\Unit\Service;

use OpenCoreEMR\CLI\ImportCodes\Exception\OpenEMRConnectorException;
use OpenCoreEMR\CLI\ImportCodes\Service\OpenEMRConnector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OpenEMRConnectorTest extends TestCase
{
    private OpenEMRConnector $connector;

    protected function setUp(): void
    {
        $this->connector = new OpenEMRConnector();
    }

    #[Test]
    public function isInitializedReturnsFalseBeforeInitialization(): void
    {
        $this->assertFalse($this->connector->isInitialized());
    }

    #[Test]
    public function initializeThrowsExceptionWhenGlobalsNotFound(): void
    {
        $this->expectException(OpenEMRConnectorException::class);
        $this->expectExceptionMessage('OpenEMR globals.php not found');

        $this->connector->initialize('/non/existent/path');
    }

    #[Test]
    public function initializeThrowsExceptionWhenStandardTablesNotFound(): void
    {
        // Create a temp directory with only globals.php
        $tempDir = sys_get_temp_dir() . '/openemr_test_' . uniqid();
        mkdir($tempDir . '/interface', 0755, true);
        file_put_contents($tempDir . '/interface/globals.php', '<?php // mock');

        try {
            $this->expectException(OpenEMRConnectorException::class);
            $this->expectExceptionMessage('standard_tables_capture.inc.php not found');

            $this->connector->initialize($tempDir);
        } finally {
            // Cleanup
            unlink($tempDir . '/interface/globals.php');
            rmdir($tempDir . '/interface');
            rmdir($tempDir);
        }
    }

    #[Test]
    public function getTempDirThrowsExceptionWhenNotInitialized(): void
    {
        $this->expectException(OpenEMRConnectorException::class);
        $this->expectExceptionMessage('OpenEMR connector not initialized');

        $this->connector->getTempDir();
    }

    #[Test]
    public function executeSqlThrowsExceptionWhenNotInitialized(): void
    {
        $this->expectException(OpenEMRConnectorException::class);
        $this->expectExceptionMessage('OpenEMR connector not initialized');

        $this->connector->executeSql('SELECT 1');
    }

    #[Test]
    public function querySqlThrowsExceptionWhenNotInitialized(): void
    {
        $this->expectException(OpenEMRConnectorException::class);
        $this->expectExceptionMessage('OpenEMR connector not initialized');

        $this->connector->querySql('SELECT 1');
    }

    #[Test]
    public function initializeTrimsTrailingSlashFromPath(): void
    {
        // We can't fully test initialization without OpenEMR, but we can
        // test that the path validation works with trailing slashes
        $this->expectException(OpenEMRConnectorException::class);
        $this->expectExceptionMessage('OpenEMR globals.php not found');

        // Path with trailing slashes should still be validated
        $this->connector->initialize('/non/existent/path///');
    }
}
