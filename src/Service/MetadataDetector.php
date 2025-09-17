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
     * Use OpenEMR's existing detection logic by simulating list_staged.php behavior
     */
    public function detectFromFile(string $filePath, string $codeType): array
    {
        $fileName = basename($filePath);
        $result = [
            'supported' => false,
            'code_type' => $codeType,
            'version' => '',
            'revision_date' => '',
            'rf2' => false,
            'us_extension' => false,
            'checksum' => md5_file($filePath)
        ];

        // Create temporary directory structure to leverage OpenEMR's detection
        $tempDir = sys_get_temp_dir() . '/oce_detect_' . uniqid();
        $codeDir = $tempDir . '/contrib/' . strtolower($codeType);

        try {
            mkdir($codeDir, 0755, true);
            copy($filePath, $codeDir . '/' . $fileName);

            // Simulate the exact logic from list_staged.php
            $revisions = $this->runOpenEMRDetection($codeType, $codeDir);

            if (!empty($revisions)) {
                $latest = end($revisions); // Get most recent
                $result['supported'] = true;
                $result['version'] = $latest['version'];
                $result['revision_date'] = $latest['date'];
                $result['rf2'] = isset($latest['rf2']) && $latest['rf2'];
                $result['us_extension'] = strpos($latest['version'], 'US') !== false;
            }

        } finally {
            // Cleanup
            if (is_dir($tempDir)) {
                $this->recursiveDelete($tempDir);
            }
        }

        return $result;
    }

    /**
     * Run OpenEMR's exact detection logic (extracted from list_staged.php)
     */
    private function runOpenEMRDetection(string $db, string $mainPATH): array
    {
        $revisions = array();

        if (!is_dir($mainPATH)) {
            return $revisions;
        }

        $files_array = scandir($mainPATH);
        array_shift($files_array); // remove "."
        array_shift($files_array); // remove ".."

        foreach ($files_array as $file) {
            $file = $mainPATH . "/" . $file;
            if (!is_file($file) || strpos($file, ".zip") === false) {
                continue;
            }

            $supported_file = 0;

            if ($db == 'RXNORM') {
                if (preg_match("/RxNorm_full_([0-9]{8}).zip/", $file, $matches)) {
                    $version = "Standard";
                    $date_release = substr($matches[1], 4) . "-" . substr($matches[1], 0, 2) . "-" . substr($matches[1], 2, -4);
                    $revisions[] = array('date' => $date_release, 'version' => $version, 'path' => $file);
                    $supported_file = 1;
                }
            } elseif ($db == 'SNOMED') {
                // All SNOMED patterns from OpenEMR's list_staged.php
                $patterns = [
                    ["/SnomedCT_INT_([0-9]{8}).zip/", "International:English"],
                    ["/SnomedCT_Release_INT_([0-9]{8}).zip/", "International:English"],
                    ["/SnomedCT_RF1Release_INT_([0-9]{8}).zip/", "International:English"],
                    ["/SnomedCT_Release_US[0-9]*_([0-9]{8}).zip/", "US Extension"],
                    ["/sct1_National_US_([0-9]{8}).zip/", "US Extension"],
                    ["/SnomedCT_RF1Release_US[0-9]*_([0-9]{8}).zip/", "Complete US Extension"],
                    ["/SnomedCT_Release-es_INT_([0-9]{8}).zip/", "International:Spanish"],
                    ["/SnomedCT_InternationalRF2_PRODUCTION_([0-9]{8})[0-9a-zA-Z]{8}.zip/", "International:English", true],
                    ["/SnomedCT_ManagedServiceIE_PRODUCTION_IE1000220_([0-9]{8})[0-9a-zA-Z]{8}.zip/", "International:English", true],
                    ["/SnomedCT_USEditionRF2_PRODUCTION_([0-9]{8})[0-9a-zA-Z]{8}.zip/", "Complete US Extension", true],
                    ["/SnomedCT_ManagedServiceUS_PRODUCTION_US[0-9]{7}_([0-9a-zA-Z]{8})T[0-9Z]{7}.zip/", "Complete US Extension", true],
                    ["/SnomedCT_SpanishRelease-es_PRODUCTION_([0-9]{8})[0-9a-zA-Z]{8}.zip/", "International:Spanish", true],
                ];

                foreach ($patterns as $pattern) {
                    $regex = $pattern[0];
                    $version = $pattern[1];
                    $rf2 = isset($pattern[2]) ? $pattern[2] : false;

                    if (preg_match($regex, $file, $matches)) {
                        $date_release = substr($matches[1], 0, 4) . "-" . substr($matches[1], 4, -2) . "-" . substr($matches[1], 6);
                        $temp_date = array('date' => $date_release, 'version' => $version, 'path' => $file);
                        if ($rf2) $temp_date['rf2'] = true;
                        $revisions[] = $temp_date;
                        $supported_file = 1;
                        break;
                    }
                }
            } elseif ($db == 'CQM_VALUESET') {
                if (preg_match("/e[p,c]_.*_cms_([0-9]{8}).xml.zip/", $file, $matches)) {
                    $version = "Standard";
                    $date_release = substr($matches[1], 0, 4) . "-" . substr($matches[1], 4, -2) . "-" . substr($matches[1], 6);
                    $revisions[] = array('date' => $date_release, 'version' => $version, 'path' => $file);
                    $supported_file = 1;
                }
            } elseif (is_numeric(strpos($db, "ICD"))) {
                // For ICD, use database lookup if available
                if (function_exists('sqlQuery')) {
                    $qry_str = "SELECT `load_checksum`,`load_source`,`load_release_date` FROM `supported_external_dataloads` WHERE `load_type` = ? and `load_filename` = ? and `load_checksum` = ? ORDER BY `load_release_date` DESC";
                    $file_checksum = md5_file($file);
                    $sqlReturn = sqlQuery($qry_str, array($db, basename($file), $file_checksum));

                    if (!empty($sqlReturn)) {
                        $version = $sqlReturn['load_source'];
                        $date_release = $sqlReturn['load_release_date'];
                        $revisions[] = array('date' => $date_release, 'version' => $version, 'path' => $file, 'checksum' => $file_checksum);
                        $supported_file = 1;
                    }
                }
            }
        }

        return $revisions;
    }

    /**
     * Auto-detect code type from filename
     */
    public function detectCodeType(string $filePath): string
    {
        $fileName = basename($filePath);

        // Quick pattern matching for code type detection
        if (preg_match("/RxNorm_full_/", $fileName)) return 'RXNORM';
        if (preg_match("/SnomedCT_.*RF2_PRODUCTION_/", $fileName)) return 'SNOMED_RF2';
        if (preg_match("/SnomedCT_/", $fileName)) return 'SNOMED';
        if (preg_match("/e[p,c]_.*_cms_.*\.xml\.zip/", $fileName)) return 'CQM_VALUESET';
        if (preg_match("/icd10/i", $fileName)) return 'ICD10';
        if (preg_match("/icd9/i", $fileName)) return 'ICD9';

        return '';
    }

    /**
     * Check if file is supported
     */
    public function isSupported(string $filePath): bool
    {
        return !empty($this->detectCodeType($filePath));
    }

    /**
     * Get supported filename patterns for display
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

    private function recursiveDelete(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->recursiveDelete($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }
}
