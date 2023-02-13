<?php

declare(strict_types=1);

namespace Basilicom\Flysystem\Pdo;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;

class PdoAdapter implements FilesystemAdapter
{
    public const DUMMY_FILE_FOR_FORCED_LISTING_IN_FLYSYSTEM_TEST = '______DUMMY_FILE_FOR_FORCED_LISTING_IN_FLYSYSTEM_TEST';

    private \PDO $pdo;

    private string $table = 'files';

    private string $bucket = 'default';

    private string $cacheDirectory = '/tmp';

    private string $cachePrefix = 'flypdo-';

    private int $cacheMaxAge = 300; // in seconds

    private int $cacheCleanupChance = 100; // 1:100 cleanup chance for each read

    private MimeTypeDetector $mimeTypeDetector;

    public function __construct(
        \PDO $pdo,
        string $bucket = 'default',
        string $table = 'files',
        private string $defaultVisibility = Visibility::PUBLIC,
        MimeTypeDetector $mimeTypeDetector = null
    ) {
        $this->pdo = $pdo;
        $this->bucket = $bucket;
        $this->table = $table;
        $this->mimeTypeDetector = $mimeTypeDetector ?: new FinfoMimeTypeDetector();
    }

    private function getFileAttributes(string $path): FileAttributes
    {
        $path = $this->preparePath($path);
        $statement = $this->pdo->prepare(
            'SELECT `size`, visibility, UNIX_TIMESTAMP(lastModified) as lastModified, mimeType
                FROM '.$this->table.' WHERE bucket = ? AND path = ?'
        );
        $statement->execute([$this->bucket, $path]);
        if (1 !== $statement->rowCount()) {
            throw new UnableToReadFile($path);
        }
        /** @var array $row */
        $row = $statement->fetch();

        return new FileAttributes(
            $path,
            (int) $row['size'],
            (string) $row['visibility'],
            (int) $row['lastModified'],
            (string) $row['mimeType']
        );
    }

    public function fileExists(string $path): bool
    {
        $statement = $this->pdo->prepare('SELECT path FROM '.$this->table.' WHERE bucket = ? AND path = ?');
        $statement->execute([$this->bucket, $this->preparePath($path)]);
        $cnt = $statement->rowCount();

        return 1 === $cnt;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $path = $this->preparePath($path);
        // Detect by contents, fall back to detection by extension.
        $mimeType = $this->mimeTypeDetector->detectMimeType($path, $contents);
        if (null == $mimeType) {
            $mimeType = '';
        }

        $timestamp = (int) $config->get('timestamp');
        if (0 === $timestamp) {
            $timestamp = time();
        }

        $visibility = (string) $config->get(Config::OPTION_VISIBILITY, $this->defaultVisibility);
        $statement = $this->pdo->prepare('REPLACE INTO '.$this->table.'(bucket, path, contents, mimeType, `size`, visibility, lastModified, checksum) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute(
            [
                $this->bucket,
                $path,
                $contents,
                $mimeType,
                strlen($contents),
                $visibility,
                date('Y-m-d H:i:s', $timestamp),
                $this->getChecksumForContent($contents),
            ]
        );
        $this->writeToCache($path, $contents);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $contents = (string) stream_get_contents($contents);
        $this->write($path, $contents, $config);
    }

    public function read(string $path): string
    {
        $path = $this->preparePath($path);

        $contents = $this->readFromCache($path);
        if (null !== $contents) {
            if ($this->getChecksumForContent($contents) === $this->getChecksumForPath($path)) {
                return $contents;
            }
        }

        $statement = $this->pdo->prepare('SELECT contents FROM '.$this->table.' WHERE bucket = ? AND path = ?');
        $statement->execute([$this->bucket, $path]);
        $cnt = $statement->rowCount();
        if (1 !== $cnt) {
            throw UnableToReadFile::fromLocation($path, 'file does not exist');
        }
        /** @var array $row */
        $row = $statement->fetch();

        $contents = (string) $row['contents'];
        $this->cleanupCache();
        $this->writeToCache($path, $contents);

        return $contents;
    }

    public function readStream(string $path)
    {
        $path = $this->preparePath($path);
        $this->read($path); // creates cache local file
        $filename = $this->getCacheFilename($path);

        return fopen($filename, 'r');
    }

    public function delete(string $path): void
    {
        $path = $this->preparePath($path);
        $statement = $this->pdo->prepare('DELETE FROM '.$this->table.' where bucket = ? AND path = ?');
        $statement->execute([$this->bucket, $this->preparePath($path)]);
    }

    public function deleteDirectory(string $path): void
    {
        $path = $this->preparePath($path);
        $path = \rtrim($path, '/').'/';

        $statement = $this->pdo->prepare('DELETE FROM '.$this->table.' where bucket = ? AND path like ?');
        $statement->execute([$this->bucket, $path.'%']);
        $this->delete(\rtrim($this->preparePath($path), '/'));
    }

    public function createDirectory(string $path, Config $config): void
    {
        $filePath = \rtrim($path, '/').'/'.self::DUMMY_FILE_FOR_FORCED_LISTING_IN_FLYSYSTEM_TEST;
        $this->write($filePath, '', $config);
    }

