<?php

/**
 * ImportCodesCommandTest.php
 * Unit tests for ImportCodesCommand
 *
 * @package   OpenCoreEMR\CLI\ImportCodes\Tests
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Tests\Unit\Command;

use OpenCoreEMR\CLI\ImportCodes\Command\ImportCodesCommand;
use OpenCoreEMR\CLI\ImportCodes\Service\CodeImporter;
use OpenCoreEMR\CLI\ImportCodes\Service\MetadataDetector;
use OpenCoreEMR\CLI\ImportCodes\Service\OpenEMRConnector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ImportCodesCommandTest extends TestCase
{
    private ImportCodesCommand $command;
    private CommandTester $tester;
    private CodeImporter&MockObject $importerMock;
    private OpenEMRConnector&MockObject $connectorMock;
    private MetadataDetector&MockObject $detectorMock;

    protected function setUp(): void
    {
        $this->importerMock = $this->createMock(CodeImporter::class);
        $this->connectorMock = $this->createMock(OpenEMRConnector::class);
        $this->detectorMock = $this->createMock(MetadataDetector::class);

        $this->command = new ImportCodesCommand(
            null,
            $this->importerMock,
            $this->connectorMock,
            $this->detectorMock
        );

        $application = new Application();
        $application->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    #[Test]
    public function commandHasCorrectNameAndDescription(): void
    {
        $this->assertEquals('import', $this->command->getName());
        $this->assertStringContainsString('standardized code tables', $this->command->getDescription());
    }

    #[Test]
    public function commandHasRequiredFilePathArgument(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasArgument('file-path'));
        $this->assertTrue($definition->getArgument('file-path')->isRequired());
    }

    #[Test]
    public function commandHasAllExpectedOptions(): void
    {
        $definition = $this->command->getDefinition();

        $expectedOptions = [
            'code-type',
            'openemr-path',
            'site',
            'windows',
            'dry-run',
            'cleanup',
            'temp-dir',
            'force',
            'lock-retry-attempts',
            'lock-retry-delay',
        ];

        foreach ($expectedOptions as $optionName) {
            $this->assertTrue(
                $definition->hasOption($optionName),
                "Command should have option: $optionName"
            );
        }
    }

    #[Test]
    public function commandFailsWhenFileNotFound(): void
    {
        $result = $this->tester->execute([
            'file-path' => '/non/existent/file.zip',
            '--openemr-path' => '/var/www/openemr',
        ]);

        $this->assertEquals(Command::FAILURE, $result);
        $this->assertStringContainsString('File not found', $this->tester->getDisplay());
    }

    #[Test]
    public function commandFailsWhenOpenEmrPathNotFound(): void
    {
        // Create a temporary file
        $tempFile = sys_get_temp_dir() . '/RxNorm_full_01012024.zip';
        file_put_contents($tempFile, 'test');

        try {
            $result = $this->tester->execute([
                'file-path' => $tempFile,
                '--openemr-path' => '/non/existent/openemr',
            ]);

            $this->assertEquals(Command::FAILURE, $result);
            $this->assertStringContainsString('OpenEMR path not found', $this->tester->getDisplay());
        } finally {
            unlink($tempFile);
        }
    }

    #[Test]
    public function commandFailsWithInvalidCodeType(): void
    {
        // Create a temporary file
        $tempFile = sys_get_temp_dir() . '/test_codes.zip';
        file_put_contents($tempFile, 'test');

        // Create a temp "openemr" directory
        $tempOpenemr = sys_get_temp_dir() . '/openemr_test_' . uniqid();
        mkdir($tempOpenemr);

        try {
            $result = $this->tester->execute([
                'file-path' => $tempFile,
                '--openemr-path' => $tempOpenemr,
                '--code-type' => 'INVALID_TYPE',
            ]);

            $this->assertEquals(Command::FAILURE, $result);
            $this->assertStringContainsString('Unsupported code type', $this->tester->getDisplay());
        } finally {
            unlink($tempFile);
            rmdir($tempOpenemr);
        }
    }

    #[Test]
    public function commandFailsWhenCodeTypeCannotBeDetected(): void
    {
        // Create a temporary file with unknown name
        $tempFile = sys_get_temp_dir() . '/unknown_codes.zip';
        file_put_contents($tempFile, 'test');

        // Create a temp "openemr" directory
        $tempOpenemr = sys_get_temp_dir() . '/openemr_test_' . uniqid();
        mkdir($tempOpenemr);

        // Configure detector to return empty code type
        $this->detectorMock->method('detectCodeType')->willReturn('');

        try {
            $result = $this->tester->execute([
                'file-path' => $tempFile,
                '--openemr-path' => $tempOpenemr,
            ]);

            $this->assertEquals(Command::FAILURE, $result);
            $this->assertStringContainsString('Could not auto-detect code type', $this->tester->getDisplay());
        } finally {
            unlink($tempFile);
            rmdir($tempOpenemr);
        }
    }

    #[Test]
    public function commandAcceptsValidCodeTypeOverride(): void
    {
        // Create a temporary file
        $tempFile = sys_get_temp_dir() . '/test_codes.zip';
        file_put_contents($tempFile, 'test');

        // Create a temp "openemr" directory
        $tempOpenemr = sys_get_temp_dir() . '/openemr_test_' . uniqid();
        mkdir($tempOpenemr);

        // Configure detector to report as supported
        $this->detectorMock->method('isSupported')->willReturn(true);
        $this->detectorMock->method('detectFromFile')->willReturn([
            'supported' => true,
            'code_type' => 'RXNORM',
            'version' => 'Standard',
            'revision_date' => '2024-01-15',
            'rf2' => false,
            'us_extension' => false,
            'checksum' => 'abc123',
        ]);

        // Connector will fail because OpenEMR isn't present
        $this->connectorMock->method('initialize')
            ->willThrowException(new \Exception('OpenEMR not available'));

        try {
            $result = $this->tester->execute([
                'file-path' => $tempFile,
                '--openemr-path' => $tempOpenemr,
                '--code-type' => 'RXNORM',
            ]);

            // Will fail at OpenEMR initialization, but code type validation passed
            $this->assertEquals(Command::FAILURE, $result);
            $this->assertStringContainsString('Failed to initialize OpenEMR', $this->tester->getDisplay());
        } finally {
            unlink($tempFile);
            rmdir($tempOpenemr);
        }
    }

    #[Test]
    public function commandHasDefaultOpenemrPath(): void
    {
        $definition = $this->command->getDefinition();
        $option = $definition->getOption('openemr-path');

        $this->assertEquals('/var/www/localhost/htdocs/openemr', $option->getDefault());
    }

    #[Test]
    public function commandHasDefaultSite(): void
    {
        $definition = $this->command->getDefinition();
        $option = $definition->getOption('site');

        $this->assertEquals('default', $option->getDefault());
    }

    #[Test]
    public function commandHasDefaultLockRetrySettings(): void
    {
        $definition = $this->command->getDefinition();

        $attemptsOption = $definition->getOption('lock-retry-attempts');
        $delayOption = $definition->getOption('lock-retry-delay');

        $this->assertEquals(10, $attemptsOption->getDefault());
        $this->assertEquals(30, $delayOption->getDefault());
    }

    #[Test]
    public function commandHelpIncludesSupportedCodeTypes(): void
    {
        $help = $this->command->getHelp();

        $this->assertStringContainsString('RXNORM', $help);
        $this->assertStringContainsString('SNOMED', $help);
        $this->assertStringContainsString('ICD', $help);
        $this->assertStringContainsString('CQM_VALUESET', $help);
    }
}
