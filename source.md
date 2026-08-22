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

The second is laziness. A `FileSource` does not read the disk until somebody
asks for its content, and once it has, it remembers - until the file changes
on disk, at which point it reads again.

## The Kinds of Source

Every source is named after what it reads: a `*Source` class in
`Phplrt\Source` is a source, and a `*Stream` class in `Phplrt\Source\Stream`
is the cursor one of them hands out.

| Class               | Reads                              |
|---------------------|------------------------------------|
| `StringSource`      | a string in memory                 |
| `FileSource`        | a real file on disk                |
| `ResourceSource`    | an open resource                   |
| `VirtualSource` | another source, under a pathname   |

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

`ReadableInterface` gives you the data of the source and a cursor over it:

```php
$source->content;    // string   - what is left from where the cursor is
$source->size;       // int|null - null when it cannot be known in advance
$source->offset;     // int      - where the next read starts
$source->isSeekable; // bool     - whether the cursor can be moved at will
$source->isEof;      // bool     - whether there is nothing left to read
$source->read(4096); // string
```

`$size` is there so that nobody has to read a source out just to find out how
long it is: a file answers with `filesize()`, a string with `strlen()`, and a
source over a pipe answers `null`, because a pipe has no size until it ends.

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
larger than the memory you are willing to spend on it. Every source is also a
cursor over its own data:

```php
while (!$source->isEof) {
    $chunk = $source->read(4096);

    // ...
}
```

`read()` returns up to the number of bytes you asked for, and an empty string
once there is nothing left; `$isEof` is what tells you the data is over, and
`$offset` is where you are in it. Unlike `feof()`, `$isEof` is true as soon as
there is nothing more to read, so the loop above never spins an extra time.

`$content` is what is left of the source from where the cursor is, and taking
it does not move the cursor:

```php
$source = StringSource::createFromString('2 + 2');

$source->read(2); // '2 '
$source->content; // '+ 2'
$source->content; // '+ 2' again
$source->offset;  // 2
```

### Going Back

`$offset` can be written to as well as read, which moves the cursor to an
arbitrary position:

```php
$source->offset = 42;

echo $source->read(8); // the eight bytes at offset 42
```

A string and a file can always be moved; a pipe, a socket and `php://input`
cannot, and answer `false` to `$isSeekable`:

```php
if ($source->isSeekable) {
    $source->offset = 0;
}
```

Moving a source that answers `false` throws `LogicException`. Whether a source
can be moved depends on what it is built over rather than on its class, which
is why this is a property and not a separate interface.

A source over a resource you cannot rewind is readable **once**: a second
`$content` read throws instead of quietly handing you what is left.

```php
$input = ResourceSource::createFromResource(\fopen('php://input', 'rb'));

$input->content; // reads the input
$input->content; // NotReadableException
```

### Who Closes The Resource

A `ResourceSource` never closes a resource it did not open - the one who
opened it is the one to close it. Pass `autoclose: true` when you would rather
hand that duty over:

```php
$owned = ResourceSource::createFromResource(\fopen('/app/x.txt', 'rb'), autoclose: true);

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

The pathname is the only thing a `VirtualSource` adds; reading, seeking and
the size all come from the source it wraps, so a string, an open resource or a
real file all work as the thing underneath.

Implementing `ReadableInterface` by hand is only worth it when the data
arrives in chunks of its own, such as a paged HTTP response - then the cursor
is yours to drive, and `$offset`, `$isEof` and `read()` are what the rest of
phplrt will call.
