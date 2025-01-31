<?php

namespace zhelyabuzhsky\sitemap\components;

use zhelyabuzhsky\sitemap\models\SitemapEntityInterface;
use yii\base\Component;
use yii\base\Exception;

/**
 * Sitemap generator.
 */
class Sitemap extends Component
{
    /**
     * Use gzip compression?
     *
     * @var boolean
     */
    public $gzipped = true;

    /**
     * Max count of urls in one sitemap file.
     *
     * @var int
     */
    public $maxUrlsCountInFile;

    /**
     * Directory to place sitemap files.
     *
     * @var string
     */
    public $sitemapDirectory;

    /**
     * List of used optional attributes.
     *
     * @var string[]
     */
    public $optionalAttributes = ['changefreq', 'lastmod', 'priority', 'image', 'video'];

    /**
     * Path to current sitemap file.
     *
     * @var string
     */
    protected $path;

    /**
     * Handle of current sitemap file.
     *
     * @var resource
     */
    protected $handle;

    /**
     * Count of urls in current sitemap file.
     *
     * @var int
     */
    protected $urlCount = 0;

    /**
     * Array of data sources and connections for sitemap generation.
     *
     * @var array[] an array of [\yii\db\ActiveQuery, \yii\db\Connection]
     */
    protected $dataSources = [];

    /**
     * @var array
     */
    protected $disallowUrls = [];

    /**
     * Maximal size of sitemap files.
     * Default value: 10M
     *
     * @var int
     */
    protected $maxFileSize = 10485760; // 10 * 1024 * 1024

    /**
     * Generated sitemap groups file count.
     *
     * @var int
     */
    protected $fileIndex = 0;

    /**
     * List of generated files.
     *
     * @var string[]
     */
    protected $generatedFiles = [];

    /**
     * Create index file sitemap.xml.
     */
    protected function createIndexFile()
    {
        $this->path = "{$this->sitemapDirectory}/_sitemap.xml";
        $this->handle = fopen($this->path, 'w');
        fwrite(
            $this->handle,
            '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL .
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        );
        $objDateTime = new \DateTime('NOW');
        $lastmod = $objDateTime->format(\DateTime::W3C);

        $baseUrl = \Yii::$app->urlManager->baseUrl;
        $hostInfo = \Yii::$app->urlManager->hostInfo;
        $gzip = $this->gzipped ? '.gz' : '';

        foreach ($this->generatedFiles as $fileName) {
            fwrite(
                $this->handle,
                PHP_EOL .
                '<sitemap>' . PHP_EOL .
                "\t" . '<loc>' . $hostInfo . $baseUrl . '/' . $fileName . $gzip . '</loc>' . PHP_EOL .
                "\t" . '<lastmod>' . $lastmod . '</lastmod>' . PHP_EOL .
                '</sitemap>'
            );
        }
        fwrite($this->handle, PHP_EOL . '</sitemapindex>');
        fclose($this->handle);
        if($this->gzipped) $this->gzipFile();
    }

    /**
     * Update sitemap file.
     */
    protected function updateSitemaps($sitemapName)
    {
        // delete old sitemap files
        foreach (glob("{$this->sitemapDirectory}/sitemap_' . $sitemapName . '*.xml*") as $filePath) {
            unlink($filePath);
        }
        // rename new files (without '_')
        foreach (glob("{$this->sitemapDirectory}/_sitemap*.xml*") as $filePath) {
            $newFilePath = dirname($filePath) . '/' . substr(basename($filePath), 1);
            rename($filePath, $newFilePath);
        }
    }

    /**
     * Write header to sitemap file.
     */
    protected function beginFile($sitemapName)
    {
        ++$this->fileIndex;
        $this->urlCount = 0;

        $fileName = 'sitemap_' . $sitemapName . '_' . $this->fileIndex . '.xml';
        $this->path = $this->sitemapDirectory . '/_' . $fileName;
        $this->generatedFiles[] = $fileName;

        $this->handle = fopen($this->path, 'w');
        fwrite(
            $this->handle,
            '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL .
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' .
            ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' .
            ' xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"' .
            ' xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">'
        );
    }

    /**
     * Write footer to sitemap file.
     */
    protected function closeFile()
    {
        fwrite($this->handle, PHP_EOL . '</urlset>');
        fclose($this->handle);
    }

    /**
     * Gzip sitemap file.
     */
    protected function gzipFile()
    {
        $gzipFileName = $this->path . '.gz';
        $mode = 'wb9';
        $error = false;
        if ($fp_out = gzopen($gzipFileName, $mode)) {
            if ($fp_in = fopen($this->path, 'rb')) {
                while (!feof($fp_in))
                    gzwrite($fp_out, fread($fp_in, 1024 * 512));
                fclose($fp_in);
            } else {
                $error = true;
            }
            gzclose($fp_out);
        } else {
            $error = true;
        }
        if ($error)
            return false;
        else
            return $gzipFileName;
    }

