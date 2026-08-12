# Source

> This package can be installed separately with `composer require phplrt/source`

Everything phplrt reads - a grammar file, an expression typed by a user, a
template - is wrapped in a *source* object. It is a thin thing: it knows how
to give up its content, and it knows what to call itself when an error points
at it.

```php
use Phplrt\Source\FileSource;
use Phplrt\Source\StringSource;

$fromDisk   = new FileSource(__DIR__ . '/example.txt');
$fromString = new StringSource('2 + 2');

echo $fromString->content; // "2 + 2"
```

## Why Not Just A String?

Two reasons.

The first is error messages. A parser that receives a bare string can only
say "syntax error at offset 42". A parser that receives a `FileSource` can
say:

```
 --> /app/config/routes.txt:7:12
```

The second is laziness. A `FileSource` does not read the disk until somebody
asks for its content, and once it has, it remembers - until the file changes
on disk, at which point it reads again.

## The Kinds of Source

Every source is named after what it reads: a `*Source` class is a source, and
a `*Stream` class is the cursor one of them hands out.

| Class                       | Reads                              |
|-----------------------------|------------------------------------|
| `StringSource`              | a string in memory                 |
| `FileSource`                | a real file on disk                |
| `ResourceSource`            | an open resource                   |
| `VirtualStringFileSource`   | a string, under a pathname         |
| `VirtualResourceFileSource` | an open resource, under a pathname |

### FileSource

A real file on disk.

```php
use Phplrt\Source\FileSource;

$source = new FileSource(__DIR__ . '/grammar.pp3');

echo $source->pathname;   // "/app/grammar.pp3"
echo $source->content;    // the contents of the file
echo $source->size;       // size in bytes
echo $source->modifiedAt; // unix timestamp

if ($source->isExists && $source->isReadable) {
    // ...
}
```

### StringSource

A string you already have in memory.

```php
use Phplrt\Source\StringSource;

$source = new StringSource('2 + 2');
```

### VirtualStringFileSource

A string that pretends to be a file. Nothing is read from disk, but errors
can still point at a name - handy for code that came from a database, an
HTTP request, or a test.

```php
use Phplrt\Source\VirtualStringFileSource;

$source = new VirtualStringFileSource('user-input.txt', '2 + 2');

echo $source->pathname; // "user-input.txt"
echo $source->content;  // "2 + 2"
```

### ResourceSource

An open resource.

```php
use Phplrt\Source\ResourceSource;
use Phplrt\Source\VirtualResourceFileSource;

$source = new ResourceSource(\fopen('php://input', 'rb'));

// ...or with a name attached
$named = new VirtualResourceFileSource('request.json', \fopen('php://input', 'rb'));
```

## The Factory

If you do not know in advance what you are given, let the factory decide:

```php
use Phplrt\Source\SourceFactory;

$factory = SourceFactory::createDefault();

$factory->create('2 + 2');                        // StringSource
$factory->create(new \SplFileInfo('/app/x.txt')); // FileSource
$factory->create(\fopen('php://memory', 'rb+'));  // ResourceSource
$factory->create(new StringSource('2 + 2'));      // the very same object back
```

A string is always the source code itself, never a pathname: there is no way
to tell one from the other, so a file is referenced by an `SplFileInfo`.

When you do want to be specific, construct the source yourself - that is what
the constructors are for, and it is the only way to reach the named kinds:

```php
new FileSource('/app/x.txt');
new StringSource('2 + 2');
new VirtualStringFileSource('virtual.txt', '2 + 2');
new ResourceSource($resource);
```

### Drivers

The factory itself knows nothing about the kinds of source; each of them is a
driver, and `create()` hands the argument to every driver in turn until one of
them recognizes it. `SourceFactory::createDefault()` is simply this list:

```php
use Phplrt\Source\Driver;
use Phplrt\Source\SourceFactory;

new SourceFactory([
    new Driver\StringSourceDriver(),      // string       -> StringSource
    new Driver\SplFileInfoSourceDriver(), // \SplFileInfo -> FileSource
    new Driver\StreamSourceDriver(),      // resource     -> ResourceSource
]);
```

