<?php

include ('share.php');

$configFile = 'offlineBox_config.json';

if (!file_exists($configFile)) {
    pr('ERROR: config file does not exist');
} else {
    $backups = json_decode(file_get_contents($configFile), true);
}
?>


<ul>
<?php foreach ($backups['backups'] as $backupName => $backup): ?>

    <li>
        <a href="run.php?single=<?= $backupName; ?>">Manually run <?= $backupName; ?></a>
    </li>

<?php endforeach; ?>

    <li>
        <a href="run.php">Manually run ALL downloads</a>
    </li>
</ul>





