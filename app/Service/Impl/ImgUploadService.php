<?php

namespace App\Service\Impl;

use App\Lib\ConfigKey;
use App\Lib\Facade\Container;
use App\Model\File;
use App\Repository\Impl\ConfigRepository;
use App\Repository\Impl\FileRepository;
use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use GuzzleHttp\Client;

/**
 * 图片上传.
 */
class ImgUploadService
{

    protected S3Client $s3;

    protected string $bucket;

    protected string $uploadPath;

    protected string $siteBid;

    protected string $s3Dir;

    protected const AwsS3CertFilePath = BASE_PATH.'/app/Lib/Data/cacert.pem';

    /**
     * @throws \Exception
     */
    public function __construct(string $siteBid)
    {
        /** @var ConfigRepository $configRepo */
        $configRepo    = Container::get(ConfigRepository::class);
        $this->siteBid = $siteBid;
        $awsConfig     = $configRepo->getByKeys([ConfigKey::AwsS3Bucket, ConfigKey::AwsS3Key, ConfigKey::AwsS3Region, ConfigKey::AwsS3Token, ConfigKey::S3Dir]);
        if (empty($awsConfig)) {
            throw new \Exception('未找到aws上传配置');
        }
        $this->s3Dir = $awsConfig[ConfigKey::S3Dir];

        $guzzleClient = new Client([
            'timeout' => 10,
        ]);

        $this->s3 = new S3Client([
            'version'      => 'latest',
            'region'       => $awsConfig[ConfigKey::AwsS3Region],
            'credentials'  => [
                'key'    => $awsConfig[ConfigKey::AwsS3Key],
                'secret' => $awsConfig[ConfigKey::AwsS3Token],
            ],
            'http'         => ['verify' => self::AwsS3CertFilePath],
            'http_handler' => new GuzzleHandler($guzzleClient),
        ]);

        /**
         * 'version' => 'latest',
         * 'region' => $aws_config['aws_region'],
         * 'http'                    => [
         * 'verify' => __DIR__ . '/../data/cacert.pem'
         * ],
         * 'credentials' => [
         * 'key'    => $aws_config['aws_key'],
         * 'secret' => $aws_config['aws_token']
         * ]
         */

        $this->bucket = $awsConfig[ConfigKey::AwsS3Bucket];
    }

    /**
     * 将远端图片下载后转上传到S3.
     *
     * @param  string  $sourceImgCdnPath  来源图片路径
     * @param  string  $sourceCdnUrl      来源图片端CDN网址
     *
     * @return array
     */
    public function uploadGroupCover(string $sourceImgCdnPath, string $sourceCdnUrl): array
    {
        $httpClient = new Client(['verify' => false]);
        try {
            $response = $httpClient->get($sourceCdnUrl.$sourceImgCdnPath);
        } catch (\Throwable $e) {
            return [false, "获取远端图片异常:".\App\Lib\Helper::getExpDetails($e)];
        }

        if ($response->getStatusCode() !== 200) {
            return [false, "下载远端图片失败, http code:".$response->getStatusCode()];
        }

        $content = $response->getBody()->getContents();
        if (empty($content)) {
            return [false, "下载远端图片失败C"];
        }

        $mimeType  = $response->getHeaderLine('Content-Type');
        $extension = $this->getExtensionFromMime($mimeType);
        if ( ! $extension) {
            return [false, "不支援的图片格式: ".$mimeType];
        }

        $md5 = md5($content);
        /** @var FileRepository $fileRepo */
        $fileRepo  = Container::get(FileRepository::class);
        $existFile = $fileRepo->getByMd5($this->siteBid, $md5);
        if ( ! empty($existFile)) {
            $path = $existFile['path'];

            return [true, $path];
        }

        $this->uploadPath = sprintf('%s/chat/%s/g_cover/', $this->s3Dir, $this->siteBid);
        $fileNameFrags    = explode('/', $sourceImgCdnPath);
        $filename         = ! empty($fileNameFrags) ? $this->uploadPath.$fileNameFrags[count($fileNameFrags) - 1] : '';
        if (empty($filename)) {
            $filename = ltrim($this->uploadPath.date('YmdHis').uniqid('', true).'.'.$extension, '/');
        }

        $this->s3->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $filename,
            'Body'        => $content,
            'ACL'         => 'public-read',
            'ContentType' => $mimeType,
        ]);

        $filename = '/'.$filename;
        $fileRepo->add(['type' => File::TypeImage, 'path' => $filename, 'create_time' => time(), 'md5' => $md5, 'site_bid' => $this->siteBid]);

        return [true, $filename];
    }

    protected function getExtensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => '',
        };
    }

}