An argument that already is a source is returned as it is, whatever the
drivers are - so `create()` is safe to call on a value that may or may not
have been converted yet.

The first driver that recognizes the argument wins, so prepending your own is
how you override a built-in one. A driver returns `null` for what it does not
recognize, and throws for what it recognizes but cannot turn into a source:

```php
use Phplrt\Contracts\Source\ReadableInterface;
use Phplrt\Source\Driver\SourceDriverInterface;
use Phplrt\Source\ResourceSource;
use Psr\Http\Message\StreamInterface;

final class PsrStreamSourceDriver implements SourceDriverInterface
{
    public function tryCreate(mixed $source): ?ReadableInterface
    {
        if (!$source instanceof StreamInterface) {
            return null;
        }

        return new ResourceSource($source->detach());
    }
}
```

When no driver recognizes the argument, `create()` throws a
`NotCreatableException`.

## The Interfaces

Type-hint against these rather than the concrete classes:

```php
use Phplrt\Contracts\Source\FileInterface;
use Phplrt\Contracts\Source\ReadableInterface;

// Anything readable: FileSource, StringSource, ResourceSource...
function parse(ReadableInterface $source): mixed { /* ... */ }

// Only the ones that have a name: FileSource, VirtualStringFileSource...
function report(FileInterface $source): string
{
    return $source->pathname;
}
```

`ReadableInterface` gives you a property and a method:

```php
$source->content;            // string
$source->createStream(); // ReadableStreamInterface
```

Both may throw `SourceExceptionInterface` - a file can disappear between the
moment you name it and the moment you read it.

```php
use Phplrt\Contracts\Source\Exception\SourceExceptionInterface;
use Phplrt\Source\FileSource;

try {
    echo new FileSource('/no/such/file')
        ->content;
} catch (SourceExceptionInterface $e) {
    echo $e->getMessage(); // File "/no/such/file" not found
}
```

### Reading In Chunks

`$content` gives you everything at once, which is fine until the source is
larger than the memory you are willing to spend on it. `createStream()`
gives you a `ReadableStreamInterface` instead: a read-only, forward-only
cursor over the same data.

```php
$stream = $source->createStream();

while (!$stream->isCompleted) {
    $chunk = $stream->read(4096);

    // ...
}
```

`read()` returns up to the number of bytes you asked for, and an empty string
once there is nothing left; `$isCompleted` is what tells you the data is over,
and `$offset` is where you are in it.

There is no way to go back, which is why this is a method rather than a
property: reading moves the cursor, so every call hands you a fresh one and
reading the source a second time means calling it again.

A fresh cursor is not the same as fresh data, though. A source over a resource
gives you a new stream over that same resource, and the resource is only read
once:

```php
$file = new FileSource('/app/x.txt');
$file->createStream()->read(); // reads the file
$file->createStream()->read(); // opens it again, reads it again

$resource = new ResourceSource(\fopen('php://input', 'rb'));
$resource->createStream()->read(); // reads the input
$resource->createStream()->read(); // "" - the resource is already at its end
```

## Bring Your Own

`ReadableInterface` is small on purpose. If your source code lives somewhere
unusual - a zip archive, a remote service, a database row - implement it
yourself and every other phplrt component will accept it:

```php
use Phplrt\Contracts\Source\FileInterface;
use Phplrt\Contracts\Source\ReadableStreamInterface;
use Phplrt\Source\Stream\StringStream;

final class DatabaseSource implements FileInterface
{
    public function __construct(
        public readonly string $pathname,
        private readonly \PDO $pdo,
        private readonly int $id,
    ) {}

    public string $content {
        get => $this->pdo
            ->query("SELECT body FROM templates WHERE id = {$this->id}")
            ->fetchColumn();
    }

    public function createStream(): ReadableStreamInterface
    {
        return new StringStream($this->content);
    }
}
```

`StringStream` and `ResourceStream` cover the two cases you are likely to
need - data you already hold as a string, and data behind an open resource -
so implementing `ReadableStreamInterface` yourself is only worth it when the data
arrives in chunks of its own, such as a paged HTTP response.
