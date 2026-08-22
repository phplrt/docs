# Position

> This package can be installed separately with `composer require phplrt/position`

An offset is what a parser works with: a number of bytes from the beginning of
the source. A line and a column is what a person works with. This package
converts between the two.

```php
use Phplrt\Position\PositionFactory;
use Phplrt\Source\StringSource;

$source = StringSource::createFromString("first line\nsecond line\nthird line");

$position = new PositionFactory()
    ->createFromOffset($source, 18);

echo $position;         // "2:8"
echo $position->line;   // 2
echo $position->column; // 8
```

## Both Directions

`createFromOffset()` turns a number of bytes into a place a person can find,
and `createOffsetFromPosition()` turns that place back into a number of bytes.

```php
use Phplrt\Position\Position;
use Phplrt\Position\PositionFactory;

$factory = new PositionFactory();

$factory->createFromOffset($source, 18);                 // 2:8
$factory->createOffsetFromPosition($source, new Position(2, 8)); // 18
```

Both are counted from **one**, not from zero: the very beginning of any source
is `1:1`, and the column is counted within its own line rather than from the
beginning of the source.

Neither of them can be pointed out of bounds. An offset past the end of the
source gives the very end of it, a column past the end of its line gives the
end of that line, and a line past the last one gives the end of the source.

```php
$factory->createFromOffset($source, 999);                          // 3:11
$factory->createOffsetFromPosition($source, new Position(2, 999)); // 22
$factory->createOffsetFromPosition($source, new Position(99, 1));  // 33
```

## The Position Itself

`Position` is an immutable pair of numbers that prints itself the way a
compiler does:

```php
use Phplrt\Position\Position;

echo new Position(7, 12); // "7:12"
echo new Position();      // "1:1"
```

A number below one is not a place in a source, so it is refused rather than
corrected:

```php
new Position(0); // InvalidArgumentException
```

## What It Does To The Source

Finding a line means counting the delimiters before it, and that means reading
the source from its beginning. What the source is decides how that ends:

| Source                              | After the call                        |
|-------------------------------------|---------------------------------------|
| one that can be rewound             | left exactly where it was             |
| a pipe or a socket, still untouched | left at the end of what has been read |
| a pipe or a socket, partly read     | `NotRewindableException`              |

```php
$source->read(5);

echo $source->offset; // 5
$factory->createFromOffset($source, 20);
echo $source->offset; // 5 - the cursor has been given back
```

A source that cannot be rewound and has already given a part of its data away
is refused, because the bytes needed to count the lines are simply gone:

```
The source does not support offset (seek/rewind) changes and
has already given away its first 2 bytes
```

## Reading In Chunks

The source is read in chunks rather than all at once, so a grammar of any size
costs the same memory. The size of a chunk is 64 KiB by default:

```php
use Phplrt\Position\PositionFactory;

$factory = new PositionFactory(chunkSize: 8192);

echo PositionFactory::DEFAULT_CHUNK_SIZE; // 65536
```

Nothing beyond the offset being looked for is ever read, so a position near
the beginning of a large file costs one chunk.

## Errors

Everything this package throws implements
`Phplrt\Contracts\Source\Exception\SourceExceptionInterface`, so it is caught
along with the failures of the source it reads.

| Exception                  | Thrown when                                             |
|----------------------------|---------------------------------------------------------|
| `InvalidArgumentException` | a line, a column or a chunk size is below its minimum    |
| `NotRewindableException`   | the source cannot be rewound and has already been read   |

## Bring Your Own

The two contracts live in packages of their own, so code that only calculates
positions does not depend on this implementation:

```php
use Phplrt\Contracts\Position\PositionFactoryInterface;
use Phplrt\Contracts\Source\FileInterface;

function report(PositionFactoryInterface $factory, FileInterface $source, int $offset): string
{
    $position = $factory->createFromOffset($source, $offset);

    return \sprintf('%s:%d:%d', $source->pathname, $position->line, $position->column);
}
```

`PositionInterface` guarantees the one-based counting described above, and
`PositionInterface::MIN_LINE` and `PositionInterface::MIN_COLUMN` are what
"the beginning" is spelled as.
