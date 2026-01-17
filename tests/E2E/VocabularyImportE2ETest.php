<?php

/**
 * VocabularyImportE2ETest.php
 * End-to-end tests that actually import vocabularies and verify database state
 *
 * These tests require a running OpenEMR Docker environment and use real
 * (minimal) vocabulary fixtures to test the complete import workflow.
 *
 * @package   OpenCoreEMR\CLI\ImportCodes\Tests
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Tests\E2E;

use OpenCoreEMR\CLI\ImportCodes\Command\ImportCodesCommand;
use OpenCoreEMR\CLI\ImportCodes\Service\OpenEMRConnector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end tests that import actual vocabulary data and verify database state.
 *
 * These tests:
 * - Import minimal vocabulary fixtures
 * - Verify data is correctly inserted into the database
 * - Verify tracking table is updated
 * - Test cleanup functionality
 */
#[Group('e2e')]
#[RequiresPhpExtension('zip')]
class VocabularyImportE2ETest extends TestCase
{
    private const OPENEMR_PATH = '/var/www/localhost/htdocs/openemr';
    private const SITE = 'default';
    private const FIXTURES_DIR = '/var/www/localhost/htdocs/openemr/oce-cli-import-codes/tests/Fixtures';

    private ?OpenEMRConnector $connector = null;

    protected function setUp(): void
    {
        if (!file_exists(self::OPENEMR_PATH . '/interface/globals.php')) {
            $this->markTestSkipped(
                'OpenEMR not available. Run these tests inside Docker: task dev:start'
            );
        }

        $this->connector = new OpenEMRConnector();
        try {
            $this->connector->initialize(self::OPENEMR_PATH, self::SITE);
        } catch (\Throwable $e) {
            $this->markTestSkipped('OpenEMR initialization failed: ' . $e->getMessage());
        }

        // Clean up any test data from previous runs
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        // Always clean up test data after tests
        if ($this->connector !== null && $this->connector->isInitialized()) {
            $this->cleanupTestData();
        }
    }

    private function cleanupTestData(): void
    {
        // Remove test valueset entries (NQF codes 0001, 0002 are our test fixtures)
        $this->connector->executeSql("DELETE FROM valueset WHERE nqf_code IN ('0001', '0002')");
        $this->connector->executeSql("DELETE FROM valueset_oid WHERE nqf_code IN ('0001', '0002')");

        // Remove test tracking entries
        $this->connector->executeSql(
            "DELETE FROM standardized_tables_track WHERE name = 'CQM_VALUESET' AND revision_date = '2024-01-01'"
        );
    }

    #[Test]
    public function cqmValuesetImportInsertsDataIntoDatabase(): void
    {
        $fixtureFile = self::FIXTURES_DIR . '/ec_test_cms_20240101.xml.zip';

        if (!file_exists($fixtureFile)) {
            $this->markTestSkipped("Fixture file not found: $fixtureFile");
        }

        // Verify database is empty before import
        $beforeCount = $this->getValuesetCount();

        // Run the import
        $command = new ImportCodesCommand();
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $result = $tester->execute([
            'file-path' => $fixtureFile,
            '--openemr-path' => self::OPENEMR_PATH,
            '--site' => self::SITE,
            '--cleanup' => true,
        ]);

        $output = $tester->getDisplay();

        // Assert command succeeded
        $this->assertEquals(0, $result, "Import should succeed. Output: $output");
        $this->assertStringContainsString('Import completed successfully', $output);

        // Verify data was inserted
        $afterCount = $this->getValuesetCount();
        $this->assertGreaterThan($beforeCount, $afterCount, 'Valueset table should have new entries');

        // Verify specific test data exists
        $testEntry = $this->connector->querySql(
            "SELECT * FROM valueset WHERE nqf_code = '0001' AND code = '99201'"
        );
        $this->assertNotEmpty($testEntry, 'Test valueset entry should exist');
        $this->assertEquals('CPT', $testEntry['code_type']);
        $this->assertEquals('Office Visit Level 1', $testEntry['description']);
    }

    #[Test]
    public function cqmValuesetImportUpdatesTrackingTable(): void
    {
        $fixtureFile = self::FIXTURES_DIR . '/ec_test_cms_20240101.xml.zip';

        if (!file_exists($fixtureFile)) {
            $this->markTestSkipped("Fixture file not found: $fixtureFile");
        }

        // Run the import
        $command = new ImportCodesCommand();
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $result = $tester->execute([
            'file-path' => $fixtureFile,
            '--openemr-path' => self::OPENEMR_PATH,
            '--site' => self::SITE,
            '--cleanup' => true,
        ]);

        $this->assertEquals(0, $result);

        // Verify tracking table was updated
        $tracking = $this->connector->querySql(
            "SELECT * FROM standardized_tables_track WHERE name = 'CQM_VALUESET' AND revision_date = '2024-01-01'"
        );

        $this->assertNotEmpty($tracking, 'Tracking entry should exist');
        $this->assertEquals('Standard', $tracking['revision_version']);
        $this->assertNotEmpty($tracking['file_checksum']);
    }

