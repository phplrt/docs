# Source Contracts

This document describes a common interface for reading source code - a file on
disk, a string typed by a user, a stream - together with the interface for
creating such a source out of an arbitrary value.

The goal is to let anything that reads source code depend on the ability to
read rather than on where the data lives. These are the bottom of the stack:
the lexer, parser and position contracts all depend on them, and they depend
on nothing.

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "MAY", and "OPTIONAL" in this document are to be
interpreted as described in [RFC 2119](https://tools.ietf.org/html/rfc2119).

## 1. Specification

### 1.1 Readable

A source is an object implementing `ReadableInterface`. Its data can be read
in any order.

- `$content` is the whole content of the source.
- Repeated readings MUST give the same data back. A source is not a stream
  that is consumed by reading it, so an implementation over data that arrives
  once MUST remember what it has read.
- `read()` returns at most `$bytes` bytes located at `$offset`, counted in
  bytes from the beginning of the source.
- An offset at or beyond the end of the source MUST give an empty string back.
- An offset with less data left after it than has been asked for MUST give
  back everything there is.
- Both MAY throw `SourceExceptionInterface`.

Reading past the end of a source is therefore not an error, and the empty
string is what tells a reader the data is over:

```php
for ($offset = 0;; $offset += \strlen($chunk)) {
    $chunk = $source->read($offset, 4096);

    if ($chunk === '') {
        break;
    }

    // ...
}
```

The length of a source is deliberately not part of this interface: a pipe has
no length until it ends, so the question has an answer only for some sources
and is asked of those alone.

### 1.2 File

A source stored in a physical file is an object implementing `FileInterface`,
which extends `ReadableInterface` with the pathname of that file.

- `$pathname` MUST be a non-empty string.

The distinction exists for error reporting: a snippet of the source under an
error message is worth considerably more with a name above it. Type-hint
`ReadableInterface` to accept any source, and `FileInterface` where the name
is required.

### 1.3 Factory

A source factory is an object implementing `SourceFactoryInterface`.

- The `create()` method takes a value of any kind - a pathname, a string of
  code, a stream resource, an object of the caller's own - and returns a
  source implementing `ReadableInterface`.
- A value that is a source already MUST be given back as it is. This is what
  the conditional return type states, and what makes passing a value through a
  factory idempotent.
- A value that cannot be converted MUST raise `SourceExceptionInterface`.

```php
public function parse(mixed $source): mixed
{
    return $this->parser->parse($this->sources->create($source));
}
```

### 1.4 Errors

Every exception raised while processing the data of a source MUST implement
`SourceExceptionInterface` - a missing file, an unreadable stream, data that
cannot be converted to a string.

It is also the exception the neighbouring specifications declare when the
failure is really a failure of the source:
[position-factory-contracts](/docs/contracts/position) raises it rather than
introducing an exception of its own.

## 2. Package

The interfaces described here are provided as part of two packages:

```bash
composer require phplrt/source-contracts
composer require phplrt/source-factory-contracts
```

| Interface                            | Package                    |
|--------------------------------------|----------------------------|
| `ReadableInterface`                  | `source-contracts`         |
| `FileInterface`                      | `source-contracts`         |
| `Exception\SourceExceptionInterface` | `source-contracts`         |
| `SourceFactoryInterface`             | `source-factory-contracts` |

Both use the `Phplrt\Contracts\Source` namespace; the split is about what a
consumer depends on, not about where the symbols live. Code that only reads
sources has no reason to require the ability to create them.
`phplrt/source-contracts` requires nothing but PHP, and
`phplrt/source-factory-contracts` requires it.

A package implementing them provides the corresponding virtual package:

```bash
composer require phplrt/source-contracts-implementation
composer require phplrt/source-factory-contracts-implementation
```

`phplrt/source` provides both. `FileSource`, `StringSource`, `VirtualSource`
and `ResourceSource` implement `ReadableInterface`; `FileSource` and
`VirtualSource` implement `FileInterface` as well; `SourceFactory` implements
`SourceFactoryInterface`.

Both packages ship a test declaring an anonymous implementation of every
interface in them. Changing that test is allowed only in a **major** release,
so no member can be added to any of these interfaces in a minor version.

## 3. Interfaces

### 3.1 `Phplrt\Contracts\Source\ReadableInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Source;

use Phplrt\Contracts\Source\Exception\SourceExceptionInterface;

/**
 * An arbitrary source code, the data of which can be read in any order.
 */
interface ReadableInterface
{
    /**
     * The whole content of the source.
     *
     * Repeated readings MUST give the same data back.
     */
    public string $content {
        /**
         * @throws SourceExceptionInterface if the data of the source cannot be
         *         read and/or converted to a string
         */
        get;
    }

    /**
     * Reads at most the given number of bytes located at the given offset,
     * counted in bytes from the beginning of the source.
     *
     * An offset at or beyond the end of the source MUST give an empty string
     * back, and an offset with less data left after it than has been asked for
     * MUST give back everything there is.
     *
     * @param int<0, max> $offset the offset in bytes from the beginning of the
     *        source the reading starts at
     * @param int<1, max> $bytes the maximal number of bytes to read
     * @return string the data that has been read
     * @throws SourceExceptionInterface if the data of the source cannot
     *         be read
     */
    public function read(int $offset, int $bytes): string;
}
```

### 3.2 `Phplrt\Contracts\Source\FileInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Source;

/**
 * A source code that is stored in a physical file.
 */
interface FileInterface extends ReadableInterface
{
    /**
     * The physical pathname of the file the source is stored in.
     *
     * @var non-empty-string
     */
    public string $pathname {
        get;
    }
}
```

### 3.3 `Phplrt\Contracts\Source\Exception\SourceExceptionInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Source\Exception;

/**
 * An error that occurs while processing the data of a source.
 *
 * Every exception thrown by a source MUST implement this interface.
 */
interface SourceExceptionInterface extends \Throwable {}
```

### 3.4 `Phplrt\Contracts\Source\SourceFactoryInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Source;

use Phplrt\Contracts\Source\Exception\SourceExceptionInterface;

/**
 * Converts arbitrary values into the source objects.
 */
interface SourceFactoryInterface
{
    /**
     * Creates a source out of the given value.
     *
     * A value that is a source already MUST be given back as is.
     *
     * @template TArgSource
     * @param TArgSource $source the value to create a source out of
     * @return (TArgSource is ReadableInterface ? TArgSource : ReadableInterface)
     * @throws SourceExceptionInterface if a source cannot be created out of
     *         the given value
     */
    public function create(mixed $source): ReadableInterface;
}
```

## 4. See Also

- [Usage](/docs/advanced/source) - the phplrt sources and the factory behind them.
- [Position Contracts](/docs/contracts/position) - naming a place inside a
  source.
