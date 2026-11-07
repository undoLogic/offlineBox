<?php
declare(strict_types=1);

/**
 * OfflineBox by undoLogic
 * Copyright (c) 2021-2026 undoLogic Inc.
 *
 * Licensed under the Apache License, Version 2.0.
 * See the LICENSE and NOTICE files in the project root.
 * This notice must be retained in copies or substantial portions of this file.
 */

// Copy to src/Command/OfflineBoxCommand.php, then edit CONNECTIONS and the failure
// hook for the project. Run: bin/cake offline_box (or --check to change nothing).

namespace App\Command;

use App\Util\OfflineBox;
use Cake\Console\Arguments;
use Cake\Command\Command;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Log\Log;

class OfflineBoxCommand extends Command
{
    // Leave blank unless projects share a php.ini. 'UPDATECASE_' reads UPDATECASE_OFFLINEBOX_S3_KEY
    // and so on. A prefixed project never falls back to the plain names.
    public const SETTING_PREFIX = '';

    // Public so the admin validation page shows the same list that gets backed up.
    // Add 'anonymize' to also upload an anonymized copy to backups_anonymized/<connection>.sql.
    // Only listed columns and keepRows rows keep real data; everything else is faked.
    // e.g. 'default' => [
    //     'exclude' => "activity_logs",
    //     'anonymize' => [
    //         'keep' => ['users' => ['user_type', 'active', 'created'], 'products' => ['name', 'price']],
    //         'keepRows' => ['users' => ['user_type' => ['admin']]],
    //     ],
    // ],
    public const CONNECTIONS = [
        'default' => ['exclude' => ''],
    ];

    public static function s3Config(): array
    {
        return [
            'spaceKey' => self::setting('OFFLINEBOX_S3_KEY'),
            'spaceSecret' => self::setting('OFFLINEBOX_S3_SECRET'),
            'spaceRegion' => self::setting('OFFLINEBOX_S3_REGION'),
            'spaceName' => self::setting('OFFLINEBOX_S3_BUCKET'),
            'spaceEndpoint' => self::setting('OFFLINEBOX_S3_ENDPOINT'),
            'settingPrefix' => self::SETTING_PREFIX,
        ];
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)->addOption('check', [
            'boolean' => true,
            'help' => 'Run the validation checks only. Dumps and uploads nothing.',
        ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        if ($args->getOption('check')) {
            return $this->execute_check($io);
        }

        $log = [];

        foreach (self::CONNECTIONS as $name => $tables) {
            $log[$name] = $this->execute_backup($name, $tables, $io);
            $io->out($name . ': ' . $log[$name]);

            if (isset($tables['anonymize'])) {
                $label = $name . ' (anonymized)';
                $log[$label] = $log[$name] === 'OK'
                    ? $this->execute_anonymized($name, $tables, $io)
                    : 'SKIPPED: the full backup failed';
                $io->out($label . ': ' . $log[$label]);
            }
        }

        if (array_diff($log, ['OK']) === []) {
            return static::CODE_SUCCESS;
        }

        Log::error('[OfflineBox] Backup failed: ' . json_encode($log));
        // Failure hook: use the project's existing email or queue.
        // e.g. $this->queueEmail('OfflineBox backup failed', $log);

        return static::CODE_ERROR;
    }

    private function execute_check(ConsoleIo $io): int
    {
        $check = OfflineBox::validate(self::CONNECTIONS, self::s3Config());
        $io->out(json_encode($check, JSON_PRETTY_PRINT));

        return $check['STATUS'] === 200 ? static::CODE_SUCCESS : static::CODE_ERROR;
    }

    // One bad connection must not stop the rest.
    private function execute_backup(string $name, array $tables, ConsoleIo $io): string
    {
        $dump = [];

        try {
            $client = new OfflineBox(self::s3Config());
            $dump = $client->mysqlDump($name, '', $tables);
            if ($dump['STATUS'] !== 200) {
                throw new \RuntimeException($dump['MSG']);
            }
            $io->out($name . ': dumped database ' . $dump['database']);

            $key = OfflineBox::KEY_PREFIX . $dump['pathInfo']['basename'];
            $upload = $client->upload($dump['file'], $key);
            if ($upload['STATUS'] !== 200) {
                throw new \RuntimeException($upload['MSG']);
            }

            $recent = $client->copyToRecent($dump['file'], $key, $name);
            if ($recent['STATUS'] !== 200) {
                throw new \RuntimeException($recent['MSG']);
            }

            return 'OK';
        } catch (\Throwable $e) {
            return 'FAILED: ' . $e->getMessage();
        } finally {
            // Never leave unencrypted dumps on the web server.
            if (!empty($dump['file']) && is_file($dump['file'])) {
                unlink($dump['file']);
            }
        }
    }

    // Runs only after the full backup succeeded, and never touches it.
    private function execute_anonymized(string $name, array $tables, ConsoleIo $io): string
    {
        $dump = [];

        try {
            $client = new OfflineBox(self::s3Config());
            $dump = $client->anonymizedDump($name, $tables);
            if ($dump['STATUS'] !== 200) {
                throw new \RuntimeException($dump['MSG']);
            }
            foreach ($dump['warnings'] as $warning) {
                $io->warning($name . ' (anonymized): ' . $warning);
                Log::warning('[OfflineBox] ' . $name . ' (anonymized): ' . $warning);
            }

            $upload = $client->upload($dump['file'], OfflineBox::anonymizedKey($name));
            if ($upload['STATUS'] !== 200) {
                throw new \RuntimeException($upload['MSG']);
            }

            return 'OK';
        } catch (\Throwable $e) {
            return 'FAILED: ' . $e->getMessage();
        } finally {
            if (!empty($dump['file']) && is_file($dump['file'])) {
                unlink($dump['file']);
            }
        }
    }

    private static function setting(string $name): string
    {
        $name = self::SETTING_PREFIX . $name;

        return (string)(get_cfg_var($name) ?: env($name) ?: '');
    }
}
