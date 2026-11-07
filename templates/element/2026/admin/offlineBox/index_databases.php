<?php
/**
 * What will be exported, one row per configured connection, with downloads
 * of the latest files already in the bucket.
 *
 * @var array $check
 * @var array $levels
 */
?>
<div class="card shadow-sm">
    <div class="card-header">
        <h2 class="h5 mb-0">Databases: what will be exported</h2>
    </div>
    <?php // Never add table-responsive to OfflineBox tables: it bumps the layout down. ?>
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
                    <th>Download</th>
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
                        <td class="text-nowrap">
                            <?php foreach ($database['downloads'] as $download): ?>
                                <div class="mb-1">
                                    <?php if ($download['available']): ?>
                                        <?= $this->Html->link($download['label'], ['action' => $download['action'], $database['connection']], ['class' => 'btn btn-sm btn-outline-primary']); ?>
                                    <?php else: ?>
                                        <span class="btn btn-sm btn-outline-secondary disabled"><?= h($download['label']); ?></span>
                                    <?php endif; ?>
                                    <span class="small text-muted"><?= h($download['detail']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php if (!empty($database['messages']) || !empty($database['tables'])): ?>
                        <tr>
                            <td colspan="9" class="bg-light">
                                <?php foreach ($database['messages'] as $message): ?>
                                    <div class="small text-danger"><?= h($message); ?></div>
                                <?php endforeach; ?>
                                <?php if (!empty($database['tables'])): ?>
                                    <details>
                                        <summary class="small">Show <?= h(count($database['tables'])); ?> tables, largest first</summary>
                                        <p class="small text-muted mt-2 mb-1">
                                            To leave a table's data out of the backup, add it to <code>exclude</code> in <code>OfflineBoxCommand::CONNECTIONS</code>.
                                            In the anonymized backup, columns not listed under a table are faked.
                                        </p>
                                        <ul class="list-unstyled small mb-0 mt-2">
                                            <?php foreach ($database['tables'] as $table): ?>
                                                <li>
                                                    <span class="badge <?= $table['export'] === 'Structure only' ? 'bg-secondary' : 'bg-primary'; ?>">
                                                        <?= h($table['export']); ?>
                                                    </span>
                                                    <span class="text-muted"><?= h($table['size']); ?></span>
                                                    <?= h($table['name']); ?>
                                                    <?php if ($table['anonymize'] !== ''): ?>
                                                        <div class="text-muted ms-3"><?= h($table['anonymize']); ?></div>
                                                    <?php endif; ?>
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
