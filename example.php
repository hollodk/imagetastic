<?php

use Imagetastic\Client;

require_once(__DIR__.'/vendor/autoload.php');

$client = new Client();

$project = 'alien-dispatch-8258';
$imageUrl = 'https://images.pexels.com/photos/974229/pexels-photo-974229.jpeg?auto=compress&cs=tinysrgb&h=800&w=1200';
$dimentions = [
    'height' => 300,
    'width' => 400,
];

$r = $client->process($imageUrl, $dimentions, $project);
var_dump($r);
