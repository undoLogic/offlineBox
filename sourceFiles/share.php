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


function getLocalTime($utcTime, $format = 'Y-m-d H:i:s') {
    $date = new DateTime($utcTime, new DateTimeZone('UTC'));
    $date->setTimezone(new DateTimeZone('America/New_York'));
    return $date->format($format);
}

function convertFilenameToDateTime($filename) {


    //[2] => 2023-07-01_15-14-38.sql
    $str = str_replace('.sql', '', $filename);

    $str = str_replace("_", " ", $str);

    $parts = explode(" ", $str);

    $time = str_replace('-', ':', $parts[1]);

    return $parts[0].' '.$time;


}

function daysSinceDownload($savedate) {
    $savedatetime = strtotime($savedate);

    $timeNow = strtotime('now');

    $seconds = $savedatetime - $timeNow;

    return round($seconds / (60 * 60 * 24));
}