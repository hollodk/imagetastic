<?php

use Imagetastic\Client;

require_once(__DIR__.'/vendor/autoload.php');

$project = 'alien-dispatch-8258';
$imageUrl = 'https://images.pexels.com/photos/974229/pexels-photo-974229.jpeg?auto=compress&cs=tinysrgb&h=800&w=1200';
$dimentions = [
    'height' => 300,
    'width' => 400,
];

$client = new Client(__DIR__.'/client.json', $project);

/*
$list = $client->list();
if (isset($list->items)) {
    foreach ($list->items as $item) {
        dump('found and deleting '.$item->name);

        $r = $client->delete($item->name);
    }

    die();
}
 */

$r = $client->process($imageUrl, $dimentions);
var_dump($r);
