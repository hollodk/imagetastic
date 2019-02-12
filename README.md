imagetastic
=============

> This is a very easy toolkit to upload images to google cloud storage including a thumbnail.

[![Build Status](https://img.shields.io/travis/hollodk/imagetastic.svg?style=flat)](https://travis-ci.org/hollodk/imagetastic)
[![Scrutinizer Quality Score](https://img.shields.io/scrutinizer/g/hollodk/imagetastic.svg?style=flat)](https://scrutinizer-ci.com/g/hollodk/imagetastic/)
[![Scrutinizer Code Intelligence](https://scrutinizer-ci.com/g/hollodk/imagetastic/badges/code-intelligence.svg)](https://scrutinizer-ci.com/g/hollodk/imagetastic/)

[![Latest Release](https://img.shields.io/packagist/v/mh/imagetastic.svg)](https://packagist.org/packages/mh/imagetastic)
[![MIT License](https://img.shields.io/packagist/l/mh/imagetastic.svg)](http://opensource.org/licenses/MIT)
[![Total Downloads](https://img.shields.io/packagist/dt/mh/imagetastic.svg)](https://packagist.org/packages/mh/imagetastic)

Developed by [Michael Holm](http://hollo.dk)


```
<?php

use Imagetastic\Client;

require_once(__DIR__.'/vendor/autoload.php');

// your google cloud project name
$project = 'alien-dispatch-8258';

// original picture you want to upload and create thumbnail
$imageUrl = 'https://images.pexels.com/photos/974229/pexels-photo-974229.jpeg?auto=compress&cs=tinysrgb&h=800&w=1200';

// dimentions for the thumbnail
$dimentions = [
    'height' => 300,
    'width' => 400,
];

// your client key, can be downloaded from your google cloud console
$client = new Client(__DIR__.'/client.json', $project);

$r = $client->process($imageUrl, $dimentions);
var_dump($r);
```

The output will be something:

```
object(stdClass)#2 (7) {
  ["height"]=>
  int(800)
  ["width"]=>
  int(800)
  ["ratio"]=>
  int(1)
  ["done"]=>
  bool(true)
  ["mime"]=>
  string(10) "image/jpeg"
  ["originalPath"]=>
  string(78) "https://storage.googleapis.com/alien-dispatch-8258/original/5c59a48f95d47.jpeg"
  ["thumbPath"]=>
  string(83) "https://storage.googleapis.com/alien-dispatch-8258/thumb_400x300/5c59a48f95d47.jpeg"
}
```
