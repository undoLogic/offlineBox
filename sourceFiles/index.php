<?php

include ('share.php');

$configFile = 'offlineBox_config.json';

$localConfig_json = file_get_contents($configFile);

$localConfig = json_decode($localConfig_json, true);


dd($localConfig);

