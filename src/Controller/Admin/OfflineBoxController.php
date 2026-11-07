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

/**
 * Read-only validation page. It never runs a backup: mysqlDump() and upload()
 * refuse to run outside the CLI.
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
}
