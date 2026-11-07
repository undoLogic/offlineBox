<?php
/**
 * @var array $check
 * @var array $levels
 */
?>
<div class="card shadow-sm mb-0">
    <div class="card-body">
        <h2 class="h5">
            <span class="badge <?= h($levels[$check['STATUS'] === 200 ? 'ok' : 'problem']['class']); ?>">
                <?= h($check['MSG']); ?>
            </span>
        </h2>
        <p class="mb-2">
            <?php foreach ($levels as $level => $display): ?>
                <span class="badge <?= h($display['class']); ?>">
                    <?= h($check['counts'][$level]); ?> <?= h($display['label']); ?>
                </span>
            <?php endforeach; ?>
        </p>
        <p class="small text-muted mb-0">
            Checked from the web server. Run <code>bin/cake offline_box --check</code> as the cron user to check the
            cron environment. This page can't prove that the key can write to the bucket, that a real dump works,
            that cron is scheduled, or that a backup restores.
        </p>
    </div>
</div>
