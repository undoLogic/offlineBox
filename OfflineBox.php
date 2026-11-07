<?php
/**
 * OfflineBox by undoLogic
 * Copyright (c) 2021-2026 undoLogic Inc.
 *
 * Licensed under the Apache License, Version 2.0.
 * See the LICENSE and NOTICE files in the project root.
 * This notice must be retained in copies or substantial portions of this file.
 */

// Requires: composer require aws/aws-sdk-php
// Spec: offlineBox/README.md in SetupCase core. Copy this class unchanged.

namespace App\Util;

use Aws\Exception\AwsException;
use Aws\S3\ObjectCopier;
use Aws\S3\ObjectUploader;
use Aws\S3\S3Client;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;

/**
 * Self-contained backup utility. It has no dependency on any other class in
 * the host project, and takes its S3 credentials as arguments so each project
 * can source them however it already does secrets.
 *
 * mysqlDump(), anonymizedDump() and upload() only run on the CLI. validate()
 * and download() run anywhere: they only read, so the admin page can use them.
 */
class OfflineBox
{
    /** Where backups live in the bucket. The local client reads the same prefix. */
    public const KEY_PREFIX = 'backups/';
    // One file per connection, always the latest. It is overwritten, never deleted.
    public const RECENT_PREFIX = 'backups_recent/';
    // One anonymized dump per connection, always the latest. Overwritten, never deleted.
    public const ANONYMIZED_PREFIX = 'backups_anonymized/';

    /** A newest backup older than this shows as a problem in validate(). */
    private const MAX_BACKUP_AGE_DAYS = 2;

    // What this class needs. Composer's platform check covers the AWS SDK's own minimum.
    private const MIN_PHP_VERSION = '8.0.0';
    // The php.ini or environment name behind each config key, shown on the admin page.
    private const SETTING_NAMES = [
        'spaceKey' => 'OFFLINEBOX_S3_KEY',
        'spaceSecret' => 'OFFLINEBOX_S3_SECRET',
        'spaceRegion' => 'OFFLINEBOX_S3_REGION',
        'spaceName' => 'OFFLINEBOX_S3_BUCKET',
    ];

    private const DUMP_FLAGS = '--single-transaction --no-tablespaces --default-character-set=utf8mb4';

