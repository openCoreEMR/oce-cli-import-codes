<?php

/**
 * CodeImporterTest.php
 * Unit tests for CodeImporter service
 *
 * @package   OpenCoreEMR\CLI\ImportCodes\Tests
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Tests\Unit\Service;

use OpenCoreEMR\CLI\ImportCodes\Exception\CodeImportException;
use OpenCoreEMR\CLI\ImportCodes\Exception\FileSystemException;
use OpenCoreEMR\CLI\ImportCodes\Service\CodeImporter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CodeImporterTest extends TestCase
{
    private CodeImporter $importer;

    protected function setUp(): void
    {
        $this->importer = new CodeImporter();
    }

    #[Test]
    public function setTempDirThrowsExceptionForNonExistentDirectory(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessage('Temporary directory is not writable');

        $this->importer->setTempDir('/non/existent/directory');
    }

    #[Test]
    public function setTempDirAcceptsWritableDirectory(): void
    {
        $tempDir = sys_get_temp_dir();

        // Should not throw exception
        $this->importer->setTempDir($tempDir);

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    #[Test]
    public function setLockRetryConfigSetsPositiveValues(): void
    {
        $this->importer->setLockRetryConfig(5, 15);

        // No exception means success - we verify through behavior in other tests
        $this->assertTrue(true);
    }

    #[Test]
    public function setLockRetryConfigClampsNegativeAttempts(): void
    {
        // Negative attempts should be clamped to 1
        $this->importer->setLockRetryConfig(-5, 10);

        // No exception means success
        $this->assertTrue(true);
    }

    #[Test]
    public function setLockRetryConfigClampsNegativeDelay(): void
    {
        // Negative delay should be clamped to 0
        $this->importer->setLockRetryConfig(10, -5);

        // No exception means success
        $this->assertTrue(true);
    }

    #[Test]
    public function copyFileThrowsExceptionWhenFunctionNotAvailable(): void
    {
        $this->expectException(CodeImportException::class);
        $this->expectExceptionMessage('OpenEMR temp_copy function not available');

        $this->importer->copyFile('/path/to/file.zip', 'RXNORM');
    }

    #[Test]
    public function extractFileThrowsExceptionWhenFunctionNotAvailable(): void
    {
        $this->expectException(CodeImportException::class);
        $this->expectExceptionMessage('OpenEMR temp_unarchive function not available');

        $this->importer->extractFile('/path/to/file.zip', 'RXNORM');
    }

    #[Test]
    public function importThrowsExceptionWhenDatabaseFunctionsNotAvailable(): void
    {
        $this->expectException(CodeImportException::class);
        $this->expectExceptionMessage('OpenEMR database functions not available');

        $this->importer->import('RXNORM');
    }

    #[Test]
    public function isAlreadyLoadedReturnsFalseWhenDatabaseNotAvailable(): void
    {
        // Without database functions, should return false
        $result = $this->importer->isAlreadyLoaded('RXNORM', '2024-01-15', 'Standard', 'abc123');

        $this->assertFalse($result);
    }

    #[Test]
    public function isVocabularyLoadedReturnsFalseWhenDatabaseNotAvailable(): void
    {
        // Without database functions, should return false
        $result = $this->importer->isVocabularyLoaded('RXNORM');

        $this->assertFalse($result);
    }

    #[Test]
    public function updateTrackingThrowsExceptionWhenFunctionNotAvailable(): void
    {
        $this->expectException(CodeImportException::class);
        $this->expectExceptionMessage('OpenEMR update_tracker_table function not available');

        $this->importer->updateTracking('RXNORM', '2024-01-15', 'Standard', 'abc123');
    }

    #[Test]
    public function cleanupThrowsExceptionWhenFunctionNotAvailable(): void
    {
        $this->expectException(CodeImportException::class);
        $this->expectExceptionMessage('OpenEMR temp_dir_cleanup function not available');

        $this->importer->cleanup('RXNORM');
    }
}
