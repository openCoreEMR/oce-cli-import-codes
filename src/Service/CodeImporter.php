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

class CodeImporter
{
    private ?string $customTempDir = null;

    /**
     * Set custom temporary directory
     */
    public function setTempDir(string $tempDir): void
    {
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            throw new \Exception("Temporary directory is not writable: $tempDir");
        }
        $this->customTempDir = $tempDir;
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
            throw new \Exception("OpenEMR temp_copy function not available");
        }

        if (!temp_copy($filePath, $codeType)) {
            throw new \Exception("Failed to copy file to temporary directory");
        }
    }

    /**
     * Extract archive file
     */
    public function extractFile(string $filePath, string $codeType): void
    {
        if (!function_exists('temp_unarchive')) {
            throw new \Exception("OpenEMR temp_unarchive function not available");
        }

        if (!temp_unarchive($filePath, $codeType)) {
            throw new \Exception("Failed to extract archive file");
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
                throw new \Exception("Unsupported code type: $codeType");
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
            throw new \Exception("OpenEMR rxnorm_import function not available");
        }

        if (!rxnorm_import($isWindows)) {
            throw new \Exception("RXNORM import failed");
        }
    }

    /**
     * Import SNOMED data
     */
    private function importSnomed(bool $usExtension): void
    {
        if (!function_exists('snomed_import')) {
            throw new \Exception("OpenEMR snomed_import function not available");
        }

        if (!snomed_import($usExtension)) {
            throw new \Exception("SNOMED import failed");
        }
    }

    /**
     * Import SNOMED RF2 data
     */
    private function importSnomedRF2(): void
    {
        if (!function_exists('snomedRF2_import')) {
            throw new \Exception("OpenEMR snomedRF2_import function not available");
        }

        if (!snomedRF2_import()) {
            throw new \Exception("SNOMED RF2 import failed");
        }
    }

    /**
     * Import ICD data
     */
    private function importIcd(string $type): void
    {
        if (!function_exists('icd_import')) {
            throw new \Exception("OpenEMR icd_import function not available");
        }

        if (!icd_import($type)) {
            throw new \Exception("$type import failed");
        }
    }

    /**
     * Import ValueSet data
     */
    private function importValueset(string $type): void
    {
        if (!function_exists('valueset_import')) {
            throw new \Exception("OpenEMR valueset_import function not available");
        }

        if (!valueset_import($type)) {
            throw new \Exception("CQM ValueSet import failed");
        }
    }

    /**
     * Update tracking table
     */
    public function updateTracking(string $type, string $revision, string $version, string $fileChecksum): bool
    {
        if (!function_exists('update_tracker_table')) {
            throw new \Exception("OpenEMR update_tracker_table function not available");
        }

        return update_tracker_table($type, $revision, $version, $fileChecksum);
    }

    /**
     * Cleanup temporary files
     */
    public function cleanup(string $type): void
    {
        if (!function_exists('temp_dir_cleanup')) {
            throw new \Exception("OpenEMR temp_dir_cleanup function not available");
        }

        temp_dir_cleanup($type);
    }
}
