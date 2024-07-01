<?php

# Setup content
$config = [
    'urlsToDownload' => [
        'www.offlincebox.com'
    ],
    'pathsToSaveTo' => [
        '/where/to/go'
    ],
    'urlsToNotify' => [
        'https://www.offlincebox.com/notifyEndPoint'
    ]
];
$configFile = 'offlineBox_config.json';

function pr($msg) {
    echo '<pre>';
    print_r($msg);
    echo '</pre>';
}
function dd($msg) {
    pr($msg);
    exit;
}

if (!file_exists('offlineBox_config.json')) {
    //pr('does not exist');
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    dd ('created config file: Now go and add your urls and destinations and run again');
}



$localConfig_json = file_get_contents($configFile);

$localConfig = json_decode($localConfig_json, true);

//dd($localConfig);



foreach ($localConfig['urlsToDownload'] as $urlToDownload) {

    #pr ($urlToDownload);

    $data = file_get_contents($urlToDownload);

    //dd($data);

    $filename = date('Y-m-d_H-i-s').'.sql';

    pr ('Downloading...');

    foreach ($localConfig['pathsToSaveTo'] as $pathToSaveTo) {
        file_put_contents($pathToSaveTo.$filename, $data);
    }

}


pr ('Downloaded: backups/'.$filename);

