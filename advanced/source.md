# Source

> This package can be installed separately with `composer require phplrt/source`

Everything phplrt reads - a grammar file, an expression typed by a user, a
template - is wrapped in a *source* object. It is a thin thing: it knows how
to give up its content, and it knows what to call itself when an error points
at it.

```php
use Phplrt\Source\FileSource;
use Phplrt\Source\StringSource;

$fromDisk   = FileSource::createFromPathname(__DIR__ . '/example.txt');
$fromString = StringSource::createFromString('2 + 2');

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

The second is laziness. A `FileSource` does not touch the disk until somebody
asks it to, and the file it opens then belongs to it until the source itself
is gone.

## The Kinds of Source

Every source is named after what it reads, and every one of them can be read
in any order and any number of times.

| Class            | Reads                            |
|------------------|----------------------------------|
| `StringSource`   | a string in memory               |
| `FileSource`     | a real file on disk              |
| `ResourceSource` | an open resource                 |
| `VirtualSource`  | another source, under a pathname |

### FileSource

A real file on disk.

```php
use Phplrt\Source\FileSource;

$source = FileSource::createFromPathname(__DIR__ . '/grammar.pp3');

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

$source = StringSource::createFromString('2 + 2');
```

### VirtualSource

Any other source, pretending to be a file. Nothing is read from disk, but
errors can still point at a name - handy for code that came from a database,
an HTTP request, or a test.

```php
use Phplrt\Source\StringSource;
use Phplrt\Source\VirtualSource;

$source = VirtualSource::createFromString('user-input.txt', '2 + 2');

echo $source->pathname; // "user-input.txt"
echo $source->content;  // "2 + 2"
```

The pathname is the only thing it adds - everything read comes from the source
it wraps, whatever kind that is. A name over a resource is the same class:

```php
$named = VirtualSource::createFromResourceStream('request.json', \fopen('php://input', 'rb'));
```

There is one for a real file as well, which is how a grammar read from disk
gets reported under the name it was included as:

```php
$named = VirtualSource::createFromPathname('/app/grammar.pp3');
```

### ResourceSource

An open resource.

```php
use Phplrt\Source\ResourceSource;

$source = ResourceSource::createFromResource(\fopen('php://input', 'rb'));

echo $source->isSeekable; // whether the resource can be rewound
echo $source->uri;        // "php://input", or null for a resource without one
echo $source->mode;       // "rb"
```

The resource has to be open for reading: one opened for writing alone is
rejected right away rather than at the moment somebody tries to read it.

## The Factory

If you do not know in advance what you are given, let the factory decide:

```php
use Phplrt\Source\SourceFactory;

$factory = SourceFactory::createDefault();

$factory->create('2 + 2');                        // StringSource
$factory->create(new \SplFileInfo('/app/x.txt')); // FileSource
$factory->create(\fopen('php://memory', 'rb+'));  // ResourceSource
$factory->create(StringSource::createFromString('2 + 2'));      // the very same object back
```

A string is always the source code itself, never a pathname: there is no way
to tell one from the other, so a file is referenced by an `SplFileInfo`.

When you do want to be specific, construct the source yourself - that is what
the constructors are for, and it is the only way to reach the named kinds:

```php
FileSource::createFromPathname('/app/x.txt');
StringSource::createFromString('2 + 2');
VirtualSource::createFromString('virtual.txt', '2 + 2');
ResourceSource::createFromResource($resource);
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
    new Driver\ResourceSourceDriver(),    // resource     -> ResourceSource
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

        return ResourceSource::createFromResource($source->detach());
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

// Only the ones that have a name: FileSource, VirtualSource...
function report(FileInterface $source): string
{
    return $source->pathname;
}
```

`ReadableInterface` is the whole of the source and any fragment of it:

```php
$source->content;      // string - the whole source
$source->read(0, 4096) // string - at most 4096 bytes located at offset 0
```

The length of a source is not among them. A pipe has no length until it ends,
so only some of the sources can answer that question, and it is asked of those
alone - `FileSource::$size` answers it with `filesize()`.

All of them may throw `SourceExceptionInterface` - a file can disappear between
the moment you name it and the moment you read it.

```php
use Phplrt\Contracts\Source\Exception\SourceExceptionInterface;
use Phplrt\Source\FileSource;

try {
    echo FileSource::createFromPathname('/no/such/file')
        ->content;
} catch (SourceExceptionInterface $e) {
    echo $e->getMessage(); // File "/no/such/file" not found
}
```

### Reading In Chunks

`$content` gives you everything at once, which is fine until the source is
larger than the memory you are willing to spend on it. `read()` takes the
fragment you name and nothing else:

```php
for ($offset = 0;; $offset += \strlen($chunk)) {
    $chunk = $source->read($offset, 4096);

    if ($chunk === '') {
        break;
    }

    // ...
}
```

`read()` returns up to the number of bytes you asked for and an empty string
once the offset is at the end of the source, which is what tells you the data
is over.

### Reading Twice

Reading a source leaves it as it is, so any fragment can be taken out of it
again, in any order:

```php
$source = StringSource::createFromString('2 + 2');

$source->read(0, 2); // '2 '
$source->read(2, 3); // '+ 2'
$source->read(0, 2); // '2 ' again
$source->content;    // '2 + 2' - the whole of it, whatever has been read
```

This holds for a pipe, a socket and `php://input` as well, none of which can
be rewound: such a source keeps everything it has given away, so the memory it
costs is the memory of the data that has been read out of it.

```php
$input = ResourceSource::createFromResource(\fopen('php://input', 'rb'));

$input->content; // reads the input
$input->content; // the very same data
```

A source built over a resource begins where the resource has been left at the
moment it has been given away, so a resource that has already been read in
part is the source of what is left in it:

```php
$stream = \fopen('/app/x.txt', 'rb'); // "2 + 2"
\fseek($stream, 2);

$source = ResourceSource::createFromResource($stream);

$source->content;    // '+ 2'
$source->read(0, 1); // '+'
```

### Who Closes The Resource

A `ResourceSource` never closes a resource it did not open - the one who
opened it is the one to close it. Construct it with `autoclose: true` when you
would rather hand that duty over:

```php
$owned = new ResourceSource(\fopen('/app/x.txt', 'rb'), autoclose: true);

unset($owned); // the file is closed here
```

A `FileSource` opens a file of its own the first time it is read and closes it
along with itself, so a file is held only for as long as the source that names
it is alive.

## Bring Your Own

If your source code lives somewhere unusual - a zip archive, a remote service,
a database row - name it with a `VirtualSource` over the data you already hold,
and every other phplrt component will accept it:

```php
use Phplrt\Source\VirtualSource;

final class TemplateSources
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public function createFromId(int $id): VirtualSource
    {
        $body = $this->pdo
            ->query("SELECT body FROM templates WHERE id = {$id}")
            ->fetchColumn();

        return VirtualSource::createFromString("template://{$id}", $body);
    }
}
```

The pathname is the only thing a `VirtualSource` adds; everything read comes
from the source it wraps, so a string, an open resource or a real file all
work as the thing underneath.

Implementing `ReadableInterface` by hand is only worth it when the data
arrives in chunks of its own, such as a paged HTTP response - `$content` and
`read()` are what the rest of phplrt will call, and holding on to the pages
that have already arrived is what makes them answerable twice.

The two members are all there is to it, and they live in a package of their
own - see [Contracts](/docs/contracts/source) for what an implementation has
to promise and for the factory contract next to it.
