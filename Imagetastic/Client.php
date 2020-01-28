<?php

namespace Imagetastic;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\Middleware\AuthTokenMiddleware;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Imagine\Filter\Basic\WebOptimization;

class Client
{
    private $key;
    private $project;

    public function __construct($key, $project)
    {
        $this->key = $key;
        $this->project = $project;
    }

    public function process($imageUrl, array $thumb)
    {
        try {
            $res = new \StdClass();
            $res->height = null;
            $res->width = null;
            $res->ratio = null;
            $res->done = false;

            $filename = tempnam('/tmp', 'mh-');
            $thumbPath = tempnam('/tmp', 'mh-thumb-');

            $this->download($imageUrl, $filename);

            $identifier = uniqid();

            $gooPath = 'original';
            $gooThumbPath = sprintf('thumb_%sx%s', $thumb['width'], $thumb['height']);

            $dimentions = @getimagesize($filename);

            if (!$dimentions) {
                throw new \Exception('Image cannot be read properly');
            }

            $res->height = $dimentions[1];
            $res->width = $dimentions[0];
            $res->mime = $dimentions['mime'];
            $res->ratio = $res->width/$res->height;

            switch ($res->mime) {
            case 'image/jpeg':
                $extension = 'jpeg';
                break;

            case 'image/png':
                $extension = 'png';
                break;

            case 'image/gif':
                $extension = 'gif';
                break;
            }

            $path = $identifier.'.'.$extension;
            $thumbPath .= '.'.$extension;

            $filter = new WebOptimization();
            $imagine = new \Imagine\Gd\Imagine();

            $image = $filter->apply(
                $imagine->open($filename)
                ->thumbnail(
                    new \Imagine\Image\Box($thumb['width'], $thumb['height']),
                    \Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND
                )
            )
            ;

            switch ($res->mime) {
            case 'image/jpeg':
                $image->save($thumbPath, ['jpeg_quality' => 70]);

                exec('jpegoptim '. $filename);
                exec('jpegoptim '. $thumbPath);

                break;

            case 'image/png':
                $image->save($thumbPath, ['png_compression_level' => 9]);
                break;

            case 'image/gif':
                $image->save($thumbPath);
                break;

            default:
                throw new \Exception('Quality drop for '.$res->mime.' not supported');
            }

            $r = $this->upload($filename, $gooPath, $path, $res->mime);
            $res->originalPath = $r->public_link;

            $r = $this->upload($thumbPath, $gooThumbPath, $path, $res->mime);
            $res->thumbPath = $r->public_link;

            $res->done = true;

        } catch (\Imagine\Exception\RuntimeException $e) {
            $res->error = $e->getMessage();

        } catch (\GuzzleHttp\Exception\ServerException $e) {
            $res->error = $e->getMessage();

        } catch (\Exception $e) {
            $res->error = $e->getMessage();
        }

        $this->cleanup([
            $filename,
            $thumbPath,
        ]);

        return $res;
    }

    public function download($url, $filename)
    {
        if (preg_match("/http:\/\//", $url)) {
            $httpClient = new HttpClient();
            $response = $httpClient->request('GET', $url);

            file_put_contents($filename, $response->getBody()->getContents());

        } else {
            file_put_contents($filename, file_get_contents($url));
        }
    }

    public function delete($object)
    {
        $client = $this->getClient();

        $url = sprintf('https://www.googleapis.com/storage/v1/b/%s/o/%s',
            $this->project,
            urlencode($object)
        );

        $response = $client->request('DELETE', $url);

        return json_decode($response->getBody()->getContents());
    }

    public function list()
    {
        $client = $this->getClient();

        $url = sprintf('https://www.googleapis.com/storage/v1/b/%s/o',
            $this->project
        );

        $response = $client->request('GET', $url);

        return json_decode($response->getBody()->getContents());
    }

    public function upload($localFile, $path, $filename, $mime)
    {
        $client = $this->getClient();

        $destination = $path.'/'.$filename;

        $gooUrl = 'https://storage.googleapis.com/'.$this->project.'/';
        $publicLink = $gooUrl.$destination;

        $url = sprintf('https://www.googleapis.com/upload/storage/v1/b/%s/o?uploadType=media&name=%s',
            $this->project,
            urlencode($destination)
        );

        $response = $client->request('POST', $url, [
            'headers' => [
                'Content-Type' => $mime,
                'Content-Length' => filesize($localFile),
            ],
            'body' => file_get_contents($localFile)
        ]);

        $o = new \StdClass();
        $o->public_link = $publicLink;
        $o->response = json_decode($response->getBody()->getContents());

        return $o;
    }

    private function cleanup($files)
    {
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function getClient()
    {
        $sa = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/cloud-platform',
            $this->key
        );

        $middleware = new AuthTokenMiddleware($sa);
        $stack = HandlerStack::create();
        $stack->push($middleware);

        return new HttpClient([
            'handler' => $stack,
            'auth' => 'google_auth'
        ]);
    }
}
