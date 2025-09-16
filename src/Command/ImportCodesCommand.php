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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

class ImportCodesCommand extends Command
{
    private const SUPPORTED_TYPES = ['RXNORM', 'SNOMED', 'SNOMED_RF2', 'ICD9', 'ICD10', 'CQM_VALUESET'];

    private CodeImporter $importer;
    private OpenEMRConnector $connector;

    public function __construct(CodeImporter $importer = null, OpenEMRConnector $connector = null)
    {
        parent::__construct();
        $this->importer = $importer ?? new CodeImporter();
        $this->connector = $connector ?? new OpenEMRConnector();
    }

    protected function configure()
    {
        $this
            ->setName('import')
            ->setDescription("Import standardized code tables (RXNORM, SNOMED, ICD, CQM_VALUESET) into OpenEMR")
            ->setHelp("This command imports standardized medical code tables into OpenEMR from mounted files.\n\nSupported code types: " . implode(', ', self::SUPPORTED_TYPES))
            ->addArgument('code-type', InputArgument::REQUIRED, 'Type of codes to import (' . implode('|', self::SUPPORTED_TYPES) . ')')
            ->addArgument('file-path', InputArgument::REQUIRED, 'Path to the code file archive (zip file)')
            ->addOption('openemr-path', null, InputOption::VALUE_REQUIRED, 'Path to OpenEMR installation', '/var/www/localhost/htdocs/openemr')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Name of OpenEMR site', 'default')
            ->addOption('windows', 'w', InputOption::VALUE_NONE, 'Use Windows-specific processing (RXNORM only)')
            ->addOption('us-extension', null, InputOption::VALUE_NONE, 'Import as US extension (SNOMED only)')
            ->addOption('revision', null, InputOption::VALUE_REQUIRED, 'Revision date for tracking (YYYY-MM-DD format)')
            ->addOption('version', null, InputOption::VALUE_REQUIRED, 'Version string for tracking')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Perform a dry run without making database changes')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Clean up temporary files after import')
            ->addOption('temp-dir', null, InputOption::VALUE_REQUIRED, 'Custom temporary directory path')
            ->addUsage('RXNORM /path/to/rxnorm.zip --openemr-path=/var/www/openemr --revision=2024-01-01 --version=2024AA')
            ->addUsage('SNOMED /path/to/snomed.zip --us-extension --openemr-path=/var/www/openemr')
            ->addUsage('ICD10 /path/to/icd10.zip --cleanup --openemr-path=/var/www/openemr');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $codeType = strtoupper($input->getArgument('code-type'));
        $filePath = $input->getArgument('file-path');
        $openemrPath = $input->getOption('openemr-path');
        $site = $input->getOption('site') ?? 'default';
        $isWindows = $input->getOption('windows');
        $usExtension = $input->getOption('us-extension');
        $revision = $input->getOption('revision');
        $version = $input->getOption('version');
        $dryRun = $input->getOption('dry-run');
        $cleanup = $input->getOption('cleanup');
        $tempDir = $input->getOption('temp-dir');

        // Validate inputs
        if (!in_array($codeType, self::SUPPORTED_TYPES)) {
            $io->error("Unsupported code type: $codeType. Supported types: " . implode(', ', self::SUPPORTED_TYPES));
            return Command::FAILURE;
        }

        if (!file_exists($filePath)) {
            $io->error("File not found: $filePath");
            return Command::FAILURE;
        }

        if (!is_dir($openemrPath)) {
            $io->error("OpenEMR path not found: $openemrPath");
            return Command::FAILURE;
        }

        if ($revision && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $revision)) {
            $io->error("Invalid revision format. Use YYYY-MM-DD format.");
            return Command::FAILURE;
        }

        // Initialize OpenEMR connection
        try {
            $this->connector->initialize($openemrPath, $site);
        } catch (\Exception $e) {
            $io->error("Failed to initialize OpenEMR connection: " . $e->getMessage());
            return Command::FAILURE;
        }

        // Display configuration
        $io->title("OpenEMR Standardized Codes Import CLI");
        $io->section("Configuration");
        $io->definitionList(
            ['Code Type' => $codeType],
            ['File Path' => $filePath],
            ['OpenEMR Path' => $openemrPath],
            ['Site' => $site],
            ['Dry Run' => $dryRun ? 'Yes' : 'No'],
            ['Cleanup' => $cleanup ? 'Yes' : 'No']
        );

        if ($codeType === 'RXNORM' && $isWindows) {
            $io->note('Using Windows-specific RXNORM processing');
        }

        if ($codeType === 'SNOMED' && $usExtension) {
            $io->note('Importing as US extension');
        }

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No database changes will be made');
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
            $progressBar = new ProgressBar($output, 3);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');

            $progressBar->setMessage('Copying file to temporary directory...');
            $progressBar->start();

            if (!$dryRun) {
                $this->importer->copyFile($filePath, $codeType);
            }
            $progressBar->advance();

            $progressBar->setMessage('Extracting archive...');
            if (!$dryRun) {
                $this->importer->extractFile($filePath, $codeType);
            }
            $progressBar->advance();

            $progressBar->setMessage('File processing complete');
            $progressBar->advance();
            $progressBar->finish();
            $io->newLine(2);

            // Step 2: Database Import
            $io->section("Step 2: Database Import");
            $this->performImport($io, $output, $codeType, $isWindows, $usExtension, $dryRun);

            // Step 3: Update tracking
            if ($revision && $version && !$dryRun) {
                $io->section("Step 3: Update Tracking");
                $fileChecksum = md5_file($filePath);
                if ($this->importer->updateTracking($codeType, $revision, $version, $fileChecksum)) {
                    $io->success("Tracking table updated successfully");
                } else {
                    $io->warning("Failed to update tracking table");
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

    private function performImport(SymfonyStyle $io, OutputInterface $output, string $codeType, bool $isWindows, bool $usExtension, bool $dryRun): void
    {
        $progressBar = new ProgressBar($output, 1);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');

        $progressBar->setMessage("Importing $codeType data...");
        $progressBar->start();

        if (!$dryRun) {
            $this->importer->import($codeType, $isWindows, $usExtension);
        }

        $progressBar->advance();
        $progressBar->finish();
        $io->newLine(2);
        $io->info("$codeType data imported successfully");
    }
}