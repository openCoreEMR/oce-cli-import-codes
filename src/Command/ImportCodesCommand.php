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
use OpenCoreEMR\CLI\ImportCodes\Exception\CodeImportException;
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
    private OutputInterface $output;

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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force import even if the same version appears to be already loaded')
            ->addOption('lock-retry-attempts', null, InputOption::VALUE_REQUIRED, 'Number of times to retry lock acquisition (default: 10)', 10)
            ->addOption('lock-retry-delay', null, InputOption::VALUE_REQUIRED, 'Initial delay between lock retries in seconds (default: 30, set to 0 for no retries)', 30)
            ->addUsage('/path/to/RxNorm_full_01012024.zip --openemr-path=/var/www/openemr')
            ->addUsage('/path/to/SnomedCT_USEditionRF2_PRODUCTION_20240301T120000Z.zip')
            ->addUsage('/path/to/icd10cm_order_2024.txt.zip --cleanup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $filePath = $input->getArgument('file-path');
        $openemrPath = $input->getOption('openemr-path');
        $site = $input->getOption('site') ?? 'default';
        $isWindows = $input->getOption('windows');
        $dryRun = $input->getOption('dry-run');
        $cleanup = $input->getOption('cleanup');
        $tempDir = $input->getOption('temp-dir');
        $force = $input->getOption('force');
        $lockRetryAttempts = (int) $input->getOption('lock-retry-attempts');
        $lockRetryDelay = (int) $input->getOption('lock-retry-delay');

        // Resolve relative paths to absolute paths
        if (!$this->is_absolute_path($filePath)) {
            $filePath = getcwd() . DIRECTORY_SEPARATOR . $filePath;
        }
        $filePath = realpath($filePath) ?: $filePath;

        // Validate file exists
        if (!file_exists($filePath)) {
            $this->logJson('error', 'File not found', ['file_path' => $filePath]);
            return Command::FAILURE;
        }

        if (!is_dir($openemrPath)) {
            $this->logJson('error', 'OpenEMR path not found', ['openemr_path' => $openemrPath]);
            return Command::FAILURE;
        }

        // Auto-detect code type, or use override
        $codeTypeOverride = $input->getOption('code-type');
        if ($codeTypeOverride) {
            $codeType = strtoupper($codeTypeOverride);
            if (!in_array($codeType, self::SUPPORTED_TYPES)) {
                $this->logJson('error', 'Unsupported code type', ['code_type' => $codeType, 'supported_types' => self::SUPPORTED_TYPES]);
                return Command::FAILURE;
            }
        } else {
            $codeType = $this->detector->detectCodeType($filePath);
            if (empty($codeType)) {
                $this->logJson('error', 'Could not auto-detect code type from filename', [
                    'filename' => basename($filePath),
                    'supported_patterns' => $this->detector->getSupportedPatterns()
                ]);
                return Command::FAILURE;
            }
        }

        if (!$this->detector->isSupported($filePath)) {
            $this->logJson('error', 'Unsupported file format', ['filename' => basename($filePath)]);
            return Command::FAILURE;
        }

        // Initialize OpenEMR connection
        try {
            $this->connector->initialize($openemrPath, $site);
        } catch (\Exception $e) {
            $this->logJson('error', 'Failed to initialize OpenEMR connection', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        // Auto-detect metadata from filename
        $metadata = $this->detector->detectFromFile($filePath, $codeType);
        $usExtension = $metadata['us_extension'];

        // Log configuration with detected metadata
        $this->logJson('info', 'Starting OpenEMR Standardized Codes Import', [
            'code_type' => $metadata['code_type'] ?: $codeType,
            'version' => $metadata['version'] ?: 'Unknown',
            'revision_date' => $metadata['revision_date'] ?: 'Unknown',
            'file_path' => $filePath,
            'openemr_path' => $openemrPath,
            'site' => $site,
            'dry_run' => $dryRun,
            'cleanup' => $cleanup,
            'force_import' => $force
        ]);

        if ($metadata['rf2']) {
            $this->logJson('info', 'Detected RF2 format', ['import_type' => 'SNOMED_RF2']);
            $codeType = 'SNOMED_RF2';
        }

        if ($codeType === 'RXNORM' && $isWindows) {
            $this->logJson('info', 'Using Windows-specific RXNORM processing');
        }

        if ($usExtension) {
            $this->logJson('info', 'Detected US Extension');
        }

        if ($dryRun) {
            $this->logJson('warning', 'DRY RUN MODE - No database changes will be made');
        }

        if (!$metadata['supported']) {
            $this->logJson('warning', 'File metadata could not be fully detected - tracking may be incomplete');
        }

        // Set custom temp directory if provided
        if ($tempDir) {
            $this->importer->setTempDir($tempDir);
        }

        // Configure lock retry behavior
        $this->importer->setLockRetryConfig($lockRetryAttempts, $lockRetryDelay);

        // Check if already loaded (unless force flag is set)
        if (!$force && !$dryRun && $metadata['supported'] && $metadata['revision_date'] && $metadata['version']) {
            $trackingCodeType = ($codeType === 'SNOMED_RF2') ? 'SNOMED' : $codeType;
            $fileChecksum = $metadata['checksum'] ?: md5_file($filePath);

            if ($this->importer->isAlreadyLoaded($trackingCodeType, $metadata['revision_date'], $metadata['version'], $fileChecksum)) {
                $this->logJson('warning', 'Code package appears to be already loaded', [
                    'type' => $trackingCodeType,
                    'version' => $metadata['version'],
                    'revision_date' => $metadata['revision_date'],
                    'suggestion' => 'Use --force flag to import anyway, or --dry-run to test without checking'
                ]);
                return Command::SUCCESS;
            }
        }

        try {
            // Step 1: File handling
            $this->logJson('info', 'Starting file processing');

            $this->logJson('info', 'Copying file to temporary directory');
            if (!$dryRun) {
                $this->importer->copyFile($filePath, $codeType);
            }
            $this->logJson('info', 'File copied successfully');

            $this->logJson('info', 'Extracting archive');
            if (!$dryRun) {
                $this->importer->extractFile($filePath, $codeType);
            }
            $this->logJson('info', 'Archive extracted successfully');

            $this->logJson('info', 'File processing complete');

            // Step 2: Database Import
            $this->logJson('info', 'Starting database import');
            $this->performImport($codeType, $isWindows, $usExtension, $dryRun, $filePath);

            // Step 3: Update tracking
            if (!$dryRun) {
                $this->logJson('info', 'Starting tracking update');

                if ($metadata['supported'] && $metadata['revision_date'] && $metadata['version']) {
                    $fileChecksum = $metadata['checksum'] ?: md5_file($filePath);
                    // Use SNOMED for tracking regardless of RF1/RF2 format to match OpenEMR web UI expectations
                    $trackingCodeType = ($codeType === 'SNOMED_RF2') ? 'SNOMED' : $codeType;
                    if ($this->importer->updateTracking($trackingCodeType, $metadata['revision_date'], $metadata['version'], $fileChecksum)) {
                        $this->logJson('success', 'Tracking table updated', [
                            'type' => $trackingCodeType,
                            'version' => $metadata['version'],
                            'revision_date' => $metadata['revision_date']
                        ]);
                    } else {
                        $this->logJson('warning', 'Failed to update tracking table');
                    }
                } else {
                    $missing = array_filter([
                        !$metadata['revision_date'] ? 'revision_date' : null,
                        !$metadata['version'] ? 'version' : null,
                        !$metadata['supported'] ? 'supported format' : null
                    ]);
                    $this->logJson('warning', 'Metadata incomplete - tracking table not updated', ['missing' => $missing]);
                }
            }

            // Step 4: Cleanup
            if ($cleanup && !$dryRun) {
                $this->logJson('info', 'Starting cleanup');
                $this->importer->cleanup($codeType);
                $this->logJson('info', 'Temporary files cleaned up');
            }

            $this->logJson('success', 'Import completed successfully');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->logJson('error', 'Import failed', ['error' => $e->getMessage()]);

            // Cleanup on error
            if (!$dryRun) {
                $this->importer->cleanup($codeType);
            }

            return Command::FAILURE;
        }
    }

    private function performImport(string $codeType, bool $isWindows, bool $usExtension, bool $dryRun, string $filePath = ''): void
    {
        $this->logJson('info', 'Starting import', ['code_type' => $codeType]);

        if (!$dryRun) {
            try {
                $this->importer->import($codeType, $isWindows, $usExtension, $filePath);
            } catch (\Exception $e) {
                // Check if this is a lock acquisition failure
                if (strpos($e->getMessage(), 'Failed to acquire database lock') !== false) {
                    throw new CodeImportException("Import failed: " . $e->getMessage());
                }
                // Re-throw other exceptions as-is
                throw $e;
            }
        }

        $this->logJson('info', 'Import completed', ['code_type' => $codeType]);
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

    /**
     * Check if a code package is already loaded with the same metadata
     */

    private function logJson(string $level, string $message, array $data = []): void
    {
        $logEntry = [
            'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'level' => strtoupper($level),
            'message' => $message,
            'component' => 'oce-import-codes'
        ];

        if (!empty($data)) {
            $logEntry = array_merge($logEntry, $data);
        }

        $json = json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->output->writeln($json);
    }
}
