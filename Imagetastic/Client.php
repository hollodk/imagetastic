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

            $identifier = uniqid();

            $gooPath = 'original';
            $gooThumbPath = sprintf('thumb_%sx%s', $thumb['width'], $thumb['height']);

            $sa = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/cloud-platform',
                $this->key
            );

            $middleware = new AuthTokenMiddleware($sa);
            $stack = HandlerStack::create();
            $stack->push($middleware);

            $client = new HttpClient([
                'handler' => $stack,
                'auth' => 'google_auth'
            ]);

            $httpClient = new HttpClient();
            $response = $httpClient->request('GET', $imageUrl);

            file_put_contents($filename, $response->getBody()->getContents());

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

            $gooUrl = 'https://storage.googleapis.com/'.$this->project.'/';
            $res->originalPath = $gooUrl.$gooPath.'/'.$path;
            $res->thumbPath = $gooUrl.$gooThumbPath.'/'.$path;

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

            $response = $client->request('POST', 'https://www.googleapis.com/upload/storage/v1/b/'.$this->project.'/o?uploadType=media&name='.$gooPath.'/'.$path, [
                'headers' => [
                    'Content-Type' => $res->mime,
                    'Content-Length' => filesize($filename),
                ],
                'body' => file_get_contents($filename)
            ]);

            $response = $client->request('POST', 'https://www.googleapis.com/upload/storage/v1/b/'.$this->project.'/o?uploadType=media&name='.$gooThumbPath.'/'.$path, [
                'headers' => [
                    'Content-Type' => $res->mime,
                    'Content-Length' => filesize($thumbPath),
                ],
                'body' => file_get_contents($thumbPath)
            ]);

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

    private function cleanup($files)
    {
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
