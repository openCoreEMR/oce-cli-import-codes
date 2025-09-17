<?php

/**
 * ImportCodesCommand.php
 * Standalone CLI tool for importing standardized code tables (RXNORM, SNOMED, ICD, CQM_VALUESET)
 *
 * @package   OpenCoreEMR\CLI\ImportCodes
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Command;

use OpenCoreEMR\CLI\ImportCodes\Service\CodeImporter;
use OpenCoreEMR\CLI\ImportCodes\Service\OpenEMRConnector;
use OpenCoreEMR\CLI\ImportCodes\Service\MetadataDetector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ImportCodesCommand extends Command
{
    private const SUPPORTED_TYPES = ['RXNORM', 'SNOMED', 'SNOMED_RF2', 'ICD9', 'ICD10', 'CQM_VALUESET'];

    private CodeImporter $importer;
    private OpenEMRConnector $connector;
    private MetadataDetector $detector;

    public function __construct(?CodeImporter $importer = null, ?OpenEMRConnector $connector = null, ?MetadataDetector $detector = null)
    {
        parent::__construct();
        $this->importer = $importer ?? new CodeImporter();
        $this->connector = $connector ?? new OpenEMRConnector();
        $this->detector = $detector ?? new MetadataDetector();
    }

    protected function configure()
    {
        $this
            ->setName('import')
            ->setDescription("Import standardized code tables into OpenEMR with automatic detection")
            ->setHelp("This command automatically detects code type, version, and revision from filenames and imports medical code tables into OpenEMR.\n\nSupported code types: " . implode(', ', self::SUPPORTED_TYPES))
            ->addArgument('file-path', InputArgument::REQUIRED, 'Path to the code file archive (zip file)')
            ->addOption('code-type', null, InputOption::VALUE_REQUIRED, 'Override auto-detected code type (' . implode('|', self::SUPPORTED_TYPES) . ')')
            ->addOption('openemr-path', null, InputOption::VALUE_REQUIRED, 'Path to OpenEMR installation', '/var/www/localhost/htdocs/openemr')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Name of OpenEMR site', 'default')
            ->addOption('windows', 'w', InputOption::VALUE_NONE, 'Use Windows-specific processing (RXNORM only)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Perform a dry run without making database changes')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Clean up temporary files after import')
            ->addOption('temp-dir', null, InputOption::VALUE_REQUIRED, 'Custom temporary directory path')
            ->addUsage('/path/to/RxNorm_full_01012024.zip --openemr-path=/var/www/openemr')
            ->addUsage('/path/to/SnomedCT_USEditionRF2_PRODUCTION_20240301T120000Z.zip')
            ->addUsage('/path/to/icd10cm_order_2024.txt.zip --cleanup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filePath = $input->getArgument('file-path');
        $openemrPath = $input->getOption('openemr-path');
        $site = $input->getOption('site') ?? 'default';
        $isWindows = $input->getOption('windows');
        $dryRun = $input->getOption('dry-run');
        $cleanup = $input->getOption('cleanup');
        $tempDir = $input->getOption('temp-dir');

        // Resolve relative paths to absolute paths
        if (!$this->is_absolute_path($filePath)) {
            $filePath = getcwd() . DIRECTORY_SEPARATOR . $filePath;
        }
        $filePath = realpath($filePath) ?: $filePath;

        // Validate file exists
        if (!file_exists($filePath)) {
            $io->error("File not found: $filePath");
            return Command::FAILURE;
        }

        if (!is_dir($openemrPath)) {
            $io->error("OpenEMR path not found: $openemrPath");
            return Command::FAILURE;
        }

        // Auto-detect code type, or use override
        $codeTypeOverride = $input->getOption('code-type');
        if ($codeTypeOverride) {
            $codeType = strtoupper($codeTypeOverride);
            if (!in_array($codeType, self::SUPPORTED_TYPES)) {
                $io->error("Unsupported code type: $codeType. Supported types: " . implode(', ', self::SUPPORTED_TYPES));
                return Command::FAILURE;
            }
        } else {
            $codeType = $this->detector->detectCodeType($filePath);
            if (empty($codeType)) {
                $io->error("Could not auto-detect code type from filename: " . basename($filePath));
                $io->note("Supported filename patterns:");
                foreach ($this->detector->getSupportedPatterns() as $type => $patterns) {
                    $io->writeln("  <comment>$type:</comment> " . implode(', ', array_slice($patterns, 0, 2)) . (count($patterns) > 2 ? '...' : ''));
                }
                return Command::FAILURE;
            }
        }

        if (!$this->detector->isSupported($filePath)) {
            $io->error("Unsupported file format: " . basename($filePath));
            return Command::FAILURE;
        }

        // Initialize OpenEMR connection
        try {
            $this->connector->initialize($openemrPath, $site);
        } catch (\Exception $e) {
            $io->error("Failed to initialize OpenEMR connection: " . $e->getMessage());
            return Command::FAILURE;
        }

        // Auto-detect metadata from filename
        $metadata = $this->detector->detectFromFile($filePath, $codeType);
        $usExtension = $metadata['us_extension'];

        // Display configuration with detected metadata
        $io->title("OpenEMR Standardized Codes Import CLI");
        $io->section("Auto-Detected Configuration");
        $io->definitionList(
            ['Code Type' => $metadata['code_type'] ?: $codeType],
            ['Version' => $metadata['version'] ?: 'Unknown'],
            ['Revision Date' => $metadata['revision_date'] ?: 'Unknown'],
            ['File Path' => $filePath],
            ['OpenEMR Path' => $openemrPath],
            ['Site' => $site],
            ['Dry Run' => $dryRun ? 'Yes' : 'No'],
            ['Cleanup' => $cleanup ? 'Yes' : 'No']
        );

        if ($metadata['rf2']) {
            $io->note('Detected RF2 format - using SNOMED RF2 import');
            $codeType = 'SNOMED_RF2';
        }

        if ($codeType === 'RXNORM' && $isWindows) {
            $io->note('Using Windows-specific RXNORM processing');
        }

        if ($usExtension) {
            $io->note('Detected US Extension');
        }

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No database changes will be made');
        }

        if (!$metadata['supported']) {
            $io->warning('File metadata could not be fully detected - tracking may be incomplete');
        }

        // Set custom temp directory if provided
        if ($tempDir) {
            $this->importer->setTempDir($tempDir);
        }

        // Confirm before proceeding
        if (!$io->confirm('Continue with import?', false)) {
            $io->info('Import cancelled');
            return Command::SUCCESS;
        }

        try {
            // Step 1: File handling
            $io->section("Step 1: File Processing");

            $io->info('Copying file to temporary directory...');
            if (!$dryRun) {
                $this->importer->copyFile($filePath, $codeType);
            }
            $io->info('File copied successfully');

            $io->info('Extracting archive...');
            if (!$dryRun) {
                $this->importer->extractFile($filePath, $codeType);
            }
            $io->info('Archive extracted successfully');

            $io->info('File processing complete');

            // Step 2: Database Import
            $io->section("Step 2: Database Import");
            $this->performImport($io, $output, $codeType, $isWindows, $usExtension, $dryRun, $filePath);

            // Step 3: Update tracking
            if (!$dryRun) {
                $io->section("Step 3: Update Tracking");

                if ($metadata['supported'] && $metadata['revision_date'] && $metadata['version']) {
                    $fileChecksum = $metadata['checksum'] ?: md5_file($filePath);
                    // Use SNOMED for tracking regardless of RF1/RF2 format to match OpenEMR web UI expectations
                    $trackingCodeType = ($codeType === 'SNOMED_RF2') ? 'SNOMED' : $codeType;
                    if ($this->importer->updateTracking($trackingCodeType, $metadata['revision_date'], $metadata['version'], $fileChecksum)) {
                        $io->success("Tracking table updated: {$trackingCodeType} v{$metadata['version']} ({$metadata['revision_date']})");
                    } else {
                        $io->warning("Failed to update tracking table");
                    }
                } else {
                    $io->warning("Metadata incomplete - tracking table not updated");
                    $io->note("Missing: " . implode(', ', array_filter([
                        !$metadata['revision_date'] ? 'revision_date' : null,
                        !$metadata['version'] ? 'version' : null,
                        !$metadata['supported'] ? 'supported format' : null
                    ])));
                }
            }

            // Step 4: Cleanup
            if ($cleanup && !$dryRun) {
                $io->section("Step 4: Cleanup");
                $this->importer->cleanup($codeType);
                $io->info("Temporary files cleaned up");
            }

            $io->success("Import completed successfully!");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error("Import failed: " . $e->getMessage());

            // Cleanup on error
            if (!$dryRun) {
                $this->importer->cleanup($codeType);
            }

            return Command::FAILURE;
        }
    }

    private function performImport(SymfonyStyle $io, OutputInterface $output, string $codeType, bool $isWindows, bool $usExtension, bool $dryRun, string $filePath = ''): void
    {
        $io->info("Starting import of $codeType data...");

        if (!$dryRun) {
            $this->importer->import($codeType, $isWindows, $usExtension, $filePath);
        }

        $io->info("$codeType data imported successfully");
    }

    private function is_absolute_path(string $path): bool
    {
        // Unix/Linux absolute path starts with /
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows absolute path starts with drive letter (e.g., C:\)
        if (preg_match('/^[a-zA-Z]:[\/\\\\]/', $path)) {
            return true;
        }

        return false;
    }

}
