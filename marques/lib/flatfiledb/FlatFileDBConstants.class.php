<?php
declare(strict_types=1);

namespace FlatFileDB;

/**
 * Konfigurationskonstanten für die FlatFile-Datenbank.
 */
class FlatFileDBConstants {
    public const DEFAULT_BASE_DIR    = 'data';
    public const DEFAULT_BACKUP_DIR  = 'data/backups';

    public const DATA_FILE_EXTENSION = '.jsonl';
    public const INDEX_FILE_EXTENSION = '.json';
    public const LOG_FILE_EXTENSION = '.jsonl';

    public const LOG_ACTION_INSERT = 'INSERT';
    public const LOG_ACTION_UPDATE = 'UPDATE';
    public const LOG_ACTION_DELETE = 'DELETE';
}