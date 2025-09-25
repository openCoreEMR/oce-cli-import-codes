# OpenCoreEMR Codes Import CLI

A standalone CLI tool for importing standardized medical code tables (RXNORM, SNOMED, ICD, CQM_VALUESET) into OpenEMR. Designed for Docker/Kubernetes deployments with efficient file mounting and reuse across multiple installations.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://php.net)

## Features

- ✅ **Standalone CLI tool** - No need to modify OpenEMR core files
- ✅ **Composer installable** - Easy installation and updates
- ✅ **Auto-detection** - Automatically detects code type from filename
- ✅ **Non-interactive** - Runs without user prompts for automation
- ✅ **Progress feedback** - Real-time progress bars and status updates
- ✅ **Docker/Kubernetes optimized** - Perfect for containerized deployments
- ✅ **Multi-site support** - Import codes across multiple OpenEMR sites
- ✅ **Dry-run capability** - Test imports without database changes
- ✅ **Comprehensive logging** - Track imports with revision/version info
- ✅ **Error handling** - Automatic cleanup on failures

## Supported Code Types

| Code Type | Description | Source |
|-----------|-------------|---------|
| **RXNORM** | RxNorm drug terminology | [NLM](https://www.nlm.nih.gov/research/umls/rxnorm/) |
| **SNOMED** | SNOMED CT clinical terminology (RF1) | [NLM](https://www.nlm.nih.gov/healthit/snomedct/) |
| **SNOMED_RF2** | SNOMED CT clinical terminology (RF2) | [NLM](https://www.nlm.nih.gov/healthit/snomedct/) |
| **ICD9** | ICD-9-CM diagnosis codes | [CMS](https://www.cms.gov/) |
| **ICD10** | ICD-10-CM/PCS codes | [CMS](https://www.cms.gov/medicare/icd-10/) |
| **CQM_VALUESET** | Clinical Quality Measures value sets | [eCQI](https://ecqi.healthit.gov/) |

## Installation

### Download PHAR (Recommended)

Download the latest PHAR release:

```bash
# Download the PHAR
curl -L -o oce-import-codes.phar https://github.com/opencoreemr/oce-cli-import-codes/releases/latest/download/oce-import-codes.phar

# Make executable
chmod +x oce-import-codes.phar

# Optional: Move to PATH
sudo mv oce-import-codes.phar /usr/local/bin/oce-import-codes
```

### Via Composer

```bash
composer require opencoreemr/oce-cli-import-codes
```

### Build from Source

```bash
git clone https://github.com/opencoreemr/oce-cli-import-codes.git
cd oce-cli-import-codes
composer install
php -d phar.readonly=0 build.php
```

## Quick Start

```bash
# Using PHAR (recommended) - auto-detects RXNORM from filename
./oce-import-codes.phar /path/to/RxNorm_full_01012024.zip --openemr-path=/var/www/openemr

# Or if installed to PATH
oce-import-codes /path/to/SnomedCT_USEditionRF2_PRODUCTION_20240301T120000Z.zip --openemr-path=/var/www/openemr

# Override auto-detection if needed
oce-import-codes /path/to/custom-name.zip --code-type=SNOMED --openemr-path=/var/www/openemr

# ICD10 with cleanup - auto-detects from filename
oce-import-codes /path/to/icd10cm_order_2024.txt.zip \
  --openemr-path=/var/www/openemr \
  --cleanup

# Dry run to test
oce-import-codes /path/to/RxNorm_full_01012024.zip --openemr-path=/var/www/openemr --dry-run
```

## Usage

### Command Syntax

```bash
oce-import-codes [OPTIONS] <file-path>
```

**Auto-Detection**: The tool automatically detects the code type from the filename. If detection fails, you can manually specify the code type using `--code-type`.

### Arguments

| Argument | Description | Required |
|----------|-------------|----------|
| `file-path` | Path to the code archive file | Yes |

### Options

| Option | Description | Default |
|--------|-------------|---------|
| `--code-type` | Override auto-detected code type (RXNORM\|SNOMED\|SNOMED_RF2\|ICD9\|ICD10\|CQM_VALUESET) | Auto-detect |
| `--openemr-path` | Path to OpenEMR installation | `/var/www/localhost/htdocs/openemr` |
| `--site` | OpenEMR site name | `default` |
| `--windows` | Use Windows processing (RXNORM only) | `false` |
| `--us-extension` | Import as US extension (SNOMED only) | `false` |
| `--revision` | Revision date (YYYY-MM-DD format) | Auto-detect |
| `--code-version` | Version string for tracking | Auto-detect |
| `--dry-run` | Test without database changes | `false` |
| `--cleanup` | Remove temp files after import | `false` |
| `--temp-dir` | Custom temporary directory | - |

## Docker/Kubernetes Deployment

### Docker Example

```dockerfile
FROM openemr/openemr:latest

# Install the CLI tool
RUN composer global require opencoreemr/oce-cli-import-codes

# Mount point for code files
VOLUME ["/var/lib/openemr/codes"]

# Import script
COPY import-codes.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/import-codes.sh
```

## File Requirements

### Directory Structure

Mount your code files to a consistent location:

```
/var/lib/openemr/codes/
├── rxnorm/
│   └── RxNorm_full_MMDDYYYY.zip
├── snomed/
│   ├── SnomedCT_InternationalRF2_PRODUCTION_YYYYMMDD.zip
│   └── SnomedCT_RF1Release_INT_YYYYMMDD.zip
├── icd10/
│   ├── icd10cm_order_YYYY.txt.zip
│   └── icd10pcs_order_YYYY.txt.zip
└── cqm/
    └── EP_EC_AH_CMS_ValueSets_v*.xml.zip
```

### File Sizes & Update Frequency

| Code Type | Compressed Size | Extracted Size | Update Frequency |
|-----------|----------------|----------------|------------------|
| RXNORM | ~200MB | ~2GB | Monthly |
| SNOMED | ~300MB | ~1.5GB | Biannual |
| ICD-10 | ~15MB | ~50MB | Annual |
| CQM ValueSets | ~50MB | ~200MB | Annual |

## Multi-Site Import


## Troubleshooting

### Common Issues

1. **OpenEMR not found**
   ```
   Error: OpenEMR globals.php not found
   ```
   - Verify `--openemr-path` points to correct installation
   - Ensure OpenEMR is properly installed

2. **Permission errors**
   ```
   Error: Temporary directory is not writable
   ```
   - Check write permissions on temp directory
   - Use `--temp-dir` to specify writable location

3. **Database connection failed**
   ```
   Error: OpenEMR database configuration not found
   ```
   - Verify OpenEMR database configuration
   - Ensure site configuration is correct

4. **Import function not found**
   ```
   Error: OpenEMR rxnorm_import function not available
   ```
   - Verify OpenEMR version compatibility
   - Check that `library/standard_tables_capture.inc.php` exists

### Debug Mode

Run with verbose output to see detailed information:

```bash
oce-import-codes RXNORM /path/to/file.zip --openemr-path=/var/www/openemr -v
```

### Log Files

Check OpenEMR logs for detailed error information:
- OpenEMR logs: `/var/log/openemr/`
- PHP error logs: Usually in `/var/log/php/`
- Apache/Nginx error logs

## Contributing

We welcome contributions! Please see our [Contributing Guide](CONTRIBUTING.md) for details.

## License

This project is licensed under the GPL-3.0-or-later License - see the [LICENSE](LICENSE) file for details.

## Support

- **Issues**: [GitHub Issues](https://github.com/opencoreemr/oce-cli-import-codes/issues)
- **Email**: support@opencoreemr.com
- **Documentation**: [OpenEMR Wiki](https://www.open-emr.org/wiki/)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

---

Made with ❤️ by [OpenCoreEMR Inc](https://opencoreemr.com)
