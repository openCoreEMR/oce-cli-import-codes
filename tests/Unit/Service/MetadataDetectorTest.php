<?php

/**
 * MetadataDetectorTest.php
 * Unit tests for MetadataDetector service
 *
 * @package   OpenCoreEMR\CLI\ImportCodes\Tests
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Tests\Unit\Service;

use OpenCoreEMR\CLI\ImportCodes\Service\MetadataDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MetadataDetectorTest extends TestCase
{
    private MetadataDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new MetadataDetector();
    }

    #[Test]
    #[DataProvider('rxnormFilenameProvider')]
    public function detectCodeTypeRecognizesRxnormFiles(string $filename, string $expectedType): void
    {
        $this->assertEquals($expectedType, $this->detector->detectCodeType($filename));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rxnormFilenameProvider(): array
    {
        return [
            'standard rxnorm' => ['RxNorm_full_01012024.zip', 'RXNORM'],
            'december release' => ['RxNorm_full_12312024.zip', 'RXNORM'],
        ];
    }

    #[Test]
    #[DataProvider('snomedFilenameProvider')]
    public function detectCodeTypeRecognizesSnomedFiles(string $filename, string $expectedType): void
    {
        $this->assertEquals($expectedType, $this->detector->detectCodeType($filename));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function snomedFilenameProvider(): array
    {
        return [
            'snomed rf2 us edition' => ['SnomedCT_USEditionRF2_PRODUCTION_20240301T120000Z.zip', 'SNOMED_RF2'],
            'snomed rf2 international' => ['SnomedCT_InternationalRF2_PRODUCTION_20240301T120000Z.zip', 'SNOMED_RF2'],
            'snomed rf1 international' => ['SnomedCT_INT_20240301.zip', 'SNOMED'],
            'snomed rf1 release' => ['SnomedCT_Release_INT_20240301.zip', 'SNOMED'],
        ];
    }

    #[Test]
    #[DataProvider('cqmFilenameProvider')]
    public function detectCodeTypeRecognizesCqmFiles(string $filename, string $expectedType): void
    {
        $this->assertEquals($expectedType, $this->detector->detectCodeType($filename));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cqmFilenameProvider(): array
    {
        return [
            'ec valueset' => ['ec_only_cms_20240101.xml.zip', 'CQM_VALUESET'],
            'ep valueset' => ['ep_only_cms_20240101.xml.zip', 'CQM_VALUESET'],
        ];
    }

    #[Test]
    #[DataProvider('icdFilenameProvider')]
    public function detectCodeTypeRecognizesIcdFiles(string $filename, string $expectedType): void
    {
        $this->assertEquals($expectedType, $this->detector->detectCodeType($filename));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function icdFilenameProvider(): array
    {
        return [
            'icd10 cm order' => ['icd10cm_order_2024.txt.zip', 'ICD10'],
            'icd10 uppercase' => ['ICD10_2024.zip', 'ICD10'],
            'icd9 codes' => ['icd9cm_order_2024.zip', 'ICD9'],
            'icd9 uppercase' => ['ICD9_codes.zip', 'ICD9'],
        ];
    }

    #[Test]
    public function detectCodeTypeReturnsEmptyForUnknownFiles(): void
    {
        $this->assertEquals('', $this->detector->detectCodeType('unknown_file.zip'));
        $this->assertEquals('', $this->detector->detectCodeType('random_codes.zip'));
        $this->assertEquals('', $this->detector->detectCodeType(''));
    }

    #[Test]
    public function isSupportedReturnsTrueForSupportedFiles(): void
    {
        $this->assertTrue($this->detector->isSupported('RxNorm_full_01012024.zip'));
        $this->assertTrue($this->detector->isSupported('SnomedCT_USEditionRF2_PRODUCTION_20240301T120000Z.zip'));
        $this->assertTrue($this->detector->isSupported('ec_only_cms_20240101.xml.zip'));
        $this->assertTrue($this->detector->isSupported('icd10cm_order_2024.txt.zip'));
    }

    #[Test]
    public function isSupportedReturnsFalseForUnsupportedFiles(): void
    {
        $this->assertFalse($this->detector->isSupported('unknown_file.zip'));
        $this->assertFalse($this->detector->isSupported('random_codes.zip'));
        $this->assertFalse($this->detector->isSupported(''));
    }

    #[Test]
    public function getSupportedPatternsReturnsAllCodeTypes(): void
    {
        $patterns = $this->detector->getSupportedPatterns();

        $this->assertArrayHasKey('RXNORM', $patterns);
        $this->assertArrayHasKey('SNOMED', $patterns);
        $this->assertArrayHasKey('SNOMED_RF2', $patterns);
        $this->assertArrayHasKey('CQM_VALUESET', $patterns);
        $this->assertArrayHasKey('ICD9', $patterns);
        $this->assertArrayHasKey('ICD10', $patterns);

        // Each type should have at least one pattern
        foreach ($patterns as $type => $typePatterns) {
            $this->assertNotEmpty($typePatterns, "Type $type should have at least one pattern");
        }
    }

    #[Test]
    public function detectFromFileReturnsMetadataForRxnorm(): void
    {
        // Create a temporary test file
        $tempFile = sys_get_temp_dir() . '/RxNorm_full_01152024.zip';
        file_put_contents($tempFile, 'test content');

        try {
            $metadata = $this->detector->detectFromFile($tempFile, 'RXNORM');

            $this->assertTrue($metadata['supported']);
            $this->assertEquals('RXNORM', $metadata['code_type']);
            $this->assertEquals('Standard', $metadata['version']);
            $this->assertEquals('2024-01-15', $metadata['revision_date']);
            $this->assertFalse($metadata['rf2']);
            $this->assertNotEmpty($metadata['checksum']);
        } finally {
            unlink($tempFile);
        }
    }

    #[Test]
    public function detectFromFileReturnsMetadataForSnomedRf2(): void
    {
        // Create a temporary test file
        $tempFile = sys_get_temp_dir() . '/SnomedCT_USEditionRF2_PRODUCTION_20240301T120000Z.zip';
        file_put_contents($tempFile, 'test content');

        try {
            $metadata = $this->detector->detectFromFile($tempFile, 'SNOMED');

            $this->assertTrue($metadata['supported']);
            $this->assertEquals('SNOMED', $metadata['code_type']);
            $this->assertEquals('Complete US Extension', $metadata['version']);
            $this->assertEquals('2024-03-01', $metadata['revision_date']);
            $this->assertTrue($metadata['rf2']);
            $this->assertTrue($metadata['us_extension']);
        } finally {
            unlink($tempFile);
        }
    }

    #[Test]
    public function detectFromFileReturnsMetadataForCqmValueset(): void
    {
        // Create a temporary test file
        $tempFile = sys_get_temp_dir() . '/ec_only_cms_20240615.xml.zip';
        file_put_contents($tempFile, 'test content');

        try {
            $metadata = $this->detector->detectFromFile($tempFile, 'CQM_VALUESET');

            $this->assertTrue($metadata['supported']);
            $this->assertEquals('CQM_VALUESET', $metadata['code_type']);
            $this->assertEquals('Standard', $metadata['version']);
            $this->assertEquals('2024-06-15', $metadata['revision_date']);
        } finally {
            unlink($tempFile);
        }
    }

    #[Test]
    public function detectFromFileReturnsUnsupportedForUnknownFile(): void
    {
        // Create a temporary test file with unknown name
        $tempFile = sys_get_temp_dir() . '/unknown_codes.zip';
        file_put_contents($tempFile, 'test content');

        try {
            $metadata = $this->detector->detectFromFile($tempFile, 'RXNORM');

            $this->assertFalse($metadata['supported']);
            $this->assertEquals('RXNORM', $metadata['code_type']);
            $this->assertEquals('', $metadata['version']);
            $this->assertEquals('', $metadata['revision_date']);
        } finally {
            unlink($tempFile);
        }
    }

    #[Test]
    public function detectFromFileDetectsSnomedInternational(): void
    {
        $tempFile = sys_get_temp_dir() . '/SnomedCT_InternationalRF2_PRODUCTION_20240901T120000Z.zip';
        file_put_contents($tempFile, 'test content');

        try {
            $metadata = $this->detector->detectFromFile($tempFile, 'SNOMED');

            $this->assertTrue($metadata['supported']);
            $this->assertEquals('International:English', $metadata['version']);
            $this->assertTrue($metadata['rf2']);
            $this->assertFalse($metadata['us_extension']);
        } finally {
            unlink($tempFile);
        }
    }

    #[Test]
    public function detectFromFileHandlesSnomedRf1Format(): void
    {
        $tempFile = sys_get_temp_dir() . '/SnomedCT_INT_20240301.zip';
        file_put_contents($tempFile, 'test content');

        try {
            $metadata = $this->detector->detectFromFile($tempFile, 'SNOMED');

            $this->assertTrue($metadata['supported']);
            $this->assertEquals('International:English', $metadata['version']);
            $this->assertFalse($metadata['rf2']);
        } finally {
            unlink($tempFile);
        }
    }
}
