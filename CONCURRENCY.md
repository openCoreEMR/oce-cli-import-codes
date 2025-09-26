# Database Locking for Concurrent Import Prevention

## Overview

The OpenEMR Code Import CLI tool now includes database locking to prevent concurrent imports of the same vocabulary type, which could otherwise result in data corruption or incomplete imports.

## How It Works

### Database Named Locks

The system uses MySQL's `GET_LOCK()` and `RELEASE_LOCK()` functions to implement named locks:

- **Lock Name Format**: `openemr_vocab_import_{CODE_TYPE}`
- **Timeout**: 30 seconds (configurable)
- **Scope**: Per code type (RXNORM, SNOMED, ICD9, ICD10, CQM_VALUESET)

### Lock Behavior

1. **Before Import**: Attempts to acquire a database lock for the specific code type
2. **Retry Logic**: If lock is held, retries with exponential backoff (configurable)
3. **During Import**: Holds the lock for the entire duration of the import process
4. **After Import**: Automatically releases the lock (even if import fails)
5. **Cleanup**: Destructor ensures locks are released if the process exits unexpectedly

### Concurrent Access Scenarios

| Scenario | Behavior |
|----------|----------|
| Same code type (e.g., two RXNORM imports) | Second process retries with exponential backoff (default: 10 attempts over ~8 minutes) |
| Different code types (e.g., RXNORM + SNOMED) | Both processes run concurrently without interference |
| Process crash/kill | Lock automatically released by MySQL when connection closes |
| No-wait mode (`--lock-retry-delay=0`) | Second process fails immediately without waiting |

## Error Messages

### Lock Acquisition Failure (after retries)
```
Failed to acquire database lock for RXNORM import after 10 attempts (510 seconds total). Another import may still be in progress.
```

### No-wait Mode Failure
```
Failed to acquire database lock for RXNORM import - another import is in progress and no-wait mode is enabled.
```

### Database Error
```
Database lock acquisition failed for RXNORM import due to a database error.
```

## Benefits

1. **Data Integrity**: Prevents table corruption from concurrent DROP/CREATE operations
2. **Import Consistency**: Ensures complete, atomic imports
3. **Resource Protection**: Prevents conflicts in temporary directories and tracking tables
4. **Graceful Degradation**: Clear error messages when conflicts occur

## Technical Implementation

### CodeImporter.php Changes

- Added `$currentLockName` property to track active locks
- `acquireLock()` method uses `GET_LOCK()` with 30-second timeout
- `releaseLock()` method uses `RELEASE_LOCK()` with error handling
- `__destruct()` ensures cleanup on object destruction
- `import()` method wrapped with try/finally for guaranteed lock release

### Lock Names by Code Type

- RXNORM: `openemr_vocab_import_RXNORM`
- SNOMED: `openemr_vocab_import_SNOMED`
- SNOMED_RF2: `openemr_vocab_import_SNOMED_RF2`
- ICD9: `openemr_vocab_import_ICD9`
- ICD10: `openemr_vocab_import_ICD10`
- CQM_VALUESET: `openemr_vocab_import_CQM_VALUESET`

## CLI Configuration Options

### Lock Retry Configuration

- `--lock-retry-attempts=N`: Number of retry attempts (default: 10)
- `--lock-retry-delay=N`: Initial delay between retries in seconds (default: 30)
  - Set to 0 for no-wait mode (fail immediately)
  - Uses exponential backoff with jitter (caps at 5 minutes per attempt)

### Examples

**Default behavior** (10 retries with exponential backoff):
```bash
php oce-import-codes import /path/to/rxnorm.zip
```

**Quick failure mode** (no waiting):
```bash
php oce-import-codes import /path/to/rxnorm.zip --lock-retry-delay=0
```

**Custom retry behavior** (3 attempts, 60-second initial delay):
```bash
php oce-import-codes import /path/to/rxnorm.zip --lock-retry-attempts=3 --lock-retry-delay=60
```

## Testing Concurrent Access

To test the locking mechanism:

1. Start an import process:
   ```bash
   php oce-import-codes import /path/to/rxnorm.zip
   ```

2. While the first is running, start a second import of the same type:
   ```bash
   php oce-import-codes import /path/to/another-rxnorm.zip
   ```

3. The second process should retry for several minutes with exponential backoff, displaying progress messages.

## Monitoring Active Locks

You can check for active import locks in MySQL:

```sql
SELECT * FROM performance_schema.metadata_locks
WHERE OBJECT_NAME LIKE 'openemr_vocab_import_%';
```

Or check specific locks:

```sql
SELECT IS_USED_LOCK('openemr_vocab_import_RXNORM') as lock_status;
```

## Troubleshooting

### Stuck Locks
If a lock appears stuck (process crashed without cleanup):

```sql
SELECT RELEASE_LOCK('openemr_vocab_import_RXNORM');
```

### Lock Timeout Issues
If 30 seconds isn't enough for your environment, modify the timeout in `CodeImporter.php`:

```php
$result = sqlQuery("SELECT GET_LOCK(?, 60) as lock_result", [$lockName]); // 60 seconds
```

## Backward Compatibility

This change is fully backward compatible:
- Single imports work exactly as before
- No changes to command-line interface
- No additional dependencies required
- Falls back gracefully if database functions unavailable