    public function directoryExists(string $path): bool
    {
        $prefix = $this->preparePath($path);
        $prefix = \rtrim($prefix, '/').'/';

        $statement = $this->pdo->prepare('SELECT path FROM '.$this->table.' WHERE bucket = ? AND path like ? LIMIT 1');
        $statement->execute([$this->bucket, $prefix.'%']);

        return 1 == $statement->rowCount();
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if (!$this->fileExists($path)) {
            throw UnableToSetVisibility::atLocation($path, 'file does not exist');
        }
        $path = $this->preparePath($path);

        $statement = $this->pdo->prepare('UPDATE '.$this->table.' SET visibility = ? WHERE bucket = ? AND path = ?');
        $statement->execute([$visibility, $this->bucket, $path]);
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            return $this->getFileAttributes($this->preparePath($path));
        } catch (\Exception) {
            throw new UnableToRetrieveMetadata();
        }
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $attributes = $this->getFileAttributes($this->preparePath($path));
            if ('' == $attributes->mimeType()) {
                throw new UnableToRetrieveMetadata();
            }

            return $attributes;
        } catch (\Exception) {
            throw new UnableToRetrieveMetadata();
        }
    }

    public function lastModified(string $path): FileAttributes
    {
        try {
            return $this->getFileAttributes($this->preparePath($path));
        } catch (\Exception) {
            throw new UnableToRetrieveMetadata();
        }
    }

    public function fileSize(string $path): FileAttributes
    {
        try {
            return $this->getFileAttributes($this->preparePath($path));
        } catch (\Exception) {
            throw new UnableToRetrieveMetadata();
        }
    }

    /**
     * @return \Generator
     *
     * @psalm-return \Generator<int, DirectoryAttributes|FileAttributes, mixed, void>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = \rtrim($this->preparePath($path), '/').'/';
        $prefixLength = strlen($prefix);
        $listedDirectories = [];

        $statement = $this->pdo->prepare('SELECT path FROM '.$this->table.' WHERE bucket = ? AND path LIKE ?');
        $statement->execute([$this->bucket, $prefix.'%']);

        while (
            /** @var array|false $row */
        $row = $statement->fetch(\PDO::FETCH_ASSOC)
        ) {
            $path = (string) $row['path'];
            if (substr($path, 0, $prefixLength) === $prefix) {
                $subPath = substr($path, $prefixLength);
                $dirname = dirname($subPath);

                if ('.' !== $dirname) {
                    $parts = explode('/', $dirname);
                    $dirPath = '';

                    foreach ($parts as $index => $part) {
                        if (false === $deep && $index >= 1) {
                            break;
                        }

                        $dirPath .= $part.'/';

                        if (!in_array($dirPath, $listedDirectories)) {
                            $listedDirectories[] = $dirPath;
                            $directoryAttributes = new DirectoryAttributes(trim($prefix.$dirPath, '/'));
                            yield $directoryAttributes;
                        }
                    }
                }

                $dummyFilename = self::DUMMY_FILE_FOR_FORCED_LISTING_IN_FLYSYSTEM_TEST;
                if (substr($path, -strlen($dummyFilename)) === $dummyFilename) {
                    continue;
                }

                if (true === $deep || false === \strpos($subPath, '/')) {
                    yield $this->getFileAttributes(ltrim($path, '/'));
                }
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $source = $this->preparePath($source);
        $destination = $this->preparePath($destination);

        if (!$this->fileExists($source) || $this->fileExists($destination)) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }

        $contents = $this->read($source);
        $this->write($destination, $contents, $config);
        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $source = $this->preparePath($source);
        $destination = $this->preparePath($destination);

        if (!$this->fileExists($source)) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }

        $contents = $this->read($source);
        $this->write($destination, $contents, $config);
    }

    private function preparePath(string $path): string
    {
        return '/'.ltrim($path, '/');
    }

    private function getCacheFilename(string $path): string
    {
        $cacheKey = sha1($this->bucket.$path);

        return $this->cacheDirectory.'/'.$this->cachePrefix.$cacheKey;
    }

    private function writeToCache(string $path, string $contents): void
    {
        file_put_contents($this->getCacheFilename($path), $contents);
    }

    private function readFromCache(string $path): ?string
    {
        $filename = $this->getCacheFilename($path);
        if (file_exists($filename)) {
            return file_get_contents($filename);
        }

        return null;
    }

    private function cleanupCache(): void
    {
        if (rand(1, $this->cacheCleanupChance) !== $this->cacheCleanupChance) {
            return; // bail out
        }

        $files = new \DirectoryIterator($this->cacheDirectory);
        foreach ($files as $fileinfo) {
            if ($fileinfo->isFile()) {
                if (str_starts_with($fileinfo->getBasename(), $this->cachePrefix)) {
                    if ((time() - $fileinfo->getATime()) > $this->cacheMaxAge) {
                        \Safe\unlink($fileinfo->getPathname());
                    }
                }
            }
        }
    }

    private function getChecksumForPath(string $path): string
    {
        $path = $this->preparePath($path);

        $statement = $this->pdo->prepare('SELECT checksum FROM '.$this->table.' WHERE bucket = ? AND path like ? LIMIT 1');
        $statement->execute([$this->bucket, $path]);

        return (string) $statement->fetch(\PDO::FETCH_COLUMN);
    }

    private function getChecksumForContent(string $content): string
    {
        return sha1($content);
    }

    public function deleteEverything(): void
    {
        $statement = $this->pdo->prepare('DELETE FROM '.$this->table.' WHERE bucket = ?');
        $statement->execute([$this->bucket]);
    }
}
