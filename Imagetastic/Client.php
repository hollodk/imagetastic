<?php

namespace Imagetastic;

use Imagetastic\Exception\MyException;
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

    /**
     * local can be file or url
     * sizes is an array with dimentions
     */
    public function process($local, array $thumbs, $cleanup=false)
    {
        $filename = tempnam('/tmp', 'mh-');
        $thumb = null;

        try {
            $res = new \StdClass();
            $res->height = null;
            $res->width = null;
            $res->ratio = null;
            $res->done = false;

            $this->download($local, $filename);

            $gooPath = 'original';

            $dimensions = @getimagesize($filename);

            if (!$dimensions) throw new MyException('Image cannot be read properly');

            $res->height = $dimensions[1];
            $res->width = $dimensions[0];
            $res->mime = $dimensions['mime'];
            $res->ratio = $res->width/$res->height;

            $res->path = sprintf('%s.%s',
                uuid_create(UUID_TYPE_RANDOM),
                $this->getExtension($res->mime)
            );

            $r = $this->upload($filename, $gooPath, $res->path, $res->mime);

            $res->google_meta = $r;
            $res->public_link = $r->public_link;
            $res->thumbnails = [];

            foreach ($thumbs as $size) {
                $thumb = $this->thumb($filename, $size);
                $gooThumbPath = sprintf('thumb_%sx%s', $size['width'], $size['height']);

                $r = $this->upload($thumb->thumbPath, $gooThumbPath, $res->path, $thumb->mime);
                $r->width = $thumb->width;
                $r->height = $thumb->height;
                $r->public_link = $r->public_link;

                $res->thumbnails[] = $r;
            }

            $res->done = true;

        } catch (\Imagine\Exception\RuntimeException $e) {
            $res->error = $e->getMessage();

        } catch (\GuzzleHttp\Exception\ServerException $e) {
            $res->error = $e->getMessage();

        } catch (MyException $e) {
            $res->error = $e->getMessage();
        }

        if ($cleanup) {
            $this->cleanup([
                $filename,
                $thumb->thumbPath,
            ]);
        }

        return $res;
    }

    public function thumb($original, array $size)
    {
        try {
            $res = new \StdClass();
            $res->done = false;
            $res->width = $size['width'];
            $res->height = $size['height'];

            $thumbPath = tempnam('/tmp', 'mh-thumb-');

            $dimensions = @getimagesize($original);

            if (!$dimensions) throw new MyException('Image cannot be read properly');

            $res->mime = $dimensions['mime'];

            $extension = $this->getExtension($res->mime);
            $thumbPath .= '.'.$extension;

            $filter = new WebOptimization();
            $imagine = new \Imagine\Gd\Imagine();

            $image = $filter->apply(
                $imagine->open($original)
                ->thumbnail(
                    new \Imagine\Image\Box($size['width'], $size['height']),
                    \Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND
                )
            )
            ;

            switch ($res->mime) {
            case 'image/jpeg':
                $image->save($thumbPath, ['jpeg_quality' => 70]);

                exec('jpegoptim '. $thumbPath);

                break;

            case 'image/png':
                $image->save($thumbPath, ['png_compression_level' => 9]);
                break;

            case 'image/gif':
                $image->save($thumbPath);
                break;

            default:
                throw new MyException('Quality drop for '.$res->mime.' not supported');
            }

            $res->original = $original;
            $res->thumbPath = $thumbPath;
            $res->done = true;

        } catch (\Imagine\Exception\RuntimeException $e) {
            $res->error = $e->getMessage();

        } catch (\GuzzleHttp\Exception\ServerException $e) {
            $res->error = $e->getMessage();

        } catch (MyException $e) {
            $res->error = $e->getMessage();
        }

        return $res;
    }

    private function getExtension($mime)
    {
        $extension = null;

        switch ($mime) {
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

        return $extension;
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

        return file_get_contents($filename);
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
