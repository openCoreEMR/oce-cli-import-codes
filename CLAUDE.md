# OpenEMR CLI Import Codes - Development Guide

This CLI tool imports standardized medical code tables (RXNORM, SNOMED, ICD, CQM_VALUESET) into OpenEMR.

## Quick Reference

### Common Commands

```bash
# Start development environment
task dev:start

# Run the CLI tool
task cli:run -- /path/to/codes.zip

# Run in dry-run mode (no database changes)
task cli:dry-run -- /path/to/codes.zip

# Show CLI help
task cli:help

# Run all code quality checks
task check

# Run tests
task test
```

### Supported Code Types

| Type | Description | Example Filename |
|------|-------------|------------------|
| RXNORM | Drug nomenclature | `RxNorm_full_01012024.zip` |
| SNOMED | Clinical terminology | `SnomedCT_USEditionRF2_PRODUCTION_*.zip` |
| SNOMED_RF2 | SNOMED RF2 format | Auto-detected from RF2 structure |
| ICD10 | ICD-10-CM diagnosis codes | `icd10cm_order_2024.txt.zip` |
| ICD9 | ICD-9 diagnosis codes | `icd9cm_order_*.zip` |
| CQM_VALUESET | Clinical Quality Measures | `ec_only_*.xml.zip` |

## Architecture

### How It Works

1. CLI bootstraps OpenEMR's environment by including `globals.php`
2. Uses OpenEMR's database functions (`sqlStatement`, `sqlQuery`)
3. Calls OpenEMR's `standard_tables_capture.inc.php` for actual import logic
4. Tracks imported versions in `standardized_tables_track` table

### Key Files

```
src/
├── Command/
│   └── ImportCodesCommand.php    # Main CLI command
├── Service/
│   ├── OpenEMRConnector.php      # OpenEMR environment bootstrap
│   ├── CodeImporter.php          # Import orchestration
│   └── MetadataDetector.php      # Auto-detect code type/version
└── Exception/
    └── *.php                     # Custom exceptions
```

### OpenEMR Integration

The CLI must run from within an OpenEMR environment (or container) because it:
- Includes `interface/globals.php` to bootstrap OpenEMR
- Uses OpenEMR's ADODB database connection
- Calls functions from `library/standard_tables_capture.inc.php`

## Development Environment

### Docker Setup

The `compose.yml` extends OpenEMR's development-easy Docker setup:
- OpenEMR is installed as a Composer dev dependency
- CLI tool is mounted at `/var/www/localhost/htdocs/openemr/oce-cli-import-codes`
- Random ports avoid conflicts with other projects

### First-Time Setup

```bash
# Install dependencies (includes OpenEMR)
task composer:install

# Pre-build OpenEMR (speeds up Docker start)
task openemr:prebuild

# Start Docker environment
task dev:start

# Get OpenEMR URL
task dev:port
```

### Running the CLI

```bash
# Files in .local/vocabs/ are accessible inside the container
task cli:run -- /var/www/localhost/htdocs/openemr/oce-cli-import-codes/.local/vocabs/codes.zip

# Or copy a file into the container first
docker cp /local/path/to/RxNorm.zip $(docker compose ps -q openemr):/tmp/
task cli:run -- /tmp/RxNorm.zip

# Or run directly via docker compose exec
docker compose exec openemr php /var/www/localhost/htdocs/openemr/oce-cli-import-codes/bin/oce-import-codes /tmp/codes.zip
```

## Code Quality

### Tools

| Tool | Purpose | Command |
|------|---------|---------|
| PHPCS | Code style (PSR-12) | `composer phpcs` |
| PHPCBF | Auto-fix code style | `composer phpcbf` |
| PHPStan | Static analysis (level 9) | `composer phpstan` |
| Rector | Code modernization | `composer rector` |

### Pre-commit Hooks

Install pre-commit for automated checks:

```bash
pip install pre-commit
pre-commit install
```

Hooks run automatically on commit, or manually:

```bash
pre-commit run -a
# or
task check
```

## Database Operations

```bash
# Access database shell
task db:shell

# Show loaded code counts
task db:show-codes

# Show import tracking history
task db:tracking

# Run ad-hoc query
task db:query -- "SELECT * FROM standardized_tables_track"
```

## CLI Options

```
--openemr-path        Path to OpenEMR installation (default: /var/www/localhost/htdocs/openemr)
--site                OpenEMR site name (default: default)
--code-type           Override auto-detected code type
--dry-run             Test without database changes
--cleanup/--no-cleanup  Clean staging directory after import (default: --cleanup)
--force               Import even if same version already loaded
--allow-unsupported   Required for files not in supported_external_dataloads table
--lock-retry-attempts Retry count for database lock (default: 10)
--lock-retry-delay    Initial retry delay in seconds (default: 30)
```

### Staging Directory Cleanup

By default, the CLI cleans the staging directory (`/tmp/{CODE_TYPE}/`) after each import. This prevents duplicate imports when running multiple times.

- **Default behavior**: Staging directory is cleaned after successful import
- **`--no-cleanup`**: Keep staging files for debugging (warning issued about duplicate risk)
- **Startup warning**: If existing files are found in staging before import, a warning is logged

To manually clean staging directories:

```bash
task db:clean-vocabs -- ICD10   # Clean specific vocab type
task db:clean-vocabs            # Clean all vocab types
```

### Importing Unsupported Code Versions

OpenEMR validates code files by checking the filename and MD5 checksum against the `supported_external_dataloads` table. This table ships with each OpenEMR release and only contains entries for code versions known at release time.

**By default, the CLI will reject files not in this table.** To import newer codes (e.g., 2026 ICD codes on an OpenEMR version that only knows about 2025), use the `--allow-unsupported` flag:

```bash
task cli:run -- /path/to/icd10orderfiles.zip --allow-unsupported
```

**How it works:**

1. Checks the `supported_external_dataloads` table for filename + checksum match
2. If not found, fails with an error (unless `--allow-unsupported` is set)
3. With `--allow-unsupported`, parses metadata from the filename instead
4. Extracts the year from filename patterns and calculates the release date

**Supported filename patterns:**

| Pattern | Example | Detected As |
|---------|---------|-------------|
| `icd10OrderFiles{YEAR}` | `icd10OrderFiles2025_0.zip` | ICD10 CM, effective {YEAR-1}-10-01 |
| `icd10cm_order_{YEAR}` | `icd10cm_order_2024.txt.zip` | ICD10 CM, effective {YEAR-1}-10-01 |
| `*{YEAR}*ICD-10-PCS*` | `Zip File 3 2026 ICD-10-PCS Codes File.zip` | ICD10 PCS, effective {YEAR-1}-10-01 |
| `icd10orderfiles.zip` | (no year) | ICD10 CM, uses current fiscal year |

ICD codes become effective October 1st, so "2026 codes" have release date 2025-10-01.

## Troubleshooting

### Common Issues

**"OpenEMR globals.php not found"**
- Ensure `--openemr-path` points to correct OpenEMR installation
- In Docker, use `/var/www/localhost/htdocs/openemr`

**"Failed to acquire database lock"**
- Another import may be running
- Increase `--lock-retry-attempts` or `--lock-retry-delay`
- Check for stuck processes

**Code type detection fails**
- Use `--code-type` to manually specify
- Check filename matches expected patterns

### Viewing Logs

```bash
# OpenEMR container logs
task dev:logs

# Error logs only
task dev:logs:errors
```
