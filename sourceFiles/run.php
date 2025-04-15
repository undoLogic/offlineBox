<?php

ini_set('memory_limit', '512M');

include ('share.php');
pr ('pwd');
echo exec('pwd');
if (!file_exists('offlineBox_config.json')) {
    //pr('does not exist');
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    dd ('created config file: Now go and add your urls and destinations and run again');
}

$localConfig_json = file_get_contents($configFile);

$localConfig = json_decode($localConfig_json, true);

foreach ($localConfig['backups'] as $backupName => $each) {

    if (isset($_GET['single'])) {
        //single only
        if ($backupName == $_GET['single']) {
            //it is the one we want
        } else {
            continue;
        }
    } else {
        //run all
    }
    $url = $each['url'];
    $data = file_get_contents($url);
    $filename = date('Y-m-d_H-i-s').'_'.$backupName.'.sql';
    pr ('Downloading... '.$backupName);
    $dir = '/var/backups/local/';
    file_put_contents($dir.$filename, $data);

    pr ('Downloaded: backups/'.$filename);

}

pr ("DONE");

