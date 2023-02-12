# Flysystem v3 PDO adapter

A (very simple) [Flysystem][1] v3 adapter for PDO/MySQL`.
Based on the https://github.com/thephpleague/flysystem-memory implementation.

## Installation

```bash
$ composer require basilicom/flysystem-pdo`
```

Prepare a (MySQL) table:
```
create table files (
	path varchar(255) not null,
	isFile tinyint not null default 1,
	mimeType varchar(64) not null default '',
	contents blob not null,
	size int unsigned not null default 0,
	checksum varchar(256) not null,
	lastModified datetime,
	visibility varchar(64),
	PRIMARY KEY(path)
);
```

## Usage

```php
use League\Flysystem\Filesystem;
use Basilicom\Flysystem\Pdo\PdoAdapter;

$pdo = new PDO('mysql:host=mysql;dbname=mydb', 'myuser', 'mypass');
$adapter = new \Basilicom\Fly\PdoFilesystemAdapter($pdo);

$flysystem = new Filesystem($adapter);
```

#### Example

```php
$path = 'my/path/to/file.txt';
$contents = 'Lorem Ipsum';
$flysystem->write($path, $contents);
```

## Tests

This library uses the `FilesystemAdapterTestCase` provided by
[`league/flysystem-adapter-test-utilities`][2], so it performs integration tests
that need a real PDO connection.

To run tests, provide a MySQL database with the `files` table schema,
duplicate the `phpunit.xml.dist` file into `phpunit.xml` and fill
all the environment variables, then run:

```bash
$ composer test
```

This will run PHP-CS-Fixer,[Psalm][3] and [PHPUnit][4], but you can run them individually
like this:

```bash
$ composer phpcsfixer
$ composer psalm
$ composer phpunit
```

[1]: https://flysystem.thephpleague.com
[2]: https://github.com/thephpleague/flysystem-adapter-test-utilities
[6]: https://psalm.dev
[7]: https://phpunit.de

