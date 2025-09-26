<?php

/**
 * CodeImporter.php
 * Service for importing standardized code tables
 *
 * @package   OpenCoreEMR\CLI\ImportCodes
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Service;

use OpenCoreEMR\CLI\ImportCodes\Exception\CodeImportException;
use OpenCoreEMR\CLI\ImportCodes\Exception\FileSystemException;
use OpenCoreEMR\CLI\ImportCodes\Exception\DatabaseLockException;

class CodeImporter
{
    private ?string $customTempDir = null;
    private ?string $currentLockName = null;
    private int $lockRetryAttempts = 10;
    private int $lockRetryDelaySeconds = 30;
    private bool $waitedForLock = false;

    /**
     * Set custom temporary directory
     */
    public function setTempDir(string $tempDir): void
    {
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            throw new FileSystemException("Temporary directory is not writable: $tempDir");
        }
        $this->customTempDir = $tempDir;
    }

    /**
     * Configure lock retry behavior
     */
    public function setLockRetryConfig(int $attempts = 10, int $delaySeconds = 30): void
    {
        $this->lockRetryAttempts = max(1, $attempts);
        $this->lockRetryDelaySeconds = max(0, $delaySeconds);
    }

    /**
     * Get temporary directory to use
     */
    private function getTempDir(): string
    {
        if ($this->customTempDir) {
            return $this->customTempDir;
        }

        if (isset($GLOBALS['temporary_files_dir'])) {
            return $GLOBALS['temporary_files_dir'];
        }

        return sys_get_temp_dir();
    }

    /**
     * Copy file to temporary directory
     */
    public function copyFile(string $filePath, string $codeType): void
    {
        if (!function_exists('temp_copy')) {
            throw new CodeImportException("OpenEMR temp_copy function not available");
        }

        if (!temp_copy($filePath, $codeType)) {
            throw new FileSystemException("Failed to copy file to temporary directory");
        }
    }

    /**
     * Extract archive file
     */
    public function extractFile(string $filePath, string $codeType): void
    {
        if (!function_exists('temp_unarchive')) {
            throw new CodeImportException("OpenEMR temp_unarchive function not available");
        }

        if (!temp_unarchive($filePath, $codeType)) {
            throw new FileSystemException("Failed to extract archive file");
        }
    }

    /**
     * Import codes based on type
     */
    public function import(string $codeType, bool $isWindows = false, bool $usExtension = false, string $filePath = ''): void
    {
        // Auto-detect RF2 for SNOMED based on filename
        if ($codeType === 'SNOMED' && $this->isRF2File($filePath)) {
            $codeType = 'SNOMED_RF2';
        }

        // Acquire database lock for this code type
        $this->acquireLock($codeType);

        try {
            // If we waited for the lock, check if vocabulary was already imported
            if ($this->waitedForLock && $this->isVocabularyLoaded($codeType)) {
                echo "Vocabulary {$codeType} was already imported by another process. Skipping import.\n";
                return;
            }

            switch ($codeType) {
                case 'RXNORM':
                    $this->importRxnorm($isWindows);
                    break;

                case 'SNOMED':
                    $this->importSnomed($usExtension);
                    break;

                case 'SNOMED_RF2':
                    $this->importSnomedRF2();
                    break;

                case 'ICD9':
                case 'ICD10':
                    $this->importIcd($codeType);
                    break;

                case 'CQM_VALUESET':
                    $this->importValueset($codeType);
                    break;

                default:
                    throw new CodeImportException("Unsupported code type: $codeType");
            }
        } finally {
            // Always release the lock, even if import fails
            $this->releaseLock();
        }
    }

    /**
     * Check if SNOMED file is RF2 format based on filename
     */
    private function isRF2File(string $filePath): bool
    {
        $fileName = basename($filePath);

        // RF2 patterns from OpenEMR's list_staged.php
        $rf2Patterns = [
            "/SnomedCT_InternationalRF2_PRODUCTION_([0-9]{8})[0-9a-zA-Z]{8}.zip/",
            "/SnomedCT_ManagedServiceIE_PRODUCTION_IE1000220_([0-9]{8})[0-9a-zA-Z]{8}.zip/",
            "/SnomedCT_USEditionRF2_PRODUCTION_([0-9]{8})[0-9a-zA-Z]{8}.zip/",
            "/SnomedCT_ManagedServiceUS_PRODUCTION_US[0-9]{7}_([0-9a-zA-Z]{8})T[0-9Z]{7}.zip/",
            "/SnomedCT_SpanishRelease-es_PRODUCTION_([0-9]{8})[0-9a-zA-Z]{8}.zip/",
        ];

        foreach ($rf2Patterns as $pattern) {
            if (preg_match($pattern, $fileName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Import RXNORM data
     */
    private function importRxnorm(bool $isWindows): void
    {
        if (!function_exists('rxnorm_import')) {
            throw new CodeImportException("OpenEMR rxnorm_import function not available");
        }

        if (!rxnorm_import($isWindows)) {
            throw new CodeImportException("RXNORM import failed");
        }
    }

    /**
     * Import SNOMED data
     */
    private function importSnomed(bool $usExtension): void
    {
        if (!function_exists('snomed_import')) {
            throw new CodeImportException("OpenEMR snomed_import function not available");
        }

        if (!snomed_import($usExtension)) {
            throw new CodeImportException("SNOMED import failed");
        }
    }

    /**
     * Import SNOMED RF2 data
     */
    private function importSnomedRF2(): void
    {
        if (!function_exists('snomedRF2_import')) {
            throw new CodeImportException("OpenEMR snomedRF2_import function not available");
        }

        if (!snomedRF2_import()) {
            throw new CodeImportException("SNOMED RF2 import failed");
        }
    }

    /**
     * Import ICD data
     */
    private function importIcd(string $type): void
    {
        if (!function_exists('icd_import')) {
            throw new CodeImportException("OpenEMR icd_import function not available");
        }

        if (!icd_import($type)) {
            throw new CodeImportException("$type import failed");
        }
    }

    /**
     * Import ValueSet data
     */
    private function importValueset(string $type): void
    {
        if (!function_exists('valueset_import')) {
            throw new CodeImportException("OpenEMR valueset_import function not available");
        }

        if (!valueset_import($type)) {
            throw new CodeImportException("CQM ValueSet import failed");
        }
    }

    /**
     * Check if vocabulary is already loaded by examining standardized_tables_track
     */
    public function isAlreadyLoaded(string $codeType, string $revisionDate, string $version, string $fileChecksum): bool
    {
        if (!function_exists('sqlQuery')) {
            return false;
        }

        $result = sqlQuery(
            "SELECT COUNT(*) as count FROM `standardized_tables_track` WHERE `name` = ? AND `revision_date` = ? AND `revision_version` = ? AND `file_checksum` = ?",
            array($codeType, $revisionDate, $version, $fileChecksum)
        );

        return $result && $result['count'] > 0;
    }

    /**
     * Check if any version of vocabulary is loaded (simpler check for post-lock validation)
     */
    public function isVocabularyLoaded(string $codeType): bool
    {
        if (!function_exists('sqlQuery')) {
            return false;
        }

        // Use SNOMED for tracking regardless of RF2 format to match OpenEMR expectations
        $trackingCodeType = ($codeType === 'SNOMED_RF2') ? 'SNOMED' : $codeType;

        try {
            $result = sqlQuery(
                "SELECT COUNT(*) as count FROM standardized_tables_track WHERE name = ?",
                [$trackingCodeType]
            );

            return $result && $result['count'] > 0;
        } catch (\Exception $e) {
            // If query fails, assume not loaded to be safe
            return false;
        }
    }

    /**
     * Update tracking table
     */
    public function updateTracking(string $type, string $revision, string $version, string $fileChecksum): bool
    {
        if (!function_exists('update_tracker_table')) {
            throw new CodeImportException("OpenEMR update_tracker_table function not available");
        }

        return update_tracker_table($type, $revision, $version, $fileChecksum);
    }

    /**
     * Cleanup temporary files
     */
    public function cleanup(string $type): void
    {
        if (!function_exists('temp_dir_cleanup')) {
            throw new CodeImportException("OpenEMR temp_dir_cleanup function not available");
        }

        temp_dir_cleanup($type);
    }

    /**
     * Acquire a database lock for the given code type to prevent concurrent imports
     */
    private function acquireLock(string $codeType): void
    {
        if (!function_exists('sqlQuery')) {
            throw new CodeImportException("OpenEMR database functions not available");
        }

        // Create a unique lock name for this code type
        $lockName = "openemr_vocab_import_{$codeType}";
        $this->currentLockName = $lockName;

        $attempt = 1;
        $delay = $this->lockRetryDelaySeconds;

        while ($attempt <= $this->lockRetryAttempts) {
            // Attempt to acquire the lock with a 10-second timeout per attempt
            $result = sqlQuery("SELECT GET_LOCK(?, 10) as lock_result", [$lockName]);

            if ($result && $result['lock_result'] == 1) {
                // Lock acquired successfully
                return;
            }

            // Lock acquisition failed
            if (!$result || $result['lock_result'] === null) {
                // Database error - don't retry
                $this->currentLockName = null;
                throw new DatabaseLockException("Database lock acquisition failed for {$codeType} import due to a database error.");
            }

            // Lock is held by another process ($result['lock_result'] == 0)
            if ($this->lockRetryDelaySeconds == 0) {
                // No retry mode - fail immediately
                $this->currentLockName = null;
                throw new DatabaseLockException("Failed to acquire database lock for {$codeType} import - another import is in progress and no-wait mode is enabled.");
            }

            if ($attempt < $this->lockRetryAttempts) {
                echo "Lock is held by another process. Waiting {$delay} seconds before retry {$attempt}/{$this->lockRetryAttempts}...\n";
                $this->waitedForLock = true;
                sleep($delay);

                // Exponential backoff with jitter (cap at 5 minutes)
                $delay = min($delay * 2, 300) + rand(1, min(10, $delay));
                $attempt++;
            } else {
                // Final attempt failed
                $this->currentLockName = null;
                $totalWaitTime = $this->calculateTotalWaitTime();
                throw new DatabaseLockException("Failed to acquire database lock for {$codeType} import after {$this->lockRetryAttempts} attempts ({$totalWaitTime} seconds total). Another import may still be in progress.");
            }
        }
    }

    /**
     * Calculate approximate total wait time for user feedback
     */
    private function calculateTotalWaitTime(): int
    {
        if ($this->lockRetryDelaySeconds == 0) {
            return 0;
        }

        $total = 0;
        $delay = $this->lockRetryDelaySeconds;

        for ($i = 1; $i < $this->lockRetryAttempts; $i++) {
            $total += $delay;
            $delay = min($delay * 2, 300); // Cap at 5 minutes
        }

        return $total;
    }

    /**
     * Release the currently held database lock
     */
    private function releaseLock(): void
    {
        if ($this->currentLockName === null) {
            return; // No lock to release
        }

        if (!function_exists('sqlQuery')) {
            // Log warning but don't throw exception during cleanup
            error_log("Warning: Could not release database lock - OpenEMR functions not available");
            return;
        }

        try {
            sqlQuery("SELECT RELEASE_LOCK(?)", [$this->currentLockName]);
        } catch (\Exception $e) {
            // Log error but don't throw exception during cleanup
            error_log("Warning: Failed to release database lock '{$this->currentLockName}': " . $e->getMessage());
        } finally {
            $this->currentLockName = null;
            $this->waitedForLock = false;
        }
    }

    /**
     * Destructor to ensure locks are always released
     */
    public function __destruct()
    {
        $this->releaseLock();
    }
}
