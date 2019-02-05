<?php

namespace Imagetastic;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\Middleware\AuthTokenMiddleware;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Imagine\Filter\Basic\WebOptimization;
use Symfony\Component\HttpFoundation\File\File;

class Client
{
    public function process($imageUrl, array $thumb, $project)
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
                __DIR__.'/../client.json'
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

            $file = new File($filename);

            $path = $identifier.'.'.$file->guessExtension();
            $thumbPath .= '.'.$file->guessExtension();

            $gooUrl = 'https://storage.googleapis.com/'.$project.'/';
            $res->originalPath = $gooUrl.$gooPath.'/'.$path;
            $res->thumbPath = $gooUrl.$gooThumbPath.'/'.$path;

            $filter = new WebOptimization();
            $imagine = new \Imagine\Gd\Imagine();

            $image = $filter->apply(
                $imagine->open($file->getRealPath())
                ->thumbnail(
                    new \Imagine\Image\Box($thumb['width'], $thumb['height']),
                    \Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND
                )
            )
            ;

            switch ($file->getMimeType()) {
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
                throw new \Exception('Quality drop for '.$file->getMimeType().' not supported');
            }

            $thumbFile = new File($thumbPath);

            $response = $client->request('POST', 'https://www.googleapis.com/upload/storage/v1/b/'.$project.'/o?uploadType=media&name='.$gooPath.'/'.$path, [
                'headers' => [
                    'Content-Type' => $file->getMimeType(),
                    'Content-Length' => $file->getSize(),
                ],
                'body' => file_get_contents($file->getRealPath())
            ]);

            $response = $client->request('POST', 'https://www.googleapis.com/upload/storage/v1/b/'.$project.'/o?uploadType=media&name='.$gooThumbPath.'/'.$path, [
                'headers' => [
                    'Content-Type' => $thumbFile->getMimeType(),
                    'Content-Length' => $thumbFile->getSize(),
                ],
                'body' => file_get_contents($thumbFile->getRealPath())
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

    private function cleanup($file)
    {
        if (file_exists($file)) {
            unlink($file->getRealPath());
        }
    }
}
