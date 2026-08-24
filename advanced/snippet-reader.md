# Reading a Snippet

> This page is about the source code lines behind the diagnostics. For
> printing them, see [Error Reporting](/docs/basics/errors).

`SnippetReader` is what a renderer reads the source code with. It takes the
[analysis of an error](/docs/advanced/analyzer) and reads the lines of the source
code around the fragment it covers, telling which part of which line is at
fault.

Use it when you want the lines rather than the picture - your own renderer, a
web page highlighting the error, or an editor jumping to it.

```php
use Phplrt\Exception\Analyzer;
use Phplrt\Exception\SnippetReader;

$result = new Analyzer()->analyze($e);
$lines = new SnippetReader()->read($result, lines: 1);
```

The result is indexed by the line numbers, so the keys are as meaningful as
the values:

```php
foreach ($lines as $number => $line) {
    \printf("%d @%d %s\n", $number, $line->offset, $line->value);
}
```

```
1 @0 name = "phplrt"
2 @16 version = four
3 @31 debug = true
```

Reading the source may fail, so both methods declare
`Phplrt\Contracts\Source\Exception\SourceExceptionInterface`.

## The Lines

Every line is a `SourceLine`:

```php
$line->number; // the line number, starting from 1
$line->offset; // the byte the line starts at, counted from the beginning of the source
$line->value;  // the line without its trailing delimiter
```

A line holding a part of the fragment is a `CapturedSourceLine`, which adds
where exactly that part is:

```php
use Phplrt\Exception\Snippet\CapturedSourceLine;

foreach ($lines as $line) {
    if (!$line instanceof CapturedSourceLine) {
        continue;
    }

    $line->captured->offset; // the byte of the line the fragment starts at
    $line->captured->length; // the number of bytes captured on this line
    $line->captured->endsAt; // $offset + $length

    // The same fragment as an offset inside the whole source
    $line->offset + $line->captured->offset;
}
```

Both are counted in **bytes from the beginning of the line** and start at
zero, so they go straight into `substr()`:

```php
\substr($line->value, $line->captured->offset, $line->captured->length);
```

Note that a fragment spanning several lines passes *through* the ones in the
middle, and a line it only passes through captures nothing of its own -
`length` is zero. That is how a multi-line fragment is told from one pointing
at a single position.

```php
foreach ($lines as $line) {
    \printf(
        "%d %s %s\n",
        $line->number,
        $line instanceof CapturedSourceLine
            ? \sprintf('[%d..%d]', $line->captured->offset, $line->captured->endsAt)
            : '       ',
        $line->value,
    );
}
```

```
1         name = "phplrt"
2 [10..14] version = four
3         debug = true
```

## Lines Around

The second argument is the number of lines read before and after the fragment,
two by default:

```php
use Phplrt\Exception\SnippetReader;

$reader->read($result);            // SnippetReader::DEFAULT_LINES_AROUND
$reader->read($result, lines: 0);  // only the lines the fragment is on
$reader->read($result, lines: 10);
```

Lines that do not exist - before the beginning or after the end of the source
- are simply not there, so asking for ten around the first line gives fewer
than twenty-one.

## Any Fragment

An error is not the only thing worth showing. A `FailureResult` describes any
fragment of any source, with no exception involved:

```php
use Phplrt\Exception\Analysis\FailureInterval;
use Phplrt\Exception\Analysis\FailureResult;
use Phplrt\Exception\SnippetReader;
use Phplrt\Position\Position;
use Phplrt\Source\FileSource;

$lines = new SnippetReader()->read(new FailureResult(
    class: '',
    message: '',
    source: FileSource::createFromPathname('config.txt'),
    position: new Position(),
    interval: new FailureInterval(offset: 26, length: 4),
), lines: 2);
```

An error covering no fragment of its own - an ordinary exception, which knows
only the line it was thrown on, so its position starts at the first column -
captures that whole line. A position pointing at a column of its own tells
where exactly the error is, and captures that place alone, as does a fragment
of a zero length.

## Large Sources

The source is read in chunks rather than all at once, so a snippet out of a
file of any size costs the same. The default chunk is 8 KiB:

```php
use Phplrt\Exception\SnippetReader;
use Phplrt\Position\PositionFactory;

$reader = new SnippetReader(
    positions: new PositionFactory(),
    chunkSize: SnippetReader::DEFAULT_CHUNK_SIZE,
);
```

Lines are separated by `"\n"`, and the `"\r"` of a `"\r\n"` delimiter belongs
to the delimiter rather than to the line. The data left after the last
delimiter is the line the source ends with, so a source ending with a
delimiter has an empty last line.

The same reader is the one `RustStyleRenderer` reads with, so a renderer of
your own configures it the way it needs:

```php
use Phplrt\Exception\ErrorPrinter;
use Phplrt\Exception\Printer\Renderer\RawRustStyleRenderer;
use Phplrt\Exception\SnippetReader;

$printer = new ErrorPrinter(new RawRustStyleRenderer(
    new SnippetReader(chunkSize: 65536),
));
```