    // Anonymized dumps: column types, so each fake value is valid for its column.
    private const INT_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];
    private const NUMBER_TYPES = ['decimal', 'float', 'double'];
    private const TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];
    // Written as hex, so the bytes survive exactly.
    private const BINARY_TYPES = [
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'geometry', 'point',
        'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
    ];
    // SQL literals, by column type.
    private const FIXED_FAKES = [
        'date' => "'2000-01-01'", 'datetime' => "'2000-01-01 00:00:00'", 'timestamp' => "'2000-01-01 00:00:00'",
        'time' => "'00:00:00'", 'year' => '2000', 'set' => "''", 'json' => "'{}'", 'bit' => '0',
        'binary' => "''", 'varbinary' => "''", 'tinyblob' => "''", 'blob' => "''", 'mediumblob' => "''", 'longblob' => "''",
    ];
    private const FAKE_TEXT = 'anonymized';
    private const FAKE_EMAIL = 'user%s@example.test';
    // An INSERT is written once it holds this many rows or bytes, so memory stays flat.
    private const INSERT_BATCH_ROWS = 500;
    private const INSERT_BATCH_BYTES = 1048576;
    // Same settings mysqldump uses for its data, so the INSERTs restore in any order.
    private const ANONYMIZED_DATA_HEADER = "\n-- OfflineBox anonymized data\n"
        . "/*!40101 SET NAMES utf8mb4 */;\n"
        . "SET @OFFLINEBOX_FK = @@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS = 0;\n"
        . "SET @OFFLINEBOX_UNIQUE = @@UNIQUE_CHECKS, UNIQUE_CHECKS = 0;\n"
        . "SET @OFFLINEBOX_SQL_MODE = @@SQL_MODE, SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
        . "SET @OFFLINEBOX_TZ = @@TIME_ZONE, TIME_ZONE = '+00:00';\n\n";
    private const ANONYMIZED_DATA_FOOTER = "SET TIME_ZONE = @OFFLINEBOX_TZ;\n"
        . "SET SQL_MODE = @OFFLINEBOX_SQL_MODE;\n"
        . "SET UNIQUE_CHECKS = @OFFLINEBOX_UNIQUE;\n"
        . "SET FOREIGN_KEY_CHECKS = @OFFLINEBOX_FK;\n\n";

    private $spaceName;
    private $s3;

    // mysqlDump() working state
    private $dumpConnectionName;
    private $dumpConnection;
    private $dumpDatabaseHeader;
    private $dumpTablesFull; // structure + data; null means "whole database, no filtering"
    private $dumpTablesStructureOnly; // excluded tables: structure kept, data dropped
    private $dumpCredentialsFile;
    private $dumpErrorFile;
    private $dumpFile;
    private $dumpCommand;
    // anonymizedDump(): the triggers pass, run after the data so restoring it fires no trigger.
    private $dumpTriggerCommand;

    /**
     * @param array $config Required: spaceKey, spaceSecret, spaceRegion, spaceName.
     *   Optional: spaceEndpoint (defaults to DigitalOcean Spaces for the region), and
     *   settingPrefix, which is only used to name settings in messages.
     */
    public function __construct(array $config)
    {
        foreach (self::requiredS3Keys() as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException('OfflineBox setting is missing: ' . ($config['settingPrefix'] ?? '') . self::SETTING_NAMES[$key]);
            }
        }

        $this->spaceName = $config['spaceName'];
        $this->s3 = self::s3Client($config);
    }

    /**
     * Dumps one database connection to a local .sql file. CLI only.
     *
     * @param string $connectionName CakePHP connection name (e.g. 'default').
     * @param string $filenamePrefix Optional prefix for the dump filename.
     * @param array $tableConfig ['exclude' => string|array]. Every table is always in the dump.
     *   'exclude' drops DATA only - the table's structure is still dumped (empty),
     *   so software relying on the table existing still works against the restored backup.
     */
    public function mysqlDump($connectionName = 'default', $filenamePrefix = '', array $tableConfig = [])
    {
        self::requireCli('mysqlDump');
        Log::debug('Starting mysqlDump: ' . $connectionName);

        $this->dumpConnectionName = $connectionName;
        $this->dumpCredentialsFile = null;
        $this->dumpErrorFile = null;
        $this->dumpFile = null;

        try {
            $res = $this->mysqlDump_run($connectionName, $filenamePrefix, $tableConfig);
        } catch (\Throwable $e) {
            $res = ['STATUS' => 500, 'MSG' => 'mysqlDump failed for ' . $connectionName . ': ' . $e->getMessage()];
        }

        $this->mysqlDump_cleanUp($res['STATUS'] === 200);
        Log::debug('mysqlDump completed: ' . $res['MSG']);

        return $res;
    }

    private function mysqlDump_run($connectionName, $filenamePrefix, array $tableConfig): array
    {
        $connection = ConnectionManager::get($connectionName);
        $this->mysqlDump_resolveConnection($connection);

        $tables = self::resolveTables($connection, $tableConfig);
        if (!empty($tables['missing'])) {
            return [
                'STATUS' => 500,
                'MSG' => 'mysqlDump aborted: exclude names no such table: ' . implode(', ', $tables['missing']),
            ];
        }

        $this->dumpTablesFull = $tables['full'];
        $this->dumpTablesStructureOnly = $tables['structure'];

        $this->mysqlDump_resolveDatabaseHeader($connection);
        $this->mysqlDump_writeCredentialsFile();
        $this->mysqlDump_buildCommand($filenamePrefix);

        return $this->mysqlDump_execute();
    }

    private function mysqlDump_resolveConnection($connection): void
    {
        $config = $connection->config();

        $this->dumpConnection = [
            'host' => $config['host'] ?? 'localhost',
            'port' => $config['port'] ?? 3306,
            'socket' => $config['unix_socket'] ?? '',
            'username' => $config['username'] ?? '',
            'password' => $config['password'] ?? '',
            'database' => $config['database'],
        ];
    }

    /**
     * Reads the source database's charset, so a restore doesn't fall back to
     * whatever the new server defaults to.
     */
    private function mysqlDump_resolveDatabaseHeader($connection): void
    {
        $database = $this->dumpConnection['database'];
        $row = $connection->execute(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation_name
             FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$database]
        )->fetch('assoc');

        if (empty($row)) {
            throw new \RuntimeException('database not found on the server: ' . $database);
        }

        $quoted = '`' . str_replace('`', '``', $database) . '`';
        $this->dumpDatabaseHeader = "CREATE DATABASE IF NOT EXISTS {$quoted}"
            . " CHARACTER SET {$row['charset']} COLLATE {$row['collation_name']};\n"
            . "USE {$quoted};\n\n";
    }

    /**
     * Writes a 0600 mysqldump --defaults-extra-file so the DB password
     * never appears on the command line / process list. Values are quoted, as
     * an unquoted # or space in an option file breaks the login.
     */
    private function mysqlDump_writeCredentialsFile(): void
    {
        $connection = $this->dumpConnection;

        $lines = ['[client]'];
        $lines[] = 'user=' . $this->mysqlDump_quoteOption($connection['username']);
        $lines[] = 'password=' . $this->mysqlDump_quoteOption($connection['password']);
        $lines[] = 'host=' . $this->mysqlDump_quoteOption($connection['host']);
        $lines[] = 'port=' . (int)$connection['port'];
        if ($connection['socket'] !== '') {
            $lines[] = 'socket=' . $this->mysqlDump_quoteOption($connection['socket']);
        }

        $this->dumpCredentialsFile = $this->mysqlDump_createPrivateFile(
            TMP . 'offlinebox_' . uniqid('', true) . '.cnf',
            implode("\n", $lines) . "\n"
        );
    }

    private function mysqlDump_quoteOption($value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$value) . '"';
    }

    /**
     * Creates the file as 0600 from the first byte, so it is never readable
     * by anyone else, not even between creating it and a later chmod().
     */
    private function mysqlDump_createPrivateFile(string $file, string $contents): string
    {
        $umask = umask(0077);
        $written = file_put_contents($file, $contents);
        umask($umask);

        if ($written === false) {
            throw new \RuntimeException('could not write to ' . $file);
        }

        return $file;
    }

    /**
     * Builds the dump file. Writes a CREATE DATABASE IF NOT EXISTS / USE
     * header directly (not via `mysqldump --databases`, which is NOT
     * combinable with an explicit table list - it would reinterpret our
     * table names as extra database names), then chains mysqldump pass(es)
     * appending to the same file: a --no-data pass for dumpTablesStructureOnly,
     * then a full pass for dumpTablesFull (structure + data), so excluded tables land in the file as empty tables rather than being
     * left out entirely. The result restores cleanly with `mysql < dump.sql`
     * against an empty server.
     */
    private function mysqlDump_buildCommand($filenamePrefix): void
    {
        $date = date('Y-m-d');
        $version = date('His');
        $name = $date . '_' . $filenamePrefix . $this->dumpConnectionName . '_' . $version . '.sql';

        $this->dumpFile = $this->mysqlDump_createPrivateFile(TMP . $name, $this->dumpDatabaseHeader);
        $this->dumpErrorFile = $this->mysqlDump_createPrivateFile(TMP . $name . '.err', '');

        // Structure-only tables go first: a view in the full pass may read from one,
        // and the restore stops if that table doesn't exist yet.
        $passes = [];
        if (!empty($this->dumpTablesStructureOnly)) {
            $passes[] = $this->mysqlDump_buildPass('--no-data', $this->dumpTablesStructureOnly);
        }
        if ($this->dumpTablesFull === null || !empty($this->dumpTablesFull)) {
            $passes[] = $this->mysqlDump_buildPass('', $this->dumpTablesFull ?? []);
        }

        // Routines belong to the database, not a table: dump them once.
        $passes[0] = str_replace('--no-tablespaces', '--no-tablespaces --routines', $passes[0]);

        $redirected = array_map(function ($command) {
            return $command . ' >> ' . escapeshellarg($this->dumpFile)
                . ' 2>> ' . escapeshellarg($this->dumpErrorFile);
        }, $passes);

        $this->dumpCommand = implode(' && ', $redirected);
    }

    private function mysqlDump_buildPass(string $extraFlag, array $tables): string
    {
        // --defaults-extra-file must be the first option, or mysqldump rejects it.
        $command = 'mysqldump --defaults-extra-file=' . escapeshellarg($this->dumpCredentialsFile)
            . ' ' . self::DUMP_FLAGS
            . ($extraFlag !== '' ? ' ' . $extraFlag : '')
            . ' ' . escapeshellarg($this->dumpConnection['database']);

        foreach ($tables as $table) {
            $command .= ' ' . escapeshellarg($table);
        }

        return $command;
    }

    private function mysqlDump_execute(): array
    {
        $output = [];
        $returnVar = 1;
        exec($this->dumpCommand, $output, $returnVar);

        if ($returnVar !== 0) {
            $error = trim(substr((string)file_get_contents($this->dumpErrorFile), 0, 500));

            return [
                'STATUS' => 500,
                'MSG' => 'mysqldump failed for ' . $this->dumpConnectionName . ': ' . ($error ?: 'exit code ' . $returnVar),
            ];
        }

        return [
            'STATUS' => 200,
            'MSG' => 'mysqldump completed: ' . $this->dumpFile,
            'file' => $this->dumpFile,
            'pathInfo' => pathinfo($this->dumpFile),
            'database' => $this->dumpConnection['database'],
        ];
    }

    /**
     * Removes the credentials and error files always, and a partial dump on
     * failure. A failed dump returns no 'file', so only this class can.
     */
    private function mysqlDump_cleanUp(bool $succeeded): void
    {
        $files = [$this->dumpCredentialsFile, $this->dumpErrorFile];
        if (!$succeeded) {
            $files[] = $this->dumpFile;
        }

        foreach ($files as $file) {
            if ($file !== null && is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Dumps one connection with real data only in kept columns and kept rows,
     * and fake values everywhere else. CLI only. Spec:
     * docs/features/MVP-offlinebox-anonymized-backups.md
     *
     * The file holds the full structure (tables, views, routines), then the
     * anonymized data, then the triggers, so restoring the data fires no trigger.
     * Excluded tables keep their structure and stay empty, as in mysqlDump().
     *
     * @param array $tableConfig The connection's entry in OfflineBoxCommand::CONNECTIONS:
     *   'exclude', and 'anonymize' => ['keep' => [table => [columns]], 'keepRows' => [table => [column => [values]]]].
     * @return array STATUS, MSG, and on success file, pathInfo, database and warnings.
     */
    public function anonymizedDump($connectionName = 'default', array $tableConfig = [])
    {
        self::requireCli('anonymizedDump');
        Log::debug('Starting anonymizedDump: ' . $connectionName);

        $this->dumpConnectionName = $connectionName;
        $this->dumpCredentialsFile = null;
        $this->dumpErrorFile = null;
        $this->dumpFile = null;

        try {
            $res = $this->anonymizedDump_run($connectionName, $tableConfig);
        } catch (\Throwable $e) {
            $res = ['STATUS' => 500, 'MSG' => 'anonymizedDump failed for ' . $connectionName . ': ' . $e->getMessage()];
        }

        $this->mysqlDump_cleanUp($res['STATUS'] === 200);
        Log::debug('anonymizedDump completed: ' . $res['MSG']);

        return $res;
    }

    private function anonymizedDump_run($connectionName, array $tableConfig): array
    {
        if (!isset($tableConfig['anonymize'])) {
            return ['STATUS' => 500, 'MSG' => 'anonymizedDump aborted: ' . $connectionName . ' has no anonymize config.'];
        }

        $connection = ConnectionManager::get($connectionName);
        $this->mysqlDump_resolveConnection($connection);

        $tables = self::resolveTables($connection, $tableConfig);
        if (!empty($tables['missing'])) {
            return ['STATUS' => 500, 'MSG' => 'anonymizedDump aborted: exclude names no such table: ' . implode(', ', $tables['missing'])];
        }

        $plan = self::resolveAnonymize($connection, $this->dumpConnection['database'], $tableConfig);
        if (!empty($plan['errors'])) {
            return ['STATUS' => 500, 'MSG' => 'anonymizedDump aborted: ' . implode(' ', $plan['errors'])];
        }

        $this->mysqlDump_resolveDatabaseHeader($connection);
        $this->mysqlDump_writeCredentialsFile();
        $this->anonymizedDump_buildCommands();

        $res = $this->mysqlDump_execute();
        if ($res['STATUS'] !== 200) {
            return $res;
        }

        $this->anonymizedDump_writeData($plan);
        $this->dumpCommand = $this->dumpTriggerCommand;
        $res = $this->mysqlDump_execute();
        $res['warnings'] = $plan['warnings'];

        return $res;
    }

    /**
     * Two mysqldump passes over the whole database, both without data: the
     * structure with routines first, and only the triggers second.
     */
    private function anonymizedDump_buildCommands(): void
    {
        $name = date('Y-m-d') . '_anonymized_' . $this->dumpConnectionName . '_' . date('His') . '.sql';

        $this->dumpFile = $this->mysqlDump_createPrivateFile(TMP . $name, $this->dumpDatabaseHeader);
        $this->dumpErrorFile = $this->mysqlDump_createPrivateFile(TMP . $name . '.err', '');

        $redirect = ' >> ' . escapeshellarg($this->dumpFile) . ' 2>> ' . escapeshellarg($this->dumpErrorFile);
        $this->dumpCommand = $this->mysqlDump_buildPass('--no-data --skip-triggers --routines', []) . $redirect;
        $this->dumpTriggerCommand = $this->mysqlDump_buildPass('--no-data --no-create-info', []) . $redirect;
    }

    private function anonymizedDump_writeData(array $plan): void
    {
        $pdo = $this->anonymizedDump_openSnapshot();
        $handle = fopen($this->dumpFile, 'ab');
        if ($handle === false) {
            throw new \RuntimeException('could not open ' . $this->dumpFile);
        }

        try {
            self::anonymizedDump_write($handle, self::ANONYMIZED_DATA_HEADER);
            foreach ($plan['tables'] as $table => $tablePlan) {
                $this->anonymizedDump_writeTable($pdo, $handle, $table, $tablePlan);
            }
            self::anonymizedDump_write($handle, self::ANONYMIZED_DATA_FOOTER);
            $pdo->exec('COMMIT');
        } finally {
            fclose($handle);
        }
    }

    /**
     * Its own connection, so the unbuffered reads never block the app's
     * connection. Reads only, inside one consistent snapshot, in UTC like mysqldump.
     */
    private function anonymizedDump_openSnapshot(): \PDO
    {
        $c = $this->dumpConnection;
        $dsn = $c['socket'] !== ''
            ? 'mysql:unix_socket=' . $c['socket']
            : 'mysql:host=' . $c['host'] . ';port=' . (int)$c['port'];

        $pdo = new \PDO($dsn . ';dbname=' . $c['database'] . ';charset=utf8mb4', $c['username'], $c['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            // Exact strings: a float cast through PHP could lose digits.
            \PDO::ATTR_STRINGIFY_FETCHES => true,
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        return $pdo;
    }

    private function anonymizedDump_writeTable(\PDO $pdo, $handle, string $table, array $tablePlan): void
    {
        $columns = array_values(array_filter($tablePlan['columns'], fn($column) => !$column['generated']));
        if ($columns === []) {
            return;
        }

        $names = implode(', ', array_map(fn($column) => self::quoteName($column['name']), $columns));
        $select = implode(', ', array_map(fn($column) => $column['bit']
            ? self::quoteName($column['name']) . ' + 0 AS ' . self::quoteName($column['name'])
            : self::quoteName($column['name']), $columns));
        $sql = 'SELECT ' . $select . ' FROM ' . self::quoteName($table);
        if (!empty($tablePlan['primaryKey'])) {
            $sql .= ' ORDER BY ' . implode(', ', array_map([self::class, 'quoteName'], $tablePlan['primaryKey']));
        }
        $insert = 'INSERT INTO ' . self::quoteName($table) . ' (' . $names . ') VALUES ';

        $batch = [];
        $bytes = 0;
        $rowNumber = 0;
        $statement = $pdo->query($sql, \PDO::FETCH_ASSOC);
        foreach ($statement as $row) {
            $values = $this->anonymizedDump_row($pdo, $table, $tablePlan, $columns, $row, ++$rowNumber);
            $batch[] = $values;
            $bytes += strlen($values);
            if (count($batch) >= self::INSERT_BATCH_ROWS || $bytes >= self::INSERT_BATCH_BYTES) {
                self::anonymizedDump_write($handle, $insert . implode(",\n", $batch) . ";\n");
                $batch = [];
                $bytes = 0;
            }
        }
        $statement->closeCursor();

        if ($batch !== []) {
            self::anonymizedDump_write($handle, $insert . implode(",\n", $batch) . ";\n");
        }
    }

    private function anonymizedDump_row(\PDO $pdo, string $table, array $tablePlan, array $columns, array $row, int $rowNumber): string
    {
        $keepRow = false;
        foreach ($tablePlan['keepRows'] as $column => $values) {
            $keepRow = $keepRow || (isset($row[$column]) && in_array((string)$row[$column], $values, true));
        }

        $key = (string)$rowNumber;
        if (!empty($tablePlan['primaryKey'])) {
            $key = implode('-', array_map(fn($column) => (string)($row[$column] ?? ''), $tablePlan['primaryKey']));
        }

        $values = [];
        foreach ($columns as $column) {
            $value = $row[$column['name']];
            $values[] = $keepRow || $column['keep']
                ? self::sqlValue($pdo, $value, $column)
                : self::anonymizedDump_fake($pdo, $table, $column, $value, $key, $rowNumber);
        }

        return '(' . implode(',', $values) . ')';
    }

    private static function anonymizedDump_fake(\PDO $pdo, string $table, array $column, $value, string $key, int $rowNumber): string
    {
        if ($value === null && $column['nullable']) {
            return 'NULL';
        }

        switch ($column['pattern']) {
            case 'rowNumber':
                return (string)$rowNumber;
            case 'email':
                $choices = [sprintf(self::FAKE_EMAIL, $key), $key];
                break;
            case 'unique':
                $choices = [self::FAKE_TEXT . '-' . $key, $key];
                break;
            default:
                return $column['fake'];
        }

        foreach ($choices as $choice) {
            if ($column['maxLength'] === null || mb_strlen($choice) <= $column['maxLength']) {
                return $pdo->quote($choice);
            }
        }

        throw new \RuntimeException($table . '.' . $column['name'] . ' is too short for a unique fake value. Add it to keep.');
    }

    private static function anonymizedDump_write($handle, string $text): void
    {
        if (fwrite($handle, $text) !== strlen($text)) {
            throw new \RuntimeException('could not write the anonymized dump. Is the disk full?');
        }
    }

    /**
     * Uploads as a private object and confirms it arrived intact. CLI only.
     * ObjectUploader streams from disk and switches to multipart for large
     * files, so this works for any dump size.
     */
    public function upload($filename, $keyName)
    {
        self::requireCli('upload');
        Log::debug('Starting upload: ' . $keyName);

        $stream = null;

        try {
            $stream = fopen($filename, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('could not open ' . $filename);
            }

            $uploader = new ObjectUploader($this->s3, $this->spaceName, $keyName, $stream, 'private');
            $result = $uploader->upload();

            $res = $this->upload_verify($filename, $keyName);
            if ($res['STATUS'] === 200) {
                $res['MSG'] = "File uploaded and verified. URL: {$result['ObjectURL']}";
            }
        } catch (\Throwable $e) {
            $res = ['STATUS' => 500, 'MSG' => 'Upload failed: ' . $e->getMessage()];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($res['STATUS'] !== 200) {
            Log::error('[OfflineBox] ' . $res['MSG']);
        }
        Log::debug('upload completed');

        return $res;
    }

    /**
     * Copies a verified backup to backups_recent/<connection>.sql, overwriting the last copy.
     * The dated backup stays where it is. This never deletes anything.
     */
    public function copyToRecent($filename, $sourceKey, $connectionName)
    {
        self::requireCli('copyToRecent');
        $recentKey = self::recentKey($connectionName);

        try {
            $copier = new ObjectCopier(
                $this->s3,
                ['Bucket' => $this->spaceName, 'Key' => $sourceKey],
                ['Bucket' => $this->spaceName, 'Key' => $recentKey],
                'private'
            );
            $copier->copy();
            $res = $this->upload_verify($filename, $recentKey);
        } catch (\Throwable $e) {
            $res = ['STATUS' => 500, 'MSG' => 'Copy failed: ' . $e->getMessage()];
        }

        if ($res['STATUS'] !== 200) {
            $res['MSG'] = $recentKey . ' was not updated, but ' . $sourceKey . ' is safe. ' . $res['MSG'];
            Log::error('[OfflineBox] ' . $res['MSG']);
        }

        return $res;
    }

    /**
     * Confirms the uploaded object matches the local file (size, and
     * MD5/ETag for non-multipart uploads) rather than assuming a
     * successful upload means the backup is intact.
     */
    private function upload_verify($filename, $keyName): array
    {
        $result = $this->s3->headObject([
            'Bucket' => $this->spaceName,
            'Key' => $keyName,
        ]);

        if ((int)$result['ContentLength'] !== filesize($filename)) {
            return [
                'STATUS' => 500,
                'MSG' => 'Upload verification failed. Local file size does not match remote object size.',
            ];
        }

        // A multipart ETag (contains "-") is not an MD5, so those verify by size only.
        $etag = strtolower(trim($result['ETag'], '"'));
        if (!str_contains($etag, '-') && $etag !== strtolower(md5_file($filename))) {
            return [
                'STATUS' => 500,
                'MSG' => 'Upload verification failed. Local MD5 does not match remote ETag.',
            ];
        }

        return ['STATUS' => 200, 'MSG' => 'Upload verified'];
    }

    /**
     * Reports whether the setup is valid and what will be exported. Read-only:
     * it never dumps and writes nothing to disk or the bucket. Each check turns
     * a failure into a 'problem' row instead of throwing.
     *
     * @param array $connections Same shape the command backs up: name => table rules.
     * @param array $s3Config Same keys as the constructor.
     * @return array STATUS, MSG, counts, databases, environment, storage.
     *   Each row holds a 'level' of ok, warning or problem.
     */
    public static function validate(array $connections, array $s3Config): array
    {
        $databases = self::validate_databases($connections);
        $environment = self::validate_environment($databases);
        $storage = self::validate_storage($s3Config);
        [$databases, $anonymizedRows] = self::validate_downloads($databases, $connections, $s3Config);
        $storage = array_merge($storage, $anonymizedRows);

        $levels = array_merge(
            array_column($databases, 'level'),
            array_column($environment, 'level'),
            array_column($storage, 'level')
        );
        $counts = array_merge(['ok' => 0, 'warning' => 0, 'problem' => 0], array_count_values($levels));

        return [
            'STATUS' => $counts['problem'] === 0 ? 200 : 500,
            'MSG' => $counts['problem'] === 0 ? 'Ready' : 'Problems found',
            'counts' => $counts,
            'databases' => $databases,
            'environment' => $environment,
            'storage' => $storage,
        ];
    }

    /**
     * The cron setup steps for the admin page, using this server's app folder.
     * Text only: it runs nothing.
     */
    public static function installSteps(string $settingPrefix = ''): array
    {
        $cd = 'cd ' . ROOT . ' && ';
        $settings = implode("\n", array_map(fn($name) => $settingPrefix . $name . ' = ""', self::SETTING_NAMES));

        return [
            'STATUS' => 200,
            'MSG' => 'Install steps',
            'intro' => 'Follow these on the server you are installing on. The folder below comes from the server showing this page, so local Docker shows its own folder.',
            'steps' => [
                ['text' => 'Add these to php.ini and fill in the real values. Run php --ini to find the file command-line PHP reads, and add them to the web server\'s php.ini too, then restart the web server. Never commit real values.', 'command' => $settings],
                ['text' => 'Log in to the server as the user that will run cron, then run the check. It must say Ready and name the real database, not a dev one.', 'command' => $cd . 'bin/cake offline_box --check'],
                ['text' => 'Run one backup by hand. It should finish with OK, and the file then shows as Newest backup on this page.', 'command' => $cd . 'bin/cake offline_box'],
                ['text' => 'Open that user\'s cron jobs with crontab -e and add this line. It runs every night at 3:00 AM, server time.', 'command' => '0 3 * * * ' . $cd . 'bin/cake offline_box >> logs/offlinebox.log 2>&1'],
                ['text' => 'Come back to this page the next day and confirm Newest backup has a new date. It turns red if the newest backup is ' . self::MAX_BACKUP_AGE_DAYS . ' or more days old.', 'command' => ''],
            ],
            'notes' => [
                'For DigitalOcean Spaces, leave ' . $settingPrefix . 'OFFLINEBOX_S3_ENDPOINT out. For AWS, also add ' . $settingPrefix . 'OFFLINEBOX_S3_ENDPOINT = "https://s3.us-east-1.amazonaws.com" with your region.',
                'If another project shares this php.ini, set SETTING_PREFIX in src/Command/OfflineBoxCommand.php so each project has its own settings and bucket.',
                'If the log says php or mysqldump is not found, cron can\'t see them. Run command -v php mysqldump, and add those folders on a PATH= line above the job.',
                'Failed backups are also written to logs/cli-error.log.',
                'Only use the server\'s crontab. Don\'t add this to config/cron.php or call it from a web page: backups refuse to run outside the command line.',
            ],
        ];
    }

    /**
     * Opens the latest backup already in the bucket for download. It never
     * dumps: the file is exactly what cron uploaded. Read-only, so the admin
     * page can use it.
     *
     * @param array $connections OfflineBoxCommand::CONNECTIONS. Only its keys are accepted.
     * @param string $kind 'anonymized' or 'full'.
     * @return array STATUS (200, 404 or 500), MSG, and on success body (a stream), size and filename.
     */
    public static function download(array $connections, array $s3Config, string $connectionName, string $kind): array
    {
        $known = array_key_exists($connectionName, $connections) && in_array($kind, ['anonymized', 'full'], true);
        if (!$known || ($kind === 'anonymized' && !isset($connections[$connectionName]['anonymize']))) {
            return ['STATUS' => 404, 'MSG' => 'No such backup.'];
        }
        if (self::validate_downloadsClient($s3Config) === null) {
            return ['STATUS' => 500, 'MSG' => 'The OfflineBox storage settings are missing.'];
        }

        $key = $kind === 'full' ? self::recentKey($connectionName) : self::anonymizedKey($connectionName);
        try {
            $object = self::s3Client($s3Config)->getObject([
                'Bucket' => $s3Config['spaceName'],
                'Key' => $key,
                '@http' => ['stream' => true],
            ]);
        } catch (\Throwable $e) {
            return self::isNotFound($e)
                ? ['STATUS' => 404, 'MSG' => 'No backup in the bucket yet: ' . $key]
                : ['STATUS' => 500, 'MSG' => 'Download failed: ' . self::shortMessage($e)];
        }

        $database = (string)(ConnectionManager::getConfig($connectionName)['database'] ?? $connectionName);
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $database)
            . '-' . $kind . '-' . $object['LastModified']->format('Y-m-d') . '.sql';

        return ['STATUS' => 200, 'MSG' => 'Ready', 'body' => $object['Body'], 'size' => (int)$object['ContentLength'], 'filename' => $filename];
    }

    private static function validate_databases(array $connections): array
    {
        $names = array_unique(array_merge(array_keys($connections), ConnectionManager::configured()));

        $rows = [];
        foreach ($names as $name) {
            $rows[] = array_key_exists($name, $connections)
                ? self::validate_exportedDatabase($name, $connections[$name])
                : self::validate_databaseRow($name, ConnectionManager::getConfig($name)['database'] ?? '', false);
        }

        return $rows;
    }

    private static function validate_databaseRow(string $name, string $database, bool $willExport): array
    {
        return [
            'connection' => $name,
            'database' => $database,
            'willExport' => $willExport,
            'level' => 'ok',
            'connects' => $willExport ? 'Not checked' : 'Not checked: not exported',
            'version' => '',
            'tablesWithData' => 0,
            'tablesStructureOnly' => 0,
            'estimatedBytes' => 0,
            'size' => '',
            'tables' => [],
            'messages' => [],
            'downloads' => [],
        ];
    }

    private static function validate_exportedDatabase(string $name, array $tableConfig): array
    {
        $row = self::validate_databaseRow($name, '', true);

        try {
            $connection = ConnectionManager::get($name);
            $row['database'] = (string)($connection->config()['database'] ?? '');
            if (!str_contains((string)($connection->config()['driver'] ?? ''), 'Mysql')) {
                throw new \RuntimeException('only MySQL connections can be exported');
            }

            $row['version'] = (string)$connection->execute('SELECT VERSION()')->fetch()[0];
            $row['connects'] = 'OK';
            $row = self::validate_tables($row, $connection, $tableConfig);
            if (isset($tableConfig['anonymize'])) {
                $row = self::validate_anonymize($row, $connection, $tableConfig);
            }
        } catch (\Throwable $e) {
            $row['level'] = 'problem';
            $row['connects'] = 'Failed';
            $row['messages'][] = $e->getMessage();
        }

        return $row;
    }

    private static function validate_tables(array $row, $connection, array $tableConfig): array
    {
        $rules = self::resolveTables($connection, $tableConfig);
        $info = $connection->execute(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine,
                COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS bytes
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY bytes DESC, TABLE_NAME',
            [$row['database']]
        )->fetchAll('assoc');

        $full = $rules['full'] ?? array_column($info, 'name');
        $notProtected = [];

        foreach ($info as $table) {
            $hasData = in_array($table['name'], $full, true);
            if (!$hasData && !in_array($table['name'], $rules['structure'], true)) {
                continue;
            }

            $row['tables'][] = [
                'name' => $table['name'],
                'export' => $hasData ? 'Data + structure' : 'Structure only',
                'size' => self::formatBytes((int)$table['bytes']),
                'anonymize' => '',
            ];
            $row[$hasData ? 'tablesWithData' : 'tablesStructureOnly']++;
            $row['estimatedBytes'] += $hasData ? (int)$table['bytes'] : 0;

            if ($hasData && $table['type'] === 'BASE TABLE' && $table['engine'] !== 'InnoDB') {
                $notProtected[] = $table['name'] . ' (' . $table['engine'] . ')';
            }
        }

        $row['size'] = self::formatBytes($row['estimatedBytes']);

        return self::validate_tableMessages($row, $rules, $notProtected);
    }

    private static function validate_tableMessages(array $row, array $rules, array $notProtected): array
    {
        if (!empty($notProtected)) {
            $row['level'] = 'warning';
            $row['messages'][] = 'Not protected by the consistent snapshot: ' . implode(', ', $notProtected);
        }
        if (!empty($rules['missing'])) {
            $row['level'] = 'problem';
            $row['messages'][] = 'exclude names no such table: ' . implode(', ', $rules['missing']);
        }
        if (empty($row['tables'])) {
            $row['level'] = 'problem';
            $row['messages'][] = 'The database has no tables.';
        }

        return $row;
    }

    /**
     * Runs the same resolveAnonymize() the dump uses, so the page and --check
     * report exactly what the anonymized dump will keep.
     */
    private static function validate_anonymize(array $row, $connection, array $tableConfig): array
    {
        $plan = self::resolveAnonymize($connection, $row['database'], $tableConfig);

        foreach ($row['tables'] as $i => $table) {
            if (isset($plan['tables'][$table['name']])) {
                $row['tables'][$i]['anonymize'] = self::validate_anonymizeSummary($plan['tables'][$table['name']]);
            }
        }
        foreach ($plan['warnings'] as $warning) {
            $row['level'] = $row['level'] === 'ok' ? 'warning' : $row['level'];
            $row['messages'][] = 'Anonymized: ' . $warning;
        }
        foreach ($plan['errors'] as $error) {
            $row['level'] = 'problem';
            $row['messages'][] = 'Anonymized: ' . $error;
        }

        return $row;
    }

    private static function validate_anonymizeSummary(array $tablePlan): string
    {
        $summary = 'Anonymized keeps: ' . ($tablePlan['kept'] === [] ? 'nothing' : implode(', ', $tablePlan['kept'])) . '.';
        foreach ($tablePlan['keepRows'] as $column => $values) {
            $summary .= ' Whole rows kept where ' . $column . ' is ' . implode(' or ', $values) . '.';
        }

        return $summary;
    }

    /**
     * Adds the download buttons to each exported database, and an age row per
     * anonymized dump to Storage. Reads object details only (headObject).
     */
    private static function validate_downloads(array $databases, array $connections, array $config): array
    {
        $client = self::validate_downloadsClient($config);
        $storage = [];

        foreach ($databases as $i => $database) {
            if (!$database['willExport']) {
                continue;
            }
            $name = $database['connection'];
            if (isset($connections[$name]['anonymize'])) {
                $object = self::validate_downloadObject($client, $config, self::anonymizedKey($name));
                $databases[$i]['downloads'][] = self::validate_downloadLink('Download anonymized data', 'downloadAnonymized', $object);
                $storage[] = self::validate_storageAnonymized($name, $object);
            }
            $object = self::validate_downloadObject($client, $config, self::recentKey($name));
            $databases[$i]['downloads'][] = self::validate_downloadLink('Download full data', 'downloadFull', $object);
        }

        return [$databases, $storage];
    }

    private static function validate_downloadsClient(array $config): ?S3Client
    {
        foreach (self::requiredS3Keys() as $key) {
            if (empty($config[$key])) {
                return null;
            }
        }

        return class_exists(S3Client::class) ? self::s3Client($config) : null;
    }

    private static function validate_downloadObject(?S3Client $client, array $config, string $key): array
    {
        if ($client === null) {
            return ['checked' => false, 'found' => false];
        }

        try {
            $head = $client->headObject(['Bucket' => $config['spaceName'], 'Key' => $key]);

            return ['checked' => true, 'found' => true, 'modified' => $head['LastModified'], 'size' => (int)$head['ContentLength']];
        } catch (\Throwable $e) {
            return ['checked' => self::isNotFound($e), 'found' => false];
        }
    }

    private static function validate_downloadLink(string $label, string $action, array $object): array
    {
        $detail = $object['checked'] ? 'None yet' : 'Could not check the bucket';
        if ($object['found']) {
            $detail = $object['modified']->format('Y-m-d H:i') . ' UTC, ' . self::formatBytes($object['size']);
        }

        return ['label' => $label, 'action' => $action, 'available' => $object['found'], 'detail' => $detail];
    }

    private static function validate_storageAnonymized(string $name, array $object): array
    {
        $label = 'Anonymized backup: ' . $name;
        if (!$object['found']) {
            return $object['checked']
                ? self::row($label, 'None yet', 'warning', 'Expected until the first backup with anonymize runs.')
                : self::row($label, 'Not checked', 'warning', 'Needs a reachable bucket.');
        }

        $days = (int)floor((time() - $object['modified']->getTimestamp()) / 86400);
        $detail = self::anonymizedKey($name) . ', ' . self::formatBytes($object['size'])
            . ', ' . $object['modified']->format('Y-m-d H:i') . ' UTC, ' . $days . ' day(s) old';

        return $days >= self::MAX_BACKUP_AGE_DAYS
            ? self::row($label, $detail, 'problem', 'Older than ' . self::MAX_BACKUP_AGE_DAYS . ' days.')
            : self::row($label, $detail, 'ok');
    }

    private static function validate_environment(array $databases): array
    {
        $sapi = PHP_SAPI === 'cli' ? 'CLI' : 'Web';
        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');
        $estimated = array_sum(array_column($databases, 'estimatedBytes'));

        $rows = [
            self::row($sapi . ' PHP', PHP_VERSION, $phpOk ? 'ok' : 'problem', $phpOk ? '' : 'PHP ' . self::MIN_PHP_VERSION . ' or later is required.'),
            self::validate_environmentExec(),
            self::validate_environmentMysqldump($databases),
            self::validate_environmentTmp($estimated),
        ];

        if (!class_exists(S3Client::class)) {
            $rows[] = self::row('AWS SDK', 'Missing', 'problem', 'Install aws/aws-sdk-php.');
        }

        return $rows;
    }

    private static function validate_environmentExec(): array
    {
        return self::execAllowed()
            ? self::row('exec()', 'Allowed', 'ok')
            : self::row('exec()', 'Disabled', 'problem', 'mysqldump can\'t run without exec().');
    }

    private static function validate_environmentMysqldump(array $databases): array
    {
        if (!self::execAllowed()) {
            return self::row('mysqldump', 'Not checked', 'warning', 'exec() is disabled.');
        }

        $output = [];
        $code = 1;
        exec('mysqldump --version 2>&1', $output, $code);
        $version = trim((string)($output[0] ?? ''));

        if ($code !== 0) {
            return self::row('mysqldump', 'Not found', 'problem', 'Not on the PATH of the user running this check.');
        }

        $hasOldServer = false;
        foreach ($databases as $database) {
            $hasOldServer = $hasOldServer || str_starts_with($database['version'], '5.');
        }

        // An 8.0+ client asks for COLUMN_STATISTICS, which a 5.7 server doesn't have.
        if ($hasOldServer && preg_match('/Ver (8|9)\.\d+/', $version)) {
            return self::row('mysqldump', $version, 'problem', 'An 8.0 or later client fails against a 5.7 server.');
        }

        return self::row('mysqldump', $version, 'ok');
    }

    private static function validate_environmentTmp(int $estimatedBytes): array
    {
        if (!is_dir(TMP) || !is_writable(TMP)) {
            return self::row('TMP folder', TMP, 'problem', 'Not writable by the user running this check.');
        }

        $free = disk_free_space(TMP);
        $detail = self::formatBytes((int)$free) . ' free, dump estimated at ' . self::formatBytes($estimatedBytes);

        return $free !== false && $free < $estimatedBytes
            ? self::row('TMP folder', $detail, 'problem', 'Not enough free space for the dump.')
            : self::row('TMP folder', $detail, 'ok');
    }

    private static function validate_storage(array $config): array
    {
        $rows = [];
        $missing = false;
        $prefix = $config['settingPrefix'] ?? '';

        foreach (self::requiredS3Keys() as $key) {
            $isSet = !empty($config[$key]);
            $missing = $missing || !$isSet;
            $rows[] = self::row($prefix . self::SETTING_NAMES[$key], $isSet ? 'Set' : 'Missing', $isSet ? 'ok' : 'problem');
        }
        $rows[] = self::row($prefix . 'OFFLINEBOX_S3_ENDPOINT', $config['spaceEndpoint'] ?? '' ?: 'Optional: not set', 'ok');

        if ($missing || !class_exists(S3Client::class)) {
            $rows[] = self::row('Bucket', 'Not checked', 'warning', 'Needs the settings above and the AWS SDK.');

            return $rows;
        }

        $rows[] = self::row('Endpoint in use', self::s3Endpoint($config), 'ok');

        try {
            $client = self::s3Client($config);
            $client->headBucket(['Bucket' => $config['spaceName']]);
            $rows[] = self::row('Bucket', $config['spaceName'] . ': reachable', 'ok');
            $rows[] = self::validate_storageNewest($client, $config['spaceName']);
        } catch (\Throwable $e) {
            $rows[] = self::row('Bucket', $config['spaceName'] . ': not reachable', 'problem', self::shortMessage($e));
        }

        return $rows;
    }

    private static function validate_storageNewest($client, string $bucket): array
    {
        $newest = null;
        $pages = $client->getPaginator('ListObjectsV2', ['Bucket' => $bucket, 'Prefix' => self::KEY_PREFIX]);

        foreach ($pages as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                if ($newest === null || $object['LastModified'] > $newest['LastModified']) {
                    $newest = $object;
                }
            }
        }

        if ($newest === null) {
            return self::row('Newest backup', 'None yet', 'warning', 'Expected until the first backup runs.');
        }

        $days = (int)floor((time() - $newest['LastModified']->getTimestamp()) / 86400);
        $detail = $newest['Key'] . ', ' . self::formatBytes((int)$newest['Size'])
            . ', ' . $newest['LastModified']->format('Y-m-d H:i') . ' UTC, ' . $days . ' day(s) old';

        return $days >= self::MAX_BACKUP_AGE_DAYS
            ? self::row('Newest backup', $detail, 'problem', 'Older than ' . self::MAX_BACKUP_AGE_DAYS . ' days.')
            : self::row('Newest backup', $detail, 'ok');
    }

    /**
     * Resolves the exclude list into:
     * - full: structure + data (null means "whole database", no filtering).
     * - structure: excluded tables that stay in the dump as empty, structure-only tables.
     * - missing: exclude names matching no table, views included. The dump refuses these,
     *   because a typo would otherwise back up the data it was meant to leave out.
     */
    private static function resolveTables($connection, array $tableConfig): array
    {
        $exclude = self::parseTableList($tableConfig['exclude'] ?? '');

        if (empty($exclude)) {
            return ['full' => null, 'structure' => [], 'missing' => []];
        }

        $tables = self::listTables($connection, 'SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');

        return [
            'full' => array_values(array_diff(self::listTables($connection, 'SHOW TABLES'), $exclude)),
            'structure' => array_values(array_intersect($tables, $exclude)),
            'missing' => array_values(array_diff($exclude, $tables)),
        ];
    }

    /**
     * Turns the anonymize config into a plan per table. Shared by anonymizedDump()
     * and validate(), so --check and the admin page report exactly what the dump does.
     * Kept: listed columns, primary keys, and integer id / *_id columns (internal keys,
     * see AGENTS.md "Column Naming"). Everything else is faked.
     *
     * @return array tables (name => plan, excluded tables left out), warnings, errors.
     */
    private static function resolveAnonymize($connection, string $database, array $tableConfig): array
    {
        $config = $tableConfig['anonymize'];
        $result = ['tables' => [], 'warnings' => [], 'errors' => []];
        if (!is_array($config) || array_diff(array_keys($config), ['keep', 'keepRows']) !== []) {
            $result['errors'][] = 'anonymize only takes the keys keep and keepRows.';

            return $result;
        }

        $schema = self::resolveAnonymize_schema($connection, $database);
        $excluded = self::parseTableList($tableConfig['exclude'] ?? '');
        $keep = (array)($config['keep'] ?? []);
        $keepRows = (array)($config['keepRows'] ?? []);
        $result = self::resolveAnonymize_checkKeep($result, $schema, $excluded, $keep);
        $result = self::resolveAnonymize_checkKeepRows($result, $schema, $excluded, $keepRows);

        foreach ($schema as $table => $info) {
            if (in_array($table, $excluded, true)) {
                continue;
            }
            $plan = self::resolveAnonymize_table($table, $info, (array)($keep[$table] ?? []), (array)($keepRows[$table] ?? []));
            $result['tables'][$table] = $plan;
            $result['errors'] = array_merge($result['errors'], $plan['errors']);
        }

        return $result;
    }

    /** Base tables only (views hold no rows), with columns, primary key and unique columns. */
    private static function resolveAnonymize_schema($connection, string $database): array
    {
        $schema = [];
        $tables = $connection->execute(
            "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
            [$database]
        )->fetchAll('assoc');
        foreach ($tables as $row) {
            $schema[$row['t']] = ['columns' => [], 'primaryKey' => [], 'unique' => []];
        }

        $columns = $connection->execute(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS name, DATA_TYPE AS type, COLUMN_TYPE AS columnType,
                IS_NULLABLE AS nullable, CHARACTER_MAXIMUM_LENGTH AS maxLength, EXTRA AS extra
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$database]
        )->fetchAll('assoc');
        foreach ($columns as $row) {
            if (isset($schema[$row['t']])) {
                $schema[$row['t']]['columns'][$row['name']] = $row;
            }
        }

        $indexes = $connection->execute(
            'SELECT TABLE_NAME AS t, INDEX_NAME AS indexName, NON_UNIQUE AS nonUnique, COLUMN_NAME AS name
             FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            [$database]
        )->fetchAll('assoc');
        foreach ($indexes as $row) {
            if (!isset($schema[$row['t']])) {
                continue;
            }
            if ($row['indexName'] === 'PRIMARY') {
                $schema[$row['t']]['primaryKey'][] = $row['name'];
            } elseif ((int)$row['nonUnique'] === 0) {
                $schema[$row['t']]['unique'][$row['name']] = true;
            }
        }

        return $schema;
    }

    /** A wrong keep name is only a warning: the real column is faked anyway, which is safe. */
    private static function resolveAnonymize_checkKeep(array $result, array $schema, array $excluded, array $keep): array
    {
        foreach ($keep as $table => $columns) {
            if (in_array($table, $excluded, true)) {
                $result['errors'][] = 'keep names ' . $table . ', which is in exclude, so it has no data.';
            } elseif (!isset($schema[$table])) {
                $result['warnings'][] = 'keep names no such table: ' . $table;
            } else {
                $unknown = array_diff((array)$columns, array_keys($schema[$table]['columns']));
                if ($unknown !== []) {
                    $result['warnings'][] = 'keep names no such column in ' . $table . ': ' . implode(', ', $unknown);
                }
            }
        }

        return $result;
    }

    /** A wrong keepRows name fails: it would silently lock the admins out of dev. */
    private static function resolveAnonymize_checkKeepRows(array $result, array $schema, array $excluded, array $keepRows): array
    {
        foreach ($keepRows as $table => $rules) {
            if (in_array($table, $excluded, true)) {
                $result['errors'][] = 'keepRows names ' . $table . ', which is in exclude, so it has no data.';
                continue;
            }
            if (!isset($schema[$table])) {
                $result['errors'][] = 'keepRows names no such table: ' . $table;
                continue;
            }
            if (!is_array($rules) || $rules === []) {
                $result['errors'][] = 'keepRows for ' . $table . ' needs a column and a list of values.';
                continue;
            }
            foreach ($rules as $column => $values) {
                if (!isset($schema[$table]['columns'][$column])) {
                    $result['errors'][] = 'keepRows names no such column: ' . $table . '.' . $column;
                } elseif (!is_array($values) || $values === []) {
                    $result['errors'][] = 'keepRows for ' . $table . '.' . $column . ' needs a list of values.';
                }
            }
        }

        return $result;
    }

    private static function resolveAnonymize_table(string $table, array $info, array $keep, array $keepRows): array
    {
        $plan = ['columns' => [], 'primaryKey' => $info['primaryKey'], 'keepRows' => [], 'kept' => [], 'errors' => []];
        foreach ($keepRows as $column => $values) {
            $plan['keepRows'][$column] = array_map('strval', (array)$values);
        }

        foreach ($info['columns'] as $name => $column) {
            $name = (string)$name;
            $isKept = in_array($name, $keep, true) || in_array($name, $info['primaryKey'], true) || self::isInternalKey($column);
            $columnPlan = self::resolveAnonymize_column($table, $column, $isKept, isset($info['unique'][$name]));
            $plan['columns'][] = $columnPlan;
            if ($isKept) {
                $plan['kept'][] = $name;
            }
            if ($columnPlan['error'] !== '') {
                $plan['errors'][] = $columnPlan['error'];
            }
        }

        return $plan;
    }

    private static function resolveAnonymize_column(string $table, array $column, bool $keep, bool $unique): array
    {
        $type = strtolower((string)$column['type']);
        $plan = [
            'name' => (string)$column['name'],
            // Generated columns can't be inserted into: MySQL computes them on restore.
            'generated' => (bool)preg_match('/\b(VIRTUAL|STORED) GENERATED\b/i', (string)$column['extra']),
            'binary' => in_array($type, self::BINARY_TYPES, true),
            // Read as a number (col + 0): PDO's own BIT handling doesn't round-trip.
            'bit' => $type === 'bit',
            'nullable' => $column['nullable'] === 'YES',
            'keep' => $keep,
            'maxLength' => $column['maxLength'] !== null ? (int)$column['maxLength'] : null,
            'fake' => null,
            'pattern' => null,
            'error' => '',
        ];

        if ($keep || $plan['generated']) {
            return $plan;
        }

        return self::resolveAnonymize_fake($plan, $table, $type, (string)$column['columnType'], $unique);
    }

    /** Picks the fake value. The order matches the table in the feature file. */
    private static function resolveAnonymize_fake(array $plan, string $table, string $type, string $columnType, bool $unique): array
    {
        $isText = in_array($type, self::TEXT_TYPES, true);
        $isNumber = in_array($type, self::INT_TYPES, true) || in_array($type, self::NUMBER_TYPES, true);
        $cannot = $table . '.' . $plan['name'] . ' (' . $type . ') can\'t be faked' . ($unique ? ' and kept unique' : '') . '. Add it to keep.';

        if ($isText && str_contains(strtolower($plan['name']), 'email')) {
            $plan['pattern'] = 'email';
        } elseif ($unique && ($isText || in_array($type, ['binary', 'varbinary'], true))) {
            $plan['pattern'] = 'unique';
        } elseif ($unique && $isNumber) {
            $plan['pattern'] = 'rowNumber';
        } elseif ($unique) {
            // NULLs never clash in a unique index.
            $plan['fake'] = $plan['nullable'] ? 'NULL' : null;
            $plan['error'] = $plan['nullable'] ? '' : $cannot;
        } elseif ($isText) {
            $plan['fake'] = "'" . substr(self::FAKE_TEXT, 0, $plan['maxLength'] ?? strlen(self::FAKE_TEXT)) . "'";
        } elseif ($isNumber) {
            $plan['fake'] = '0';
        } elseif ($type === 'enum' && preg_match("/^enum\\(('(?:[^']|'')*')/i", $columnType, $match)) {
            // Already a valid SQL literal, exactly as MySQL prints it.
            $plan['fake'] = $match[1];
        } elseif (isset(self::FIXED_FAKES[$type])) {
            $plan['fake'] = self::FIXED_FAKES[$type];
        } else {
            $plan['fake'] = $plan['nullable'] ? 'NULL' : null;
            $plan['error'] = $plan['nullable'] ? '' : $cannot;
        }

        return $plan;
    }

    /**
     * Splits a newline-separated (or array) table list into a trimmed,
     * de-duplicated array. Supports \r\n and \n line endings so lists
     * pasted directly from phpMyAdmin work without manual conversion.
     */
    private static function parseTableList($value): array
    {
        $lines = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string)$value);

        $tables = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $tables[$line] = $line;
            }
        }

        return array_values($tables);
    }

    private static function listTables($connection, string $sql): array
    {
        $tables = [];
        foreach ($connection->execute($sql)->fetchAll() as $row) {
            $tables[] = (string)reset($row);
        }

        return $tables;
    }

    public static function anonymizedKey(string $connectionName): string
    {
        return self::ANONYMIZED_PREFIX . $connectionName . '.sql';
    }

    private static function recentKey(string $connectionName): string
    {
        return self::RECENT_PREFIX . $connectionName . '.sql';
    }

    /** An internal key per AGENTS.md "Column Naming": an integer named id or *_id (lowercase, exact). */
    private static function isInternalKey(array $column): bool
    {
        $name = (string)$column['name'];

        return in_array(strtolower((string)$column['type']), self::INT_TYPES, true)
            && ($name === 'id' || str_ends_with($name, '_id'));
    }

    private static function quoteName(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private static function sqlValue(\PDO $pdo, $value, array $column): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if ($column['bit']) {
            return (string)(int)$value;
        }
        if ($column['binary']) {
            return $value === '' ? "''" : '0x' . bin2hex((string)$value);
        }

        return $pdo->quote((string)$value);
    }

    private static function isNotFound(\Throwable $e): bool
    {
        return $e instanceof AwsException && $e->getStatusCode() === 404;
    }

    private static function requireCli(string $method): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException("OfflineBox::{$method}() only runs on the CLI.");
        }
    }

    private static function requiredS3Keys(): array
    {
        return ['spaceKey', 'spaceSecret', 'spaceRegion', 'spaceName'];
    }

    private static function s3Endpoint(array $config): string
    {
        return !empty($config['spaceEndpoint'])
            ? $config['spaceEndpoint']
            : 'https://' . $config['spaceRegion'] . '.digitaloceanspaces.com';
    }

    private static function s3Client(array $config): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $config['spaceRegion'],
            'endpoint' => self::s3Endpoint($config),
            'credentials' => [
                'key' => $config['spaceKey'],
                'secret' => $config['spaceSecret'],
            ],
        ]);
    }

    private static function execAllowed(): bool
    {
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

        return function_exists('exec') && !in_array('exec', $disabled, true);
    }

    private static function row(string $label, string $value, string $level, string $message = ''): array
    {
        return ['label' => $label, 'value' => $value, 'level' => $level, 'message' => $message];
    }

    private static function shortMessage(\Throwable $e): string
    {
        return substr($e->getMessage(), 0, 200);
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float)$bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 1) . ' ' . $units[$unit];
    }
}
