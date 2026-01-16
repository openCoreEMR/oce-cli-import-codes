<?php

/**
 * RealVocabularyImportE2ETest.php
 * End-to-end tests for importing real vocabulary files
 *
 * These tests use actual vocabulary files configured in tests/Fixtures/vocabs.php.
 * They test importing previous, current, and next versions to verify upgrade paths.
 *
 * To run these tests:
 * 1. Copy tests/Fixtures/vocabs.php.example to tests/Fixtures/vocabs.php
 * 2. Configure paths to your vocabulary files
 * 3. Run: task test:e2e
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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * E2E tests for importing real vocabulary files.
 *
 * These tests are skipped if no vocabulary files are configured.
 * Configure your vocab files in tests/Fixtures/vocabs.php
 */
#[Group('e2e')]
#[Group('real-vocabs')]
class RealVocabularyImportE2ETest extends TestCase
{
    private const OPENEMR_PATH = '/var/www/localhost/htdocs/openemr';
    private const SITE = 'default';
    private const VOCABS_CONFIG = '/var/www/localhost/htdocs/openemr/oce-cli-import-codes/tests/Fixtures/vocabs.php';

    private ?OpenEMRConnector $connector = null;

    /** @var array<string, array<string, string>> */
    private static array $vocabConfig = [];

    public static function setUpBeforeClass(): void
    {
        if (file_exists(self::VOCABS_CONFIG)) {
            self::$vocabConfig = require self::VOCABS_CONFIG;
        }
    }