    #[Test]
    public function importSkipsWhenAlreadyLoaded(): void
    {
        $fixtureFile = self::FIXTURES_DIR . '/ec_test_cms_20240101.xml.zip';

        if (!file_exists($fixtureFile)) {
            $this->markTestSkipped("Fixture file not found: $fixtureFile");
        }

        $command = new ImportCodesCommand();
        $application = new Application();
        $application->add($command);

        // First import
        $tester = new CommandTester($command);
        $result1 = $tester->execute([
            'file-path' => $fixtureFile,
            '--openemr-path' => self::OPENEMR_PATH,
            '--site' => self::SITE,
            '--cleanup' => true,
        ]);
        $this->assertEquals(0, $result1);

        $countAfterFirst = $this->getValuesetCount();

        // Second import (should be skipped)
        $tester2 = new CommandTester($command);
        $result2 = $tester2->execute([
            'file-path' => $fixtureFile,
            '--openemr-path' => self::OPENEMR_PATH,
            '--site' => self::SITE,
        ]);

        $output2 = $tester2->getDisplay();

        $this->assertEquals(0, $result2);
        $this->assertStringContainsString('already loaded', $output2);

        // Count should be the same (no duplicate inserts)
        $countAfterSecond = $this->getValuesetCount();
        $this->assertEquals($countAfterFirst, $countAfterSecond, 'No new entries should be added on skip');
    }

    #[Test]
    public function forceImportReimportsData(): void
    {
        $fixtureFile = self::FIXTURES_DIR . '/ec_test_cms_20240101.xml.zip';

        if (!file_exists($fixtureFile)) {
            $this->markTestSkipped("Fixture file not found: $fixtureFile");
        }

        $command = new ImportCodesCommand();
        $application = new Application();
        $application->add($command);

        // First import
        $tester = new CommandTester($command);
        $tester->execute([
            'file-path' => $fixtureFile,
            '--openemr-path' => self::OPENEMR_PATH,
            '--site' => self::SITE,
            '--cleanup' => true,
        ]);

        // Force import (should not skip)
        $tester2 = new CommandTester($command);
        $result2 = $tester2->execute([
            'file-path' => $fixtureFile,
            '--openemr-path' => self::OPENEMR_PATH,
            '--site' => self::SITE,
            '--force' => true,
            '--cleanup' => true,
        ]);

        $output2 = $tester2->getDisplay();

        $this->assertEquals(0, $result2);
        $this->assertStringNotContainsString('already loaded', $output2);
        $this->assertStringContainsString('Import completed successfully', $output2);
    }

    #[Test]
    public function cleanupRemovesTemporaryFiles(): void
    {
        $fixtureFile = self::FIXTURES_DIR . '/ec_test_cms_20240101.xml.zip';

        if (!file_exists($fixtureFile)) {
            $this->markTestSkipped("Fixture file not found: $fixtureFile");
        }

        $command = new ImportCodesCommand();
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $result = $tester->execute([
            'file-path' => $fixtureFile,
            '--openemr-path' => self::OPENEMR_PATH,
            '--site' => self::SITE,
            '--cleanup' => true,
            '--force' => true,
        ]);

        $this->assertEquals(0, $result);

        // Verify temp directory was cleaned up
        $tempDir = $GLOBALS['temporary_files_dir'] . '/CQM_VALUESET';
        $this->assertDirectoryDoesNotExist($tempDir, 'Temp directory should be removed after cleanup');
    }

    #[Test]
    public function importedDataHasCorrectCodeTypes(): void
    {
        $fixtureFile = self::FIXTURES_DIR . '/ec_test_cms_20240101.xml.zip';

        if (!file_exists($fixtureFile)) {
            $this->markTestSkipped("Fixture file not found: $fixtureFile");
        }

        $command = new ImportCodesCommand();
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $result = $tester->execute([
            'file-path' => $fixtureFile,
            '--openemr-path' => self::OPENEMR_PATH,
            '--site' => self::SITE,
            '--cleanup' => true,
        ]);

        $this->assertEquals(0, $result);

        // Verify CPT codes
        $cptEntry = $this->connector->querySql(
            "SELECT * FROM valueset WHERE nqf_code = '0001' AND code = '99201'"
        );
        $this->assertEquals('CPT', $cptEntry['code_type']);

        // Verify ICD10 codes (ICD10CM gets normalized to ICD10)
        $icd10Entry = $this->connector->querySql(
            "SELECT * FROM valueset WHERE nqf_code = '0002' AND code = 'E11.9'"
        );
        $this->assertEquals('ICD10', $icd10Entry['code_type']);

        // Verify ICD9 codes (ICD9CM gets normalized to ICD9)
        $icd9Entry = $this->connector->querySql(
            "SELECT * FROM valueset WHERE nqf_code = '0002' AND code = '250.00'"
        );
        $this->assertEquals('ICD9', $icd9Entry['code_type']);
    }

    #[Test]
    public function valuesetOidTableIsPopulated(): void
    {
        $fixtureFile = self::FIXTURES_DIR . '/ec_test_cms_20240101.xml.zip';

        if (!file_exists($fixtureFile)) {
            $this->markTestSkipped("Fixture file not found: $fixtureFile");
        }

        $command = new ImportCodesCommand();
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $result = $tester->execute([
            'file-path' => $fixtureFile,
            '--openemr-path' => self::OPENEMR_PATH,
            '--site' => self::SITE,
            '--cleanup' => true,
        ]);

        $this->assertEquals(0, $result);

        // Verify valueset_oid table has entries
        $oidEntry = $this->connector->querySql(
            "SELECT * FROM valueset_oid WHERE nqf_code = '0001'"
        );
        $this->assertNotEmpty($oidEntry, 'valueset_oid should have entries');
        $this->assertStringContainsString('2.16.840.1.113883', $oidEntry['valueset']);
    }

    private function getValuesetCount(): int
    {
        $result = $this->connector->querySql("SELECT COUNT(*) as cnt FROM valueset WHERE nqf_code IN ('0001', '0002')");
        return (int) ($result['cnt'] ?? 0);
    }
}
