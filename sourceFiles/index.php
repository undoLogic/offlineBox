<?php

# Setup content
$config = [
    'urlsToDownload' => [
        'www.offlincebox.com'
    ],
    'pathToSaveTo' => [
        '/where/to/go'
    ],
    'urlToNotify' => [
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
    pr ('created config file');
}



$localConfig = file_get_contents($configFile);

dd($localConfig);

//phpinfo();

//$offlineBox_shared_secret = '123';
//
//$url = "https://offlinebox.com/dump/$offlineBox_shared_secret";
//
//$out = file_get_contents($url);
//
//print_r ($out);
