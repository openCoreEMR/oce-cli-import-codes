<?php

/**
 * MetadataDetector.php
 * Service for detecting code metadata using OpenEMR's existing logic
 *
 * @package   OpenCoreEMR\CLI\ImportCodes
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Service;

class MetadataDetector
{
    /**
     * Use OpenEMR's existing detection logic by directly processing the filename
     *
     * @return array<string, mixed>
     */
    public function detectFromFile(string $filePath, string $codeType, bool $allowUnsupported = false): array
    {
        $result = [
            'supported' => false,
            'from_database' => false,
            'code_type' => $codeType,
            'version' => '',
            'revision_date' => '',
            'rf2' => false,
            'us_extension' => false,
            'checksum' => md5_file($filePath)
        ];

        // Run detection directly on the filename without temp file operations
        $revisions = $this->runOpenEMRDetection($codeType, $filePath, $allowUnsupported);

        if ($revisions !== []) {
            $latest = end($revisions); // Get most recent
            $result['supported'] = true;
            $result['from_database'] = !empty($latest['from_database']);
            $version = is_string($latest['version']) ? $latest['version'] : '';
            $result['version'] = $version;
            $result['revision_date'] = $latest['date'];
            $result['rf2'] = isset($latest['rf2']) && $latest['rf2'];
            $result['us_extension'] = str_contains($version, 'US');
        }

        return $result;
    }

    /**
     * Run OpenEMR's exact detection logic (extracted from list_staged.php)
     *
     * @return list<array<string, mixed>>
     */
    private function runOpenEMRDetection(string $db, string $filePath, bool $allowUnsupported = false): array
    {
        $revisions = [];
        $fileName = basename($filePath);

        if ($db === 'RXNORM') {
            if (preg_match("/RxNorm_full_(\\d{8}).zip/", $fileName, $matches)) {
                $version = "Standard";
                // RXNORM date format: MMDDYYYY
                $m = $matches[1];
                $date_release = substr($m, 4) . "-" . substr($m, 0, 2) . "-" . substr($m, 2, 2);
                $revisions[] = ['date' => $date_release, 'version' => $version, 'path' => $filePath];
            }
        } elseif ($db === 'SNOMED') {
            // All SNOMED patterns from OpenEMR's list_staged.php
            // phpcs:disable Generic.Files.LineLength.TooLong
            $patterns = [
                ["/SnomedCT_INT_(\\d{8}).zip/", "International:English"],
                ["/SnomedCT_Release_INT_(\\d{8}).zip/", "International:English"],
                ["/SnomedCT_RF1Release_INT_(\\d{8}).zip/", "International:English"],
                ["/SnomedCT_Release_US\\d*_(\\d{8}).zip/", "US Extension"],
                ["/sct1_National_US_(\\d{8}).zip/", "US Extension"],
                ["/SnomedCT_RF1Release_US\\d*_(\\d{8}).zip/", "Complete US Extension"],
                ["/SnomedCT_Release-es_INT_(\\d{8}).zip/", "International:Spanish"],
                ["/SnomedCT_InternationalRF2_PRODUCTION_(\\d{8})[0-9a-zA-Z]{8}.zip/", "International:English", true],
                ["/SnomedCT_ManagedServiceIE_PRODUCTION_IE1000220_(\\d{8})[0-9a-zA-Z]{8}.zip/", "International:English", true],
                ["/SnomedCT_USEditionRF2_PRODUCTION_(\\d{8})[0-9a-zA-Z]{8}.zip/", "Complete US Extension", true],
                ["/SnomedCT_ManagedServiceUS_PRODUCTION_US\\d{7}_([0-9a-zA-Z]{8})T[0-9Z]{7}.zip/", "Complete US Extension", true],
                ["/SnomedCT_SpanishRelease-es_PRODUCTION_(\\d{8})[0-9a-zA-Z]{8}.zip/", "International:Spanish", true],
            ];
            // phpcs:enable

            foreach ($patterns as $pattern) {
                $regex = $pattern[0];
                $version = $pattern[1];
                $rf2 = $pattern[2] ?? false;

                if (preg_match($regex, $fileName, $matches)) {
                    // Fix date parsing - handle both 8-digit dates and mixed formats
                    $dateStr = $matches[1];
                    if (strlen($dateStr) === 8 && is_numeric($dateStr)) {
                        // Format: YYYYMMDD -> YYYY-MM-DD
                        $date_release = substr($dateStr, 0, 4) . "-"
                            . substr($dateStr, 4, 2) . "-" . substr($dateStr, 6, 2);
                    } else {
                        // Handle other formats or fallback
                        $date_release = date('Y-m-d'); // Use current date as fallback
                    }

                    $temp_date = ['date' => $date_release, 'version' => $version, 'path' => $filePath];
                    if ($rf2) {
                        $temp_date['rf2'] = true;
                    }
                    $revisions[] = $temp_date;
                    break;
                }
            }
        } elseif ($db === 'CQM_VALUESET') {
            if (preg_match("/e[p,c]_.*_cms_(\\d{8}).xml.zip/", $fileName, $matches)) {
                $version = "Standard";
                // CQM date format: YYYYMMDD -> YYYY-MM-DD
                $m = $matches[1];
                $date_release = substr($m, 0, 4) . "-" . substr($m, 4, 2) . "-" . substr($m, 6, 2);
                $revisions[] = ['date' => $date_release, 'version' => $version, 'path' => $filePath];
            }
        } elseif (is_numeric(strpos($db, "ICD"))) {
            $revisions = $this->detectIcdFromFile($db, $filePath, $allowUnsupported);
        }

        return $revisions;
    }

    /**
     * Detect ICD metadata from file, with optional fallback to filename parsing
     *
     * @return list<array<string, mixed>>
     */
    private function detectIcdFromFile(string $db, string $filePath, bool $allowUnsupported): array
    {
        $revisions = [];
        $fileName = basename($filePath);
        $file_checksum = md5_file($filePath);

        // First try database lookup if available
        if (function_exists('sqlQuery')) {
            $qry_str = "SELECT `load_checksum`, `load_source`, `load_release_date` "
                . "FROM `supported_external_dataloads` "
                . "WHERE `load_type` = ? AND `load_filename` = ? AND `load_checksum` = ? "
                . "ORDER BY `load_release_date` DESC";
            $sqlReturn = sqlQuery($qry_str, [$db, $fileName, $file_checksum]);

            if (!empty($sqlReturn)) {
                return [[
                    'date' => $sqlReturn['load_release_date'],
                    'version' => $sqlReturn['load_source'],
                    'path' => $filePath,
                    'checksum' => $file_checksum,
                    'from_database' => true
                ]];
            }
        }

        // If allowUnsupported, fall back to filename-based detection
        if ($allowUnsupported) {
            $metadata = $this->parseIcdFilename($fileName);
            if ($metadata !== null) {
                $revisions[] = [
                    'date' => $metadata['release_date'],
                    'version' => $metadata['version'],
                    'path' => $filePath,
                    'checksum' => $file_checksum,
                    'from_database' => false
                ];
            }
        }

        return $revisions;
    }

    /**
     * Parse ICD metadata from filename
     *
     * @return ?array{release_date: string, version: string, year: int}
     */
    private function parseIcdFilename(string $fileName): ?array
    {
        // Patterns for ICD filenames with year extraction
        // phpcs:disable Generic.Files.LineLength.TooLong
        $patterns = [
            // icd10OrderFiles2025_0.zip -> year 2025, effective 2024-10-01
            '/icd10OrderFiles(\d{4})/' => 'ICD10 CM',
            // icd10cm_order_2024.txt.zip -> year 2024, effective 2023-10-01
            '/icd10cm_order_(\d{4})/' => 'ICD10 CM',
            // Zip File 3 2026 ICD-10-PCS Codes File.zip -> year 2026, effective 2025-10-01
            '/Zip File \d+ (\d{4}) ICD-10-PCS/i' => 'ICD10 PCS',
            // zip-file-3-2026-icd-10-pcs-codes-file.zip -> year 2026, effective 2025-10-01
            '/zip-file-\d+-(\d{4})-icd-10-pcs/i' => 'ICD10 PCS',
            // icd9cm_order_*.zip patterns
            '/icd9cm.*(\d{4})/' => 'ICD9 CM',
            '/ICD-9-CM-v(\d+)/' => 'ICD9 CM',
        ];
        // phpcs:enable

        foreach ($patterns as $pattern => $version) {
            if (preg_match($pattern, $fileName, $matches)) {
                $year = (int) $matches[1];

                // ICD codes are effective October 1 of the previous year
                // e.g., "2026 codes" are effective 2025-10-01
                $effectiveYear = $year - 1;
                $releaseDate = sprintf('%d-10-01', $effectiveYear);

                return [
                    'release_date' => $releaseDate,
                    'version' => $version,
                    'year' => $year
                ];
            }
        }

        // Handle generic icd10orderfiles.zip without year - use current fiscal year
        if (preg_match('/icd10orderfiles\.zip$/i', $fileName)) {
            // ICD fiscal year starts October 1
            // If we're before October, we're in the previous fiscal year
            $now = new \DateTime();
            $month = (int) $now->format('n');
            $year = (int) $now->format('Y');

            // Fiscal year: Oct 2025 - Sep 2026 = FY 2026
            $fiscalYear = $month >= 10 ? $year + 1 : $year;
            $effectiveYear = $fiscalYear - 1;

            return [
                'release_date' => sprintf('%d-10-01', $effectiveYear),
                'version' => 'ICD10 CM',
                'year' => $fiscalYear
            ];
        }

        return null;
    }

    /**
     * Auto-detect code type from filename
     */
    public function detectCodeType(string $filePath): string
    {
        $fileName = basename($filePath);

        // Quick pattern matching for code type detection
        if (preg_match("/RxNorm_full_/", $fileName)) {
            return 'RXNORM';
        }
        if (preg_match("/SnomedCT_.*RF2_PRODUCTION_/", $fileName)) {
            return 'SNOMED_RF2';
        }
        if (preg_match("/SnomedCT_/", $fileName)) {
            return 'SNOMED';
        }
        if (preg_match("/e[p,c]_.*_cms_.*\.xml\.zip/", $fileName)) {
            return 'CQM_VALUESET';
        }
        if (preg_match("/icd10/i", $fileName)) {
            return 'ICD10';
        }
        if (preg_match("/icd9/i", $fileName)) {
            return 'ICD9';
        }

        return '';
    }

    /**
     * Check if file is supported
     */
    public function isSupported(string $filePath): bool
    {
        return !in_array($this->detectCodeType($filePath), ['', '0'], true);
    }

    /**
     * Get supported filename patterns for display
     *
     * @return array<string, list<string>>
     */
    public function getSupportedPatterns(): array
    {
        return [
            'RXNORM' => ['RxNorm_full_MMDDYYYY.zip'],
            'SNOMED' => ['SnomedCT_INT_YYYYMMDD.zip', 'SnomedCT_Release_INT_YYYYMMDD.zip'],
            'SNOMED_RF2' => ['SnomedCT_InternationalRF2_PRODUCTION_*.zip', 'SnomedCT_USEditionRF2_PRODUCTION_*.zip'],
            'CQM_VALUESET' => ['ep_*_cms_YYYYMMDD.xml.zip', 'ec_*_cms_YYYYMMDD.xml.zip'],
            'ICD9' => ['*icd9*.zip'],
            'ICD10' => ['*icd10*.zip'],
        ];
    }
}