    protected function setUp(): void
    {
        if (!file_exists(self::OPENEMR_PATH . '/interface/globals.php')) {
            $this->markTestSkipped('OpenEMR not available. Run inside Docker: task dev:start');
        }

        $this->connector = new OpenEMRConnector();
        try {
            $this->connector->initialize(self::OPENEMR_PATH, self::SITE);
        } catch (\Exception $e) {
            $this->markTestSkipped('OpenEMR initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Get configured files for a vocabulary type
     *
     * @return array<string, string>
     */
    private function getConfiguredFiles(string $type): array
    {
        $files = self::$vocabConfig[$type] ?? [];
        return array_filter($files, fn($path) => is_string($path) && file_exists($path));
    }

    #[Test]
    public function rxnormImportSucceeds(): void
    {
        $files = $this->getConfiguredFiles('RXNORM');
        if (empty($files)) {
            $this->markTestSkipped('No RXNORM files configured in tests/Fixtures/vocabs.php');
        }

        foreach ($files as $version => $filePath) {
            $this->runVocabImportTest('RXNORM', $version, $filePath, 'rxnconso');
        }
    }

    #[Test]
    public function snomedImportSucceeds(): void
    {
        $files = $this->getConfiguredFiles('SNOMED');
        if (empty($files)) {
            $this->markTestSkipped('No SNOMED files configured in tests/Fixtures/vocabs.php');
        }

        foreach ($files as $version => $filePath) {
            $this->runVocabImportTest('SNOMED', $version, $filePath, 'sct_descriptions');
        }
    }

    #[Test]
    public function icd10ImportSucceeds(): void
    {
        $files = $this->getConfiguredFiles('ICD10');
        if (empty($files)) {
            $this->markTestSkipped('No ICD10 files configured in tests/Fixtures/vocabs.php');
        }

        foreach ($files as $version => $filePath) {
            $this->runVocabImportTest('ICD10', $version, $filePath, 'icd10_dx_order_code');
        }
    }

    #[Test]
    public function icd9ImportSucceeds(): void
    {
        $files = $this->getConfiguredFiles('ICD9');
        if (empty($files)) {
            $this->markTestSkipped('No ICD9 files configured in tests/Fixtures/vocabs.php');
        }

        foreach ($files as $version => $filePath) {
            $this->runVocabImportTest('ICD9', $version, $filePath, 'icd9_dx_code');
        }
    }

    #[Test]
    public function cqmValuesetImportSucceeds(): void
    {
        $files = $this->getConfiguredFiles('CQM_VALUESET');
        if (empty($files)) {
            $this->markTestSkipped('No CQM_VALUESET files configured in tests/Fixtures/vocabs.php');
        }

        foreach ($files as $version => $filePath) {
            $this->runVocabImportTest('CQM_VALUESET', $version, $filePath, 'valueset');
        }
    }

    private function runVocabImportTest(
        string $type,
        string $version,
        string $filePath,
        string $tableName
    ): void {
        // Get count before import
        $beforeCount = $this->getTableCount($tableName);

        // Run the import
        $command = new ImportCodesCommand();
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $startTime = microtime(true);

        $result = $tester->execute([
            'file-path' => $filePath,
            '--openemr-path' => self::OPENEMR_PATH,
            '--site' => self::SITE,
            '--force' => true,
            '--cleanup' => true,
        ]);

        $duration = microtime(true) - $startTime;
        $output = $tester->getDisplay();

        // Assert command succeeded
        $this->assertEquals(
            0,
            $result,
            "Import of {$type} ({$version}) should succeed. Output: $output"
        );
        $this->assertStringContainsString('Import completed successfully', $output);

        // Verify data was inserted
        $afterCount = $this->getTableCount($tableName);
        $this->assertGreaterThan(
            0,
            $afterCount,
            "Table {$tableName} should have entries after {$type} import"
        );

        // Verify tracking table was updated
        $trackingType = ($type === 'SNOMED' || $type === 'SNOMED_RF2') ? 'SNOMED' : $type;
        $tracking = $this->connector->querySql(
            "SELECT * FROM standardized_tables_track WHERE name = ? ORDER BY imported_date DESC LIMIT 1",
            [$trackingType]
        );
        $this->assertNotEmpty($tracking, "Tracking entry should exist for {$type}");

        // Log performance info
        echo sprintf(
            "\n[%s %s] Imported in %.2fs, %d rows in %s\n",
            $type,
            $version,
            $duration,
            $afterCount,
            $tableName
        );
    }

    #[Test]
    public function versionUpgradePathWorks(): void
    {
        // Find a vocab type that has multiple versions configured
        $typeWithVersions = null;
        $versions = [];

        foreach (self::$vocabConfig as $type => $files) {
            $validFiles = array_filter($files, fn($path) => is_string($path) && file_exists($path));
            if (count($validFiles) >= 2) {
                $typeWithVersions = $type;
                $versions = $validFiles;
                break;
            }
        }

        if ($typeWithVersions === null) {
            $this->markTestSkipped(
                'Need at least 2 versions of a vocabulary configured in tests/Fixtures/vocabs.php to test upgrade path'
            );
        }

        $tableName = $this->getTableNameForType($typeWithVersions);

        // Import versions in order
        $command = new ImportCodesCommand();
        $application = new Application();
        $application->add($command);

        $previousCount = 0;
        foreach ($versions as $version => $filePath) {
            $tester = new CommandTester($command);
            $result = $tester->execute([
                'file-path' => $filePath,
                '--openemr-path' => self::OPENEMR_PATH,
                '--site' => self::SITE,
                '--force' => true,
                '--cleanup' => true,
            ]);

            $this->assertEquals(0, $result, "Import of {$typeWithVersions} {$version} should succeed");

            $currentCount = $this->getTableCount($tableName);
            echo sprintf(
                "\n[%s %s] %d rows in %s\n",
                $typeWithVersions,
                $version,
                $currentCount,
                $tableName
            );

            $previousCount = $currentCount;
        }

        // Verify final state has data
        $this->assertGreaterThan(0, $previousCount, 'Table should have data after upgrade path');
    }

    private function getTableNameForType(string $type): string
    {
        return match ($type) {
            'RXNORM' => 'rxnconso',
            'SNOMED', 'SNOMED_RF2' => 'sct_descriptions',
            'ICD10' => 'icd10_dx_order_code',
            'ICD9' => 'icd9_dx_code',
            'CQM_VALUESET' => 'valueset',
            default => throw new \InvalidArgumentException("Unknown type: $type"),
        };
    }

    private function getTableCount(string $tableName): int
    {
        try {
            $result = $this->connector->querySql("SELECT COUNT(*) as cnt FROM `{$tableName}`");
            return (int) ($result['cnt'] ?? 0);
        } catch (\Exception) {
            return 0;
        }
    }
}
