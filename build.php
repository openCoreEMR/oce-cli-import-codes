#!/usr/bin/env php
<?php

/**
 * Build script for creating oce-import-codes.phar
 *
 * @package   OpenCoreEMR\CLI\ImportCodes
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

// Check if we're running from CLI
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

// Check if phar.readonly is disabled
if (ini_get('phar.readonly')) {
    echo "Error: phar.readonly is enabled. Run with: php -d phar.readonly=0 build.php\n";
    exit(1);
}

$buildDir = __DIR__ . '/build';
$pharFile = $buildDir . '/oce-import-codes.phar';

echo "Building oce-import-codes.phar...\n";

// Create build directory
if (!is_dir($buildDir)) {
    mkdir($buildDir, 0755, true);
}

// Remove existing phar
if (file_exists($pharFile)) {
    unlink($pharFile);
}

try {
    $phar = new Phar($pharFile);

    // Set metadata
    $phar->setMetadata([
        'name' => 'oce-import-codes',
        'version' => '1.0.0',
        'created' => date('Y-m-d H:i:s')
    ]);

    // Start buffering
    $phar->startBuffering();

    // Add source files
    echo "Adding source files...\n";
    $phar->buildFromDirectory(__DIR__, '/^(?!.*\/(?:build|tests|\.git)).*$/');

    // Set stub with proper shebang
    $stub = "#!/usr/bin/env php\n" . $phar->createDefaultStub('bin/oce-import-codes');
    $phar->setStub($stub);

    // Stop buffering and write
    $phar->stopBuffering();

    // Compress
    echo "Compressing...\n";
    $phar->compressFiles(Phar::GZ);

    // Make executable
    chmod($pharFile, 0755);

    echo "✅ PHAR built successfully: $pharFile\n";
    echo "   Size: " . number_format(filesize($pharFile)) . " bytes\n";
    echo "   Usage: ./build/oce-import-codes.phar RXNORM /path/to/rxnorm.zip --openemr-path=/var/www/openemr\n";

} catch (Exception $e) {
    echo "❌ Error building PHAR: " . $e->getMessage() . "\n";
    exit(1);
}