    /**
     * Add ActiveQuery from SitemapEntity model to Sitemap model.
     * @param Connection|null   $db     The DB connection to be used when performing batch query.
     *                                  If null, the "db" application component will be used.
     *
     * @param \yii\db\ActiveQuery $dataSource
     */
    public function addDataSource($dataSource, $sitemapName, $db = null)
    {
        $this->dataSources[] = [
            'query' => $dataSource,
            'sitemapName' => $sitemapName,
            'connection' => $db
        ];
    }

    /**
     * Add SitemapEntity model to Sitemap model.
     *
     * @param SitemapEntityInterface|string $model
     * @param Connection|null   $db     The DB connection to be used when performing batch query.
     *                                  If null, the "db" application component will be used.
     * @return $this
     * @throws Exception
     */
    public function addModel($model, $sitemapName, $db = null)
    {
        if (!((new $model()) instanceof SitemapEntityInterface)) {
            throw new Exception("Model $model does not implement interface SitemapEntity");
        }
        $this->addDataSource($model::getSitemapDataSource(), $sitemapName, $db);
        return $this;
    }

    /**
     * Create sitemap file.
     */
    public function create()
    {
        foreach ($this->dataSources as $dataSource) {
            $this->fileIndex = 0;
            $this->beginFile($dataSource['sitemapName']);
            /** @var ActiveQuery $query */
            $query = $dataSource['query'];
            /** @var Connection  $connection */
            $connection = $dataSource['connection'];
            foreach ($query->batch(100, $connection) as $entities) {
                foreach ($entities as $entity) {
                    if (!$this->isDisallowUrl($entity->getSitemapLoc())) {
                        $this->writeEntity($entity, $dataSource['sitemapName']);
                    }
                }
            }
            if (is_resource($this->handle)) {
                $this->closeFile();
                if($this->gzipped) $this->gzipFile();
            }
            $this->createIndexFile();
            $this->updateSitemaps($dataSource['sitemapName']);
        }


    }

    /**
     * Set disallow pattern url.
     *
     * @param array $urls
     * @return $this
     */
    public function setDisallowUrls($urls)
    {
        $this->disallowUrls = $urls;
        return $this;
    }

    /**
     * Set maximal size of sitemap files
     *
     * @param int|string    $size   Maximal file size. Zero to work without limits.
     * So you can specify the following abbreviations k - kilobytes and m - megabytes.
     */
    public function setMaxFileSize($size)
    {
        $fileSizeAbbr = ['k', 'm'];
        if (!is_int($size)) {
            if (is_string($size) && preg_match('/^([\d]*)(' . implode('|', $fileSizeAbbr) . ')?$/i', $size, $matches)) {
                $size = $matches[1];
                if (isset($matches[2])) {
                    $size = $size * pow(1024, array_search(strtolower($matches[2]), $fileSizeAbbr) + 1);
                }
            } else {
                $size = intval($size);
            }
        }
        $this->maxFileSize = $size;
    }

    /**
     * Method checks limits for write in the current file.
     *
     * @param int   $strLen Size of writable string
     * @return boolean
     */
    public function isLimitExceeded($strLen)
    {
        $isStrLenExceeded = function ($strLen) {
            $fileStat = fstat($this->handle);
            return $fileStat['size'] + $strLen > $this->maxFileSize;
        };

        return
            ($this->maxUrlsCountInFile > 0 && $this->urlCount === $this->maxUrlsCountInFile) ||
            ($this->maxFileSize > 0 && $isStrLenExceeded($strLen));
    }

    /**
     * Checking for validity.
     *
     * @param string $url
     * @return bool
     */
    protected function isDisallowUrl($url)
    {
        foreach ($this->disallowUrls as $disallowUrl) {
            if (preg_match($disallowUrl, $url) != false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Write entity to sitemap file.
     *
     * @param SitemapEntityInterface $entity
     */
    protected function writeEntity($entity, $sitemapName)
    {
        $str = PHP_EOL . '<url>' . PHP_EOL;
        foreach (
            array_merge(
                ['loc'],
                $this->optionalAttributes
            ) as $attribute
        ) {
            $tag = ($attribute == 'image' || $attribute == 'video') ? $attribute . ':' . $attribute : $attribute;
            if ( !empty(call_user_func([$entity, 'getSitemap' . $attribute])) )
                $str .= sprintf("\t<%s>%s</%1\$s>", $tag, call_user_func([$entity, 'getSitemap' . $attribute])) . PHP_EOL;
        }

        $str .= '</url>';

        if ($this->isLimitExceeded(strlen($str))) {
            $this->closeFile();
            if($this->gzipped) $this->gzipFile();
            $this->beginFile($sitemapName);
        }

        fwrite($this->handle, $str);
        ++$this->urlCount;
    }
}
