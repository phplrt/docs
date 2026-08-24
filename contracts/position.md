# Position Contracts

This document describes a common interface for a human-readable location
inside a source - a line and a column - together with the interface for
converting between such a location and an offset in bytes.

The goal is to let anything that talks about places in a source - an error
reporter, an IDE plugin, a linter, a diff tool - speak of them in the same
terms.

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "MAY", and "OPTIONAL" in this document are to be
interpreted as described in [RFC 2119](https://tools.ietf.org/html/rfc2119).

## 1. Specification

### 1.1 Position

A position is an object implementing `PositionInterface`.

- `$line` MUST be counted from the beginning of the source, starting at
  `PositionInterface::MIN_LINE`.
- `$column` MUST be counted from the beginning of its own line, starting at
  `PositionInterface::MIN_COLUMN`.
- Both minimums are `1`: the counting is one-based, not zero-based.
- An implementation MUST be immutable.

Code that clamps or compares positions SHOULD use the constants rather than a
literal `1`:

```php
$line = \max(PositionInterface::MIN_LINE, $line - $context);
```

### 1.2 Factory

A position factory is an object implementing `PositionFactoryInterface`. It
converts in both directions, and both directions need the source, since the
answer is a property of the bytes in it.

- `createFromOffset()` returns the position of the given offset, counted in
  bytes from the beginning of the given source.
- An offset beyond the end of the source MUST be corrected to the end of it.
- `createOffsetFromPosition()` returns the offset the given position points
  at.
- A position beyond the end of its own line MUST be corrected to the end of
  that line, and a position beyond the end of the source MUST be corrected to
  the end of the source.
- Both MAY raise `SourceExceptionInterface` when the data of the source cannot
  be read.

Neither method fails on an argument that is out of range - both clamp. That is
what makes the pair safe to use on numbers that have been arrived at by
arithmetic, which is what an error reporter drawing a caret under a token
does:

```php
use Phplrt\Contracts\Position\PositionFactoryInterface;
use Phplrt\Contracts\Source\FileInterface;

function report(PositionFactoryInterface $factory, FileInterface $source, int $offset): string
{
    $position = $factory->createFromOffset($source, $offset);

    return \sprintf('%s:%d:%d', $source->pathname, $position->line, $position->column);
}
```

The factory raises `SourceExceptionInterface` rather than an exception of its
own, so locating something inside a source fails the same way reading it does.

## 2. Package

The interfaces described here are provided as part of two packages:

```bash
composer require phplrt/position-contracts
composer require phplrt/position-factory-contracts
```

| Interface                  | Package                      |
|----------------------------|------------------------------|
| `PositionInterface`        | `position-contracts`         |
| `PositionFactoryInterface` | `position-factory-contracts` |

Both use the `Phplrt\Contracts\Position` namespace; the split is about what a
consumer depends on. `phplrt/position-contracts` requires nothing but PHP - a
line and a column mean nothing about where they came from - while
`phplrt/position-factory-contracts` requires it along with
[phplrt/source-contracts](/docs/contracts/source), since counting lines means
reading a source.

A package implementing them provides the corresponding virtual package:

```bash
composer require phplrt/position-contracts-implementation
composer require phplrt/position-factory-contracts-implementation
```

`phplrt/position` provides both, as `Phplrt\Position\Position` and
`Phplrt\Position\PositionFactory`.

Both packages ship a test declaring an anonymous implementation of every
interface in them. Changing that test is allowed only in a **major** release,
so no member can be added to either interface in a minor version.

## 3. Interfaces

### 3.1 `Phplrt\Contracts\Position\PositionInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Position;

/**
 * A human-readable location inside a source.
 *
 * The line of a position MUST be counted from the beginning of the source and
 * the column MUST be counted from the beginning of its own line, both starting
 * at one.
 *
 * An implementation MUST be immutable.
 *
 * @readonly
 */
interface PositionInterface
{
    /**
     * The minimal line number a position is allowed to have.
     *
     * @var int<1, max>
     */
    public const int MIN_LINE = 1;

    /**
     * The minimal column number a position is allowed to have.
     *
     * @var int<1, max>
     */
    public const int MIN_COLUMN = 1;

    /**
     * The number of the source line the position points at.
     *
     * @var int<1, max>
     */
    public int $line {
        get;
    }

    /**
     * The number of the column within its own line the position points at.
     *
     * @var int<1, max>
     */
    public int $column {
        get;
    }
}
```

### 3.2 `Phplrt\Contracts\Position\PositionFactoryInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Position;

use Phplrt\Contracts\Source\Exception\SourceExceptionInterface;
use Phplrt\Contracts\Source\ReadableInterface;

/**
 * Converts the offsets inside a source into the positions and back.
 */
interface PositionFactoryInterface
{
    /**
     * Creates the position of the given offset inside the given source.
     *
     * An offset beyond the end of the source MUST be corrected to the end
     * of it.
     *
     * @param int<0, max> $offset the offset in bytes from the beginning of
     *        the source
     * @return PositionInterface the position of the given offset
     * @throws SourceExceptionInterface if the data of the given source cannot
     *         be read
     */
    public function createFromOffset(ReadableInterface $source, int $offset): PositionInterface;

    /**
     * Creates the offset in bytes from the beginning of the given source the
     * given position points at.
     *
     * A position beyond the end of its own line MUST be corrected to the end
     * of that line, and a position beyond the end of the source MUST be
     * corrected to the end of the source.
     *
     * @return int<0, max> the offset the given position points at
     * @throws SourceExceptionInterface if the data of the given source cannot
     *         be read
     */
    public function createOffsetFromPosition(ReadableInterface $source, PositionInterface $position): int;
}
```

## 4. See Also

- [Usage](/docs/advanced/position) - the phplrt implementation, and what it does about
  a large source.
- [Source Contracts](/docs/contracts/source) - what a position is calculated
  over.
