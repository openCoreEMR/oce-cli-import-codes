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

use OpenCoreEMR\CLI\ImportCodes\Config\ConfigAccessorInterface;
use OpenCoreEMR\CLI\ImportCodes\Config\GlobalsAccessor;
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
    private ?OutputInterface $output = null;

    public function __construct(
        ?ConfigAccessorInterface $config = null,
        ?CodeImporter $importer = null,
        ?OpenEMRConnector $connector = null,
        private readonly MetadataDetector $detector = new MetadataDetector()
    ) {
        $config ??= new GlobalsAccessor();
        $this->importer = $importer ?? new CodeImporter($config);
        $this->connector = $connector ?? new OpenEMRConnector($config);
        parent::__construct();
    }

    private readonly CodeImporter $importer;
    private readonly OpenEMRConnector $connector;

    protected function configure()
    {
        $supportedTypes = implode(', ', self::SUPPORTED_TYPES);
        $codeTypeOptions = implode('|', self::SUPPORTED_TYPES);

        $this
            ->setName('import')
            ->setDescription("Import standardized code tables into OpenEMR with automatic detection")
            ->setHelp(
                "This command automatically detects code type, version, and revision from " .
                "filenames and imports medical code tables into OpenEMR.\n\n" .
                "Supported code types: " . $supportedTypes
            )
            ->addArgument('file-path', InputArgument::REQUIRED, 'Path to the code file archive (zip file)')
            ->addOption(
                'code-type',
                null,
                InputOption::VALUE_REQUIRED,
                'Override auto-detected code type (' . $codeTypeOptions . ')'
            )
            ->addOption(
                'openemr-path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to OpenEMR installation',
                '/var/www/localhost/htdocs/openemr'
            )
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Name of OpenEMR site', 'default')
            ->addOption('windows', 'w', InputOption::VALUE_NONE, 'Use Windows-specific processing (RXNORM only)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Perform a dry run without making database changes')
            ->addOption(
                'cleanup',
                null,
                InputOption::VALUE_NEGATABLE,
                'Clean staging directory after import (default: true, use --no-cleanup to disable)'
            )
            ->addOption('temp-dir', null, InputOption::VALUE_REQUIRED, 'Custom temporary directory path')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Force import even if the same version appears to be already loaded'
            )
            ->addOption(
                'lock-retry-attempts',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of times to retry lock acquisition (default: 10)',
                10
            )
            ->addOption(
                'lock-retry-delay',
                null,
                InputOption::VALUE_REQUIRED,
                'Delay between lock retries in seconds (default: 30, 0 for no retries)',
                30
            )
            ->addOption(
                'allow-unsupported',
                null,
                InputOption::VALUE_NONE,
                'Allow importing code files not in supported_external_dataloads table'
            )
            ->addUsage('/path/to/RxNorm_full_01012024.zip --openemr-path=/var/www/openemr')
            ->addUsage('/path/to/SnomedCT_USEditionRF2_PRODUCTION_20240301T120000Z.zip')
            ->addUsage('/path/to/icd10cm_order_2024.txt.zip --cleanup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        /** @var string $filePath */
        $filePath = $input->getArgument('file-path');
        /** @var string $openemrPath */
        $openemrPath = $input->getOption('openemr-path');
        /** @var string $site */
        $site = $input->getOption('site') ?? 'default';
        /** @var bool $isWindows */
        $isWindows = $input->getOption('windows');
        /** @var bool $dryRun */
        $dryRun = $input->getOption('dry-run');
        /** @var ?bool $cleanupOpt */
        $cleanupOpt = $input->getOption('cleanup');
        $cleanup = $cleanupOpt ?? true; // Default to true if not specified
        /** @var string|null $tempDir */
        $tempDir = $input->getOption('temp-dir');
        /** @var bool $force */
        $force = $input->getOption('force');
        /** @var int|string|null $lockRetryAttemptsOpt */
        $lockRetryAttemptsOpt = $input->getOption('lock-retry-attempts');
        $lockRetryAttempts = (int) ($lockRetryAttemptsOpt ?? 10);
        /** @var int|string|null $lockRetryDelayOpt */
        $lockRetryDelayOpt = $input->getOption('lock-retry-delay');
        $lockRetryDelay = (int) ($lockRetryDelayOpt ?? 30);

        // Resolve relative paths to absolute paths
        if (!$this->isAbsolutePath($filePath)) {
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
        /** @var string|null $codeTypeOverride */
        $codeTypeOverride = $input->getOption('code-type');
        if ($codeTypeOverride !== null && $codeTypeOverride !== '') {
            $codeType = strtoupper($codeTypeOverride);
            if (!in_array($codeType, self::SUPPORTED_TYPES)) {
                $this->logJson('error', 'Unsupported code type', [
                    'code_type' => $codeType,
                    'supported_types' => self::SUPPORTED_TYPES
                ]);
                return Command::FAILURE;
            }
        } else {
            $codeType = $this->detector->detectCodeType($filePath);
            if ($codeType === '' || $codeType === '0') {
                $this->logJson('error', 'Could not auto-detect code type from filename', [
                    'filename' => basename((string) $filePath),
                    'supported_patterns' => $this->detector->getSupportedPatterns()
                ]);
                return Command::FAILURE;
            }
        }

        if (!$this->detector->isSupported($filePath)) {
            $this->logJson('error', 'Unsupported file format', ['filename' => basename((string) $filePath)]);
            return Command::FAILURE;
        }

        // Initialize OpenEMR connection
        try {
            $this->connector->initialize($openemrPath, $site);
        } catch (\Throwable $e) {
            $this->logJson('error', 'Failed to initialize OpenEMR connection', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        // Auto-detect metadata from filename
        /** @var bool $allowUnsupported */
        $allowUnsupported = $input->getOption('allow-unsupported');
        $metadata = $this->detector->detectFromFile($filePath, $codeType, $allowUnsupported);
        $usExtension = (bool) ($metadata['us_extension'] ?? false);

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

        // Only ICD code types use the supported_external_dataloads table for validation.
        // Other types (RXNORM, SNOMED, CQM_VALUESET) are validated by filename patterns only.
        $usesExternalDataloads = in_array($codeType, ['ICD9', 'ICD10'], true);

        if ($usesExternalDataloads && !$metadata['from_database']) {
            if (!$allowUnsupported) {
                $this->logJson('error', 'File not in supported_external_dataloads table', [
                    'filename' => basename($filePath),
                    'code_type' => $codeType,
                    'suggestion' => 'Use --allow-unsupported to bypass this check'
                ]);
                return Command::FAILURE;
            }
            $this->logJson('warning', 'File not in supported_external_dataloads - using filename-based metadata');
        }

        // Set custom temp directory if provided
        if ($tempDir) {
            $this->importer->setTempDir($tempDir);
        }

        // Configure lock retry behavior
        $this->importer->setLockRetryConfig($lockRetryAttempts, $lockRetryDelay);

        // Check if already loaded (unless force flag is set)
        $revisionDate = isset($metadata['revision_date']) && is_string($metadata['revision_date'])
            ? $metadata['revision_date'] : '';
        $version = isset($metadata['version']) && is_string($metadata['version'])
            ? $metadata['version'] : '';
        $checksum = isset($metadata['checksum']) && is_string($metadata['checksum'])
            ? $metadata['checksum'] : '';

        if (!$force && !$dryRun && $metadata['supported'] && $revisionDate !== '' && $version !== '') {
            $trackingCodeType = ($codeType === 'SNOMED_RF2') ? 'SNOMED' : $codeType;
            $fileChecksum = $checksum !== '' ? $checksum : (md5_file($filePath) ?: '');

            $isLoaded = $this->importer->isAlreadyLoaded(
                $trackingCodeType,
                $revisionDate,
                $version,
                $fileChecksum
            );
            if ($isLoaded) {
                $this->logJson('warning', 'Code package appears to be already loaded', [
                    'type' => $trackingCodeType,
                    'version' => $metadata['version'],
                    'revision_date' => $metadata['revision_date'],
                    'suggestion' => 'Use --force flag to import anyway, or --dry-run to test without checking'
                ]);
                return Command::SUCCESS;
            }
        }

        // Check for existing files in staging directory (potential for duplicates)
        if (!$dryRun) {
            $existingFiles = $this->importer->getStagingFiles($codeType);
            if ($existingFiles !== []) {
                $this->logJson('warning', 'Staging directory already contains files', [
                    'staging_dir' => $codeType,
                    'file_count' => count($existingFiles),
                    'files' => array_slice($existingFiles, 0, 10), // Show first 10
                    'risk' => 'These files may be imported along with your new file, causing duplicates',
                    'suggestion' => 'Run "task db:clean-vocabs -- ' . $codeType . '" to clean staging first'
                ]);
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

                if ($metadata['supported'] && $revisionDate !== '' && $version !== '') {
                    $fileChecksum = $checksum !== '' ? $checksum : (md5_file($filePath) ?: '');
                    // Use SNOMED for tracking regardless of RF1/RF2 format to match OpenEMR web UI expectations
                    $trackingCodeType = ($codeType === 'SNOMED_RF2') ? 'SNOMED' : $codeType;
                    $updated = $this->importer->updateTracking(
                        $trackingCodeType,
                        $revisionDate,
                        $version,
                        $fileChecksum
                    );
                    if ($updated) {
                        $this->logJson('success', 'Tracking table updated', [
                            'type' => $trackingCodeType,
                            'version' => $version,
                            'revision_date' => $revisionDate
                        ]);
                    } else {
                        $this->logJson('warning', 'Failed to update tracking table');
                    }
                } else {
                    $missing = array_filter([
                        $revisionDate !== '' ? null : 'revision_date',
                        $version !== '' ? null : 'version',
                        $metadata['supported'] ? null : 'supported format'
                    ]);
                    $this->logJson(
                        'warning',
                        'Metadata incomplete - tracking table not updated',
                        ['missing' => $missing]
                    );
                }
            }

            // Step 4: Cleanup (default behavior to prevent duplicate imports)
            if ($cleanup && !$dryRun) {
                $this->logJson('info', 'Cleaning up staging directory');
                $this->importer->cleanup($codeType);
                $this->logJson('info', 'Staging directory cleaned');
            } elseif (!$cleanup && !$dryRun) {
                $this->logJson(
                    'warning',
                    'Staging files kept (--no-cleanup). Clean before next import to avoid duplicates.'
                );
            }

            $this->logJson('success', 'Import completed successfully');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logJson('error', 'Import failed', ['error' => $e->getMessage()]);

            // Cleanup on error
            if (!$dryRun) {
                $this->importer->cleanup($codeType);
            }

            return Command::FAILURE;
        }
    }

    private function performImport(
        string $codeType,
        bool $isWindows,
        bool $usExtension,
        bool $dryRun,
        string $filePath = ''
    ): void {
        $this->logJson('info', 'Starting import', ['code_type' => $codeType]);

        if (!$dryRun) {
            try {
                $this->importer->import($codeType, $isWindows, $usExtension, $filePath);
            } catch (\Throwable $e) {
                // Check if this is a lock acquisition failure
                if (str_contains($e->getMessage(), 'Failed to acquire database lock')) {
                    throw new CodeImportException("Import failed: " . $e->getMessage());
                }
                // Re-throw other exceptions as-is
                throw $e;
            }
        }

        $this->logJson('info', 'Import completed', ['code_type' => $codeType]);
    }

    private function isAbsolutePath(string $path): bool
    {
        // Unix/Linux absolute path starts with /
        if (str_starts_with($path, '/')) {
            return true;
        }
        // Windows absolute path starts with drive letter (e.g., C:\)
        return (bool) preg_match('/^[a-zA-Z]:[\/\\\\]/', $path);
    }

    /**
     * Log a JSON-formatted message to the output
     *
     * @param array<string, mixed> $data
     */
    private function logJson(string $level, string $message, array $data = []): void
    {
        if (!$this->output instanceof \Symfony\Component\Console\Output\OutputInterface) {
            return;
        }

        $logEntry = [
            'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'level' => strtoupper($level),
            'message' => $message,
            'component' => 'oce-import-codes'
        ];

        if ($data !== []) {
            $logEntry = array_merge($logEntry, $data);
        }

        $json = json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            $this->output->writeln($json);
        }
    }
}
