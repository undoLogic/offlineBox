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


function listFile($dir) {
    $ignore = ['.','..','empty'];
    return array_diff(
        scandir($dir),
        $ignore
    );
}