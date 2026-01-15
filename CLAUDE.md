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
# Copy a code file into the container and run
docker cp /local/path/to/RxNorm.zip $(docker compose ps -q openemr):/tmp/
task cli:run -- /tmp/RxNorm.zip

# Or run directly via docker compose exec
docker compose exec openemr php /var/www/localhost/htdocs/openemr/oce-cli-import-codes/bin/oce-import-codes import /tmp/codes.zip
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
--openemr-path    Path to OpenEMR installation (default: /var/www/localhost/htdocs/openemr)
--site            OpenEMR site name (default: default)
--code-type       Override auto-detected code type
--dry-run         Test without database changes
--cleanup         Remove temp files after import
--force           Import even if same version already loaded
--lock-retry-attempts   Retry count for database lock (default: 10)
--lock-retry-delay      Initial retry delay in seconds (default: 30)
```

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
