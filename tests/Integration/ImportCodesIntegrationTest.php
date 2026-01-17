<?php

/**
 * ImportCodesIntegrationTest.php
 * Integration tests for the import codes CLI tool
 *
 * These tests require a running OpenEMR Docker environment.
 * Run with: task dev:start && composer test:integration
 *
 * @package   OpenCoreEMR\CLI\ImportCodes\Tests
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Tests\Integration;

use OpenCoreEMR\CLI\ImportCodes\Command\ImportCodesCommand;
use OpenCoreEMR\CLI\ImportCodes\Config\GlobalsAccessor;
use OpenCoreEMR\CLI\ImportCodes\Service\CodeImporter;
use OpenCoreEMR\CLI\ImportCodes\Service\MetadataDetector;
use OpenCoreEMR\CLI\ImportCodes\Service\OpenEMRConnector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests for the import codes CLI tool.
 *
 * These tests require a running OpenEMR Docker environment.
 * They are marked with @group integration and will be skipped in CI.
 */
#[Group('integration')]
#[RequiresPhpExtension('zip')]
class ImportCodesIntegrationTest extends TestCase
{
    private const OPENEMR_PATH = '/var/www/localhost/htdocs/openemr';
    private const SITE = 'default';

    private ?OpenEMRConnector $connector = null;
    private bool $openemrAvailable = false;

    protected function setUp(): void
    {
        // Check if we're running inside Docker with OpenEMR available
        if (!file_exists(self::OPENEMR_PATH . '/interface/globals.php')) {
            $this->markTestSkipped(
                'OpenEMR not available at ' . self::OPENEMR_PATH . '. ' .
                'Run these tests inside the Docker container: task dev:start'
            );
        }

        // Try to initialize the connector
        $this->connector = new OpenEMRConnector(new GlobalsAccessor());
        try {
            $this->connector->initialize(self::OPENEMR_PATH, self::SITE);
            $this->openemrAvailable = true;
        } catch (\Throwable $e) {
            $this->markTestSkipped('OpenEMR initialization failed: ' . $e->getMessage());
        }
    }

    #[Test]
    public function openEmrConnectorCanInitialize(): void
    {
        $this->assertTrue($this->connector->isInitialized());
        $this->assertEquals(self::OPENEMR_PATH, $this->connector->getOpenEMRPath());
        $this->assertEquals(self::SITE, $this->connector->getSite());
    }

