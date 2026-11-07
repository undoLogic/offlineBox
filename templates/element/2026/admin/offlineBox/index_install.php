<?php
/**
 * Cron setup steps, with this server's path already filled in.
 *
 * @var array $install
 */
?>
<div class="card shadow-sm">
    <div class="card-header">
        <h2 class="h5 mb-0">Installation: set up the cron job</h2>
    </div>
    <div class="card-body">
        <p class="small text-muted"><?= h($install['intro']); ?></p>
        <ol class="mb-3">
            <?php foreach ($install['steps'] as $step): ?>
                <li class="mb-3">
                    <div><?= h($step['text']); ?></div>
                    <?php if ($step['command'] !== ''): ?>
                        <pre class="bg-light border rounded p-2 mt-1 mb-0 user-select-all"><code><?= h($step['command']); ?></code></pre>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <ul class="small text-muted mb-0">
            <?php foreach ($install['notes'] as $note): ?>
                <li><?= h($note); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
