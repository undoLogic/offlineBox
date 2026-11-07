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

namespace App\Controller\Admin;

use App\Command\OfflineBoxCommand;
use App\Controller\AppController;
use App\Util\OfflineBox;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\Exception\NotFoundException;

/**
 * Read-only validation page and downloads. It never runs a backup: mysqlDump()
 * and upload() refuse to run outside the CLI. Downloads stream files cron
 * already put in the bucket.
 */
class OfflineBoxController extends AppController
{
    public function index()
    {
        $this->request->allowMethod(['get']);

        $this->set('check', OfflineBox::validate(OfflineBoxCommand::CONNECTIONS, OfflineBoxCommand::s3Config()));
        $this->set('install', OfflineBox::installSteps(OfflineBoxCommand::SETTING_PREFIX));
        $this->set('levels', [
            'ok' => ['label' => 'OK', 'class' => 'bg-success'],
            'warning' => ['label' => 'Warning', 'class' => 'bg-warning text-dark'],
            'problem' => ['label' => 'Problem', 'class' => 'bg-danger'],
        ]);
    }

    public function downloadAnonymized(string $connection = '')
    {
        return $this->download_send($connection, 'anonymized');
    }

    public function downloadFull(string $connection = '')
    {
        return $this->download_send($connection, 'full');
    }

    private function download_send(string $connection, string $kind)
    {
        $this->request->allowMethod(['get']);

        $res = OfflineBox::download(OfflineBoxCommand::CONNECTIONS, OfflineBoxCommand::s3Config(), $connection, $kind);
        if ($res['STATUS'] === 404) {
            throw new NotFoundException($res['MSG']);
        }
        if ($res['STATUS'] !== 200) {
            throw new InternalErrorException($res['MSG']);
        }

        // Optional download log: add the project's own logging here (who, when, $connection, $kind).

        // Streamed from the bucket in chunks, so a large dump never sits in memory.
        set_time_limit(0);

        return $this->response
            ->withType('application/sql')
            ->withHeader('Content-Length', (string)$res['size'])
            ->withDownload($res['filename'])
            ->withBody($res['body']);
    }
}
