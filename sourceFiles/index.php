<?php

include ('share.php');

$configFile = 'offlineBox_config.json';

$localConfig_json = file_get_contents($configFile);

$localConfig = json_decode($localConfig_json, true);

foreach ($localConfig['pathsToSaveTo'] as $pathToSaveTo) {
    pr ('files at: '.$pathToSaveTo);


    foreach ( listFile($pathToSaveTo)  as $file) {

        $timeOfDoiwnload = getLocalTime(convertFilenameToDateTime($file));

        pr ('file: '.$timeOfDoiwnload.' - Days since: '.daysSinceDownload($timeOfDoiwnload));
    }
}


//echo getLocalTime(date('Y-m-d H:i:s'));
?>


<a href="run.php">Manually run download</a>
