<?php
/**
 * One card of checks. Used for Environment and Storage.
 *
 * @var string $title
 * @var array $rows
 * @var array $levels
 */
?>
<div class="card shadow-sm h-100">
    <div class="card-header">
        <h2 class="h5 mb-0"><?= h($title); ?></h2>
    </div>
    <div>
        <table class="table table-sm align-middle mb-0">
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <span class="badge <?= h($levels[$row['level']]['class']); ?>">
                                <?= h($levels[$row['level']]['label']); ?>
                            </span>
                        </td>
                        <td class="text-nowrap"><?= h($row['label']); ?></td>
                        <td class="text-break">
                            <?= h($row['value']); ?>
                            <?php if ($row['message'] !== ''): ?>
                                <div class="small text-danger"><?= h($row['message']); ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
