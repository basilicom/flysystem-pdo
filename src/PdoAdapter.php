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

    /** @var \PDO */
    private $pdo;
    private string $table = 'files';
    private MimeTypeDetector $mimeTypeDetector;

    public function __construct(
        \PDO $pdo,
        private string $defaultVisibility = Visibility::PUBLIC,
        MimeTypeDetector $mimeTypeDetector = null
    ) {
        $this->pdo = $pdo;
        $this->mimeTypeDetector = $mimeTypeDetector ?: new FinfoMimeTypeDetector();
    }

    private function getFileAttributes(string $path): FileAttributes
    {
        $path = $this->preparePath($path);
        $statement = $this->pdo->prepare(
            'SELECT `size`,visibility,UNIX_TIMESTAMP(lastModified) as lastModified,mimeType 
                FROM files WHERE path = ?'
        );
        $statement->execute([$path]);
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
        $statement = $this->pdo->prepare('SELECT * FROM files WHERE path = ?');
        $statement->execute([$this->preparePath($path)]);
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
        $statement = $this->pdo->prepare('REPLACE INTO files(path, contents, mimeType, `size`, visibility, lastModified, checksum) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute(
            [
                $path,
                $contents,
                $mimeType,
                strlen($contents),
                $visibility,
                date('Y-m-d H:i:s', $timestamp),
                sha1($contents),
            ]
        );
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, (string) stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        $path = $this->preparePath($path);

        $statement = $this->pdo->prepare('SELECT contents FROM files WHERE path = ?');
        $statement->execute([$path]);
        $cnt = $statement->rowCount();
        if (1 !== $cnt) {
            throw UnableToReadFile::fromLocation($path, 'file does not exist');
        }
        /** @var array $row */
        $row = $statement->fetch();

        return (string) $row['contents'];
    }

    public function readStream(string $path)
    {
        $contents = $this->read($path);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        $path = $this->preparePath($path);
        $statement = $this->pdo->prepare('DELETE FROM files where path = ?');
        $statement->execute([$this->preparePath($path)]);
    }

    public function deleteDirectory(string $path): void
    {
        $path = $this->preparePath($path);
        $path = \rtrim($path, '/').'/';

        $statement = $this->pdo->prepare('DELETE FROM files where path like ?');
        $statement->execute([$path.'%']);
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

        $statement = $this->pdo->prepare('SELECT * FROM files WHERE path like ?');
        $statement->execute([$prefix.'%']);

        return 1 == $statement->rowCount();
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if (!$this->fileExists($path)) {
            throw UnableToSetVisibility::atLocation($path, 'file does not exist');
        }
        $path = $this->preparePath($path);

        $statement = $this->pdo->prepare('UPDATE files SET visibility = ? WHERE path = ?');
        $statement->execute([$visibility, $path]);
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            return $this->getFileAttributes($this->preparePath($path));
        } catch (\Exception) {
            throw new UnableToRetrieveMetadata();
        }
        /*
        $path = $this->preparePath($path);

        if (array_key_exists($path, $this->files) === false) {
            throw UnableToRetrieveMetadata::visibility($path, 'file does not exist');
        }
        */
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

        $statement = $this->pdo->prepare('SELECT * FROM files WHERE path LIKE ?');
        $statement->execute([$prefix.'%']);

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

    public function deleteEverything(): void
    {
        $statement = $this->pdo->prepare('DELETE FROM files');
        $statement->execute([]);
    }
}