    #[Test]
    public function openEmrConnectorCanQueryDatabase(): void
    {
        $result = $this->connector->querySql('SELECT 1 as test');

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['test']);
    }

    #[Test]
    public function openEmrConnectorCanGetTempDir(): void
    {
        $tempDir = $this->connector->getTempDir();

        $this->assertNotEmpty($tempDir);
        $this->assertTrue(is_dir($tempDir) || is_writable(dirname($tempDir)));
    }

    #[Test]
    public function codeImporterCanCheckIfVocabularyLoaded(): void
    {
        $importer = new CodeImporter(new GlobalsAccessor());

        // Should not throw exception when database is available
        $result = $importer->isVocabularyLoaded('RXNORM');

        $this->assertIsBool($result);
    }

    #[Test]
    public function codeImporterCanCheckIfAlreadyLoaded(): void
    {
        $importer = new CodeImporter(new GlobalsAccessor());

        // Check with fake data - should return false
        $result = $importer->isAlreadyLoaded(
            'RXNORM',
            '2099-12-31',
            'TestVersion',
            'fake_checksum_abc123'
        );

        $this->assertFalse($result);
    }

    #[Test]
    public function metadataDetectorIntegrationWithRealFilenames(): void
    {
        $detector = new MetadataDetector();

        // Test detection with realistic filenames (no actual file needed for detection)
        $this->assertEquals('RXNORM', $detector->detectCodeType('RxNorm_full_01012024.zip'));
        $this->assertEquals('SNOMED_RF2', $detector->detectCodeType('SnomedCT_USEditionRF2_PRODUCTION_20240301T120000Z.zip'));
        $this->assertEquals('CQM_VALUESET', $detector->detectCodeType('ec_only_cms_20240101.xml.zip'));
        $this->assertEquals('ICD10', $detector->detectCodeType('icd10cm_order_2024.txt.zip'));
    }

    #[Test]
    public function commandDryRunWorksWithOpenEmr(): void
    {
        // Create a test file with valid name
        $tempFile = sys_get_temp_dir() . '/RxNorm_full_01152024.zip';
        file_put_contents($tempFile, 'mock zip content');

        try {
            $command = new ImportCodesCommand();
            $application = new Application();
            $application->add($command);

            $tester = new CommandTester($command);
            $result = $tester->execute([
                'file-path' => $tempFile,
                '--openemr-path' => self::OPENEMR_PATH,
                '--site' => self::SITE,
                '--dry-run' => true,
            ]);

            $output = $tester->getDisplay();

            // Should succeed with dry-run (no actual import)
            $this->assertEquals(0, $result, 'Command should succeed in dry-run mode. Output: ' . $output);
            $this->assertStringContainsString('DRY RUN MODE', $output);
            $this->assertStringContainsString('Import completed successfully', $output);
        } finally {
            unlink($tempFile);
        }
    }

    #[Test]
    public function commandSkipsAlreadyLoadedVocabulary(): void
    {
        // This test verifies the skip logic when vocabulary appears already loaded
        // First, we need to insert a tracking record, then verify it's detected

        // Create a test file
        $tempFile = sys_get_temp_dir() . '/RxNorm_full_12312099.zip';
        file_put_contents($tempFile, 'test content for checksum');

        $checksum = md5_file($tempFile);

        try {
            // Insert a fake tracking record
            $this->connector->executeSql(
                "INSERT INTO `standardized_tables_track` " .
                "(`name`, `revision_date`, `revision_version`, `file_checksum`) " .
                "VALUES (?, ?, ?, ?)",
                ['RXNORM', '2099-12-31', 'Standard', $checksum]
            );

            $command = new ImportCodesCommand();
            $application = new Application();
            $application->add($command);

            $tester = new CommandTester($command);
            $result = $tester->execute([
                'file-path' => $tempFile,
                '--openemr-path' => self::OPENEMR_PATH,
                '--site' => self::SITE,
            ]);

            $output = $tester->getDisplay();

            // Should succeed but skip the import
            $this->assertEquals(0, $result);
            $this->assertStringContainsString('already loaded', $output);
        } finally {
            // Cleanup the test record
            $this->connector->executeSql(
                "DELETE FROM `standardized_tables_track` WHERE `name` = ? AND `revision_date` = ?",
                ['RXNORM', '2099-12-31']
            );
            unlink($tempFile);
        }
    }

    #[Test]
    public function commandForceIgnoresAlreadyLoaded(): void
    {
        // This test verifies --force bypasses the already-loaded check
        $tempFile = sys_get_temp_dir() . '/RxNorm_full_12312098.zip';
        file_put_contents($tempFile, 'test content');

        $checksum = md5_file($tempFile);

        try {
            // Insert a fake tracking record
            $this->connector->executeSql(
                "INSERT INTO `standardized_tables_track` " .
                "(`name`, `revision_date`, `revision_version`, `file_checksum`) " .
                "VALUES (?, ?, ?, ?)",
                ['RXNORM', '2098-12-31', 'Standard', $checksum]
            );

            $command = new ImportCodesCommand();
            $application = new Application();
            $application->add($command);

            $tester = new CommandTester($command);

            // Use --force with --dry-run to test the bypass without actual import
            $result = $tester->execute([
                'file-path' => $tempFile,
                '--openemr-path' => self::OPENEMR_PATH,
                '--site' => self::SITE,
                '--force' => true,
                '--dry-run' => true,
            ]);

            $output = $tester->getDisplay();

            // Should proceed with import (not skip)
            $this->assertEquals(0, $result);
            $this->assertStringNotContainsString('already loaded', $output);
            $this->assertStringContainsString('force_import', $output);
        } finally {
            // Cleanup the test record
            $this->connector->executeSql(
                "DELETE FROM `standardized_tables_track` WHERE `name` = ? AND `revision_date` = ?",
                ['RXNORM', '2098-12-31']
            );
            unlink($tempFile);
        }
    }
}
