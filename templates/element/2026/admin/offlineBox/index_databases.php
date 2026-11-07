<?php
/**
 * What will be exported, one row per configured connection.
 *
 * @var array $check
 * @var array $levels
 */
?>
<div class="card shadow-sm">
    <div class="card-header">
        <h2 class="h5 mb-0">Databases: what will be exported</h2>
    </div>
    <div>
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Connection</th>
                    <th>Database</th>
                    <th>Will export</th>
                    <th>Connects</th>
                    <th>With data</th>
                    <th>Structure only</th>
                    <th>Size</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($check['databases'] as $database): ?>
                    <tr>
                        <td>
                            <span class="badge <?= h($levels[$database['level']]['class']); ?>">
                                <?= h($levels[$database['level']]['label']); ?>
                            </span>
                        </td>
                        <td><?= h($database['connection']); ?></td>
                        <td class="text-break"><?= h($database['database']); ?></td>
                        <td>
                            <span class="badge <?= $database['willExport'] ? 'bg-primary' : 'bg-secondary'; ?>">
                                <?= $database['willExport'] ? 'Yes' : 'No'; ?>
                            </span>
                        </td>
                        <td><?= h($database['connects']); ?> <span class="text-muted small"><?= h($database['version']); ?></span></td>
                        <td><?= h($database['tablesWithData']); ?></td>
                        <td><?= h($database['tablesStructureOnly']); ?></td>
                        <td><?= h($database['size']); ?></td>
                    </tr>
                    <?php if (!empty($database['messages']) || !empty($database['tables'])): ?>
                        <tr>
                            <td colspan="8" class="bg-light">
                                <?php foreach ($database['messages'] as $message): ?>
                                    <div class="small text-danger"><?= h($message); ?></div>
                                <?php endforeach; ?>
                                <?php if (!empty($database['tables'])): ?>
                                    <details>
                                        <summary class="small">Show <?= h(count($database['tables'])); ?> tables, largest first</summary>
                                        <p class="small text-muted mt-2 mb-1">
                                            To leave a table's data out of the backup, add it to <code>exclude</code> in <code>OfflineBoxCommand::CONNECTIONS</code>.
                                        </p>
                                        <ul class="list-unstyled small mb-0 mt-2">
                                            <?php foreach ($database['tables'] as $table): ?>
                                                <li>
                                                    <span class="badge <?= $table['export'] === 'Structure only' ? 'bg-secondary' : 'bg-primary'; ?>">
                                                        <?= h($table['export']); ?>
                                                    </span>
                                                    <span class="text-muted"><?= h($table['size']); ?></span>
                                                    <?= h($table['name']); ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
