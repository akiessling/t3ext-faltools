# EXT:faltools

Tools for TYPO3 FAL indexing and handling missing files (`sys_file.missing = 1`).

## Status

**Alpha version**  
This extension is under active development.

## Important Notice

This extension provides features that help in restoring files or deleting FAL index entries and references.  
**Use at your own risk.**  
Backups, staging validation, and careful review are strongly recommended before production use.

## Features

## 1) CLI command: index storage

Command:

```bash
./vendor/bin/typo3 faltools:index-storage <storageUid>
./vendor/bin/typo3 faltools:index-storage --all
```

Behavior:

- runs FAL indexing for one storage or all storages
- uses TYPO3 Core `Indexer::processChangesInStorages()`
- detects changes and marks missing files

Note:

- Indexing can also be triggered via the TYPO3 Core Scheduler task.
- For ad-hoc/manual test runs, the `faltools:index-storage` command can be used directly.

## 2) Backend module: missing files

Module in the **File** main section with:

- list of missing files (`sys_file.missing = 1`)
- storage/folder tree navigation
- pagination for large datasets
- reference count display (excluding pure metadata self-relations)

### Per-file actions

- open file URL (if available)
- restore a missing file by upload to its original identifier
- delete missing entry (with reference confirmation)

### Batch actions

- delete all missing entries in the selected folder scope
- delete all missing entries on the current page
- explicit warning/confirmation when references exist

### CSV export

- export all missing files below the currently selected folder scope so you can e.g. use rsync to restore from a backup
- includes:
  - `uid`
  - `name`
  - `storage_uid`
  - `storage_path` (with storage prefix, e.g. `fileadmin/...`)
  - `sha1`

## Technical requirements

- TYPO3 13.4+
- PHP 8.2+
