# Installation Guide

## Requirements

- PHP 8.2 or higher
- Composer
- OpenEMR installation
- Required PHP extensions: zip, xml, simplexml

## Installation Methods

### Method 1: Global Composer Installation (Recommended)

Install the tool globally so it's available system-wide:

```bash
composer global require opencoreemr/oce-cli-import-codes
```

After installation, the `oce-import-codes` command will be available globally.

### Method 2: Local Project Installation

Install as a dependency in your project:

```bash
composer require opencoreemr/oce-cli-import-codes
```

Run using:
```bash
./vendor/bin/oce-import-codes
```

### Method 3: Git Clone Installation

For development or custom installations:

```bash
git clone https://github.com/opencoreemr/oce-cli-import-codes.git
cd oce-cli-import-codes
composer install
chmod +x bin/oce-import-codes
```

Run using:
```bash
./bin/oce-import-codes
```

## Docker Installation

### Docker Image with CLI Pre-installed

Create a custom OpenEMR image with the CLI tool:

```dockerfile
FROM openemr/openemr:latest

# Install Composer if not present
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
    php -r "unlink('composer-setup.php');"

# Install the CLI tool globally
RUN composer global require opencoreemr/oce-cli-import-codes

# Ensure the global composer bin is in PATH
ENV PATH="$PATH:/root/.composer/vendor/bin"

# Create mount point for code files
VOLUME ["/var/lib/openemr/codes"]
```

### Build and Use

```bash
# Build image
docker build -t openemr-with-cli .

# Run with volume mount
docker run -v /host/codes:/var/lib/openemr/codes openemr-with-cli \
  oce-import-codes RXNORM /var/lib/openemr/codes/rxnorm.zip \
  --openemr-path=/var/www/localhost/htdocs/openemr
```

## Kubernetes Installation

### Using Init Container

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: openemr
spec:
  template:
    spec:
      initContainers:
      - name: install-cli
        image: composer:2
        command:
        - /bin/sh
        - -c
        - composer global require opencoreemr/oce-cli-import-codes
        volumeMounts:
        - name: composer-home
          mountPath: /root/.composer
      containers:
      - name: openemr
        image: openemr/openemr:latest
        env:
        - name: PATH
          value: "$PATH:/root/.composer/vendor/bin"
        volumeMounts:
        - name: composer-home
          mountPath: /root/.composer
        - name: codes-volume
          mountPath: /var/lib/openemr/codes
      volumes:
      - name: composer-home
        emptyDir: {}
      - name: codes-volume
        persistentVolumeClaim:
          claimName: codes-pvc
```

### Using Sidecar Container

```yaml
apiVersion: v1
kind: Pod
spec:
  containers:
  - name: openemr
    image: openemr/openemr:latest
  - name: codes-importer
    image: opencoreemr/oce-cli-import-codes:latest
    command: ["/bin/sh", "-c", "while true; do sleep 3600; done"]
    volumeMounts:
    - name: codes-volume
      mountPath: /var/lib/openemr/codes
```

## Verification

After installation, verify the tool is working:

```bash
# Check version
oce-import-codes --version

# Show help
oce-import-codes --help

# Test with dry-run
oce-import-codes RXNORM /path/to/test.zip --openemr-path=/var/www/openemr --dry-run
```

## Troubleshooting Installation

### Composer Global Path Issues

If `oce-import-codes` command is not found after global installation:

```bash
# Check global composer bin directory
composer global config bin-dir --absolute

# Add to your shell profile (.bashrc, .zshrc, etc.)
export PATH="$PATH:$HOME/.composer/vendor/bin"

# Or create symlink
ln -s $HOME/.composer/vendor/bin/oce-import-codes /usr/local/bin/
```

### Permission Issues

```bash
# Make binary executable
chmod +x $(which oce-import-codes)

# For Docker installations
docker exec -it container_name chmod +x /root/.composer/vendor/bin/oce-import-codes
```

### PHP Extensions

Ensure required extensions are installed:

```bash
# Check extensions
php -m | grep -E "(zip|xml|simplexml)"

# Ubuntu/Debian
sudo apt-get install php-zip php-xml

# CentOS/RHEL
sudo yum install php-zip php-xml

# Alpine
apk add php-zip php-xml php-simplexml
```

## Updating

### Global Installation
```bash
composer global update opencoreemr/oce-cli-import-codes
```

### Local Installation
```bash
composer update opencoreemr/oce-cli-import-codes
```

### Git Installation
```bash
git pull origin main
composer install
```