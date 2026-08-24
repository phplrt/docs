# Analysing an Error

> This page is about the data behind the diagnostics. For printing them, see
> [Error Reporting](/docs/errors).

`ErrorPrinter` turns an exception into a picture. `Analyzer` is the half that
works out *what* to draw: it takes any `Throwable` and gives back a
`FailureResult` - a plain representation of the error, telling what it says
about itself, where it happened, in which source and how much of it is at
fault. Nothing of the original exception is kept, so the result travels
wherever the exception itself cannot.

Use it when you want the facts rather than the output - a language server
reporting diagnostics over LSP, a linter collecting errors into JSON, or your
own renderer.

```php
use Phplrt\Exception\Analyzer;

$result = new Analyzer()->analyze($e);

$result->class;    // the class of the exception, or an empty string
$result->message;  // the message of the exception, or an empty string
$result->source;   // Phplrt\Contracts\Source\ReadableInterface
$result->position; // Phplrt\Contracts\Position\PositionInterface
$result->level;    // Phplrt\Exception\Analysis\FailureLevel
$result->interval; // Phplrt\Exception\Analysis\FailureInterval|null
$result->previous; // the same information about $e->getPrevious(), or null
```

Reading the source may fail, so `analyze()` declares
`Phplrt\Contracts\Source\Exception\SourceExceptionInterface`.

## Where It Comes From

An exception implementing the lexer or parser runtime contract knows the
source it was reading and the token it failed on, and that is where the
analysis comes from:

|            | `RuntimeExceptionInterface`                             | any other `Throwable`               |
|------------|---------------------------------------------------------|-------------------------------------|
| `class`    | `$e::class`                                             | `$e::class`                         |
| `message`  | `$e->getMessage()`                                      | `$e->getMessage()`                  |
| `source`   | `$e->source`                                            | a `FileSource` over `$e->getFile()` |
| `position` | the position of the token offset                        | line `$e->getLine()`, column 1      |
| `level`    | the severity of an `ErrorException`, or the default one | the same                            |
| `interval` | the token offset and its size                           | `null`                              |

A parser error may be as large as the whole grammar rule the analysis failed
on rather than as large as one token, and it says so through
`RuntimeExceptionInterface::$length`. An exception implementing both contracts
is read as a parser one, because its own size is the more precise of the two.

An exception thrown outside any file - one restored from a serialized state,
for example - belongs to no source at all and is analysed over an empty one.

## The Fragment

`FailureInterval` is the fragment of the source the error covers, counted in
bytes from the beginning of it:

```php
$result->interval->offset; // the byte the fragment starts at
$result->interval->length; // the size of the fragment, in bytes
$result->interval->endsAt; // $offset + $length
```

It is `null` when the error tells nothing about its own size, which is the
case for every exception outside the contracts. Such an error points at a
position rather than at a fragment, and `SnippetReader` underlines a single
character for it.

## The Chain

Every error that led to the one being analysed is described the same way, from
the outermost exception to the innermost:

```php
use Phplrt\Contracts\Source\FileInterface;

for ($current = $result; $current !== null; $current = $current->previous) {
    \printf(
        "%s at %s:%d:%d\n",
        $current->class,
        $current->source instanceof FileInterface ? $current->source->pathname : '-',
        $current->position->line,
        $current->position->column,
    );
}
```

The chain is walked without recursion, so its length is not a limit.

## Overrides

`FailureResult` is immutable, and `with()` gives a copy with one or
more values replaced:

```php
use Phplrt\Exception\Analysis\FailureInterval;
use Phplrt\Source\VirtualSource;

$result = $result->with(
    source: VirtualSource::createFromString('config.txt', $code),
    interval: new FailureInterval(offset: 26, length: 4),
);
```

This is what `PrintableError::withSource()` and `withInterval()` do underneath
when an ordinary exception has to be pointed at a source of its own.

## Positions

Positions are calculated by `Phplrt\Position\PositionFactory`, which reads the
source in chunks and counts the line delimiters. Pass your own when the
default chunk size does not suit the sources you deal with:

```php
use Phplrt\Exception\Analyzer;
use Phplrt\Position\PositionFactory;

$analyzer = new Analyzer(new PositionFactory(chunkSize: 65536));
```

A position is the line and the column of the *beginning* of the fragment, both
counted from one. See [Position](/docs/position) for the details.
