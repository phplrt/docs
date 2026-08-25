# Lexer Contracts

This document describes a common interface for lexical analysis - turning a
source into the tokens it consists of - together with the interfaces for the
tokens themselves, for the channels those tokens are marked with, and for the
errors raised along the way.

The goal is to let anything that consumes tokens - a parser, a syntax
highlighter, a linter, a formatter - depend on the ability to read them rather
than on a particular lexer.

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "MAY", and "OPTIONAL" in this document are to be
interpreted as described in [RFC 2119](https://tools.ietf.org/html/rfc2119).

## 1. Specification

### 1.1 Lexer

A lexer is an object implementing `LexerInterface`.

- The `lex()` method takes a source implementing `ReadableInterface` and
  returns an `iterable` of objects implementing `TokenInterface`.
- The returned value MAY be of any iterable kind, an array and a generator
  among them.
- The `$offset` argument is counted in bytes from the beginning of the source,
  and the analysis MUST begin there rather than at the beginning of the
  source. It defaults to `0`.
- An implementation MUST NOT change its own state during the analysis, so that
  one lexer can be used concurrently.

```php
use Phplrt\Contracts\Lexer\LexerInterface;
use Phplrt\Contracts\Source\ReadableInterface;

function highlight(LexerInterface $lexer, ReadableInterface $source): string
{
    foreach ($lexer->lex($source) as $token) {
        // ...
    }
}
```

### 1.2 Tokens

A token is an object implementing `TokenInterface`. It is the smallest
meaningful unit a source consists of.

- An implementation MUST be immutable.
- `$offset` is counted in bytes from the beginning of the source, starting at
  `TokenInterface::MIN_OFFSET`, and MUST point at the **start** of the token.
- `$size` MUST be the size of the source fragment the token has been read
  from, so that the position right after the token is `$offset + $size`.
- `$value` MUST be the exact source fragment matched, without normalization or
  transformation of any kind. Any such conversion SHOULD be performed during
  the syntax analysis.
- `$id` identifies the token type. A significant token SHOULD be identified by
  a number greater than or equal to zero, and a system one - the end of input,
  an error - by a negative number.
- `$name` is the human-readable name of the token type, and is `null` for a
  token type that has none.
- A token is `Stringable`.

`$size` is not the length of `$value`. An ordinary token is as large as its
own value, while a token that read its fragment through a lexer of its own is
as large as *that* lexer consumed, however short the value it produced.

### 1.3 Channels

A channel is a tag a token or a group of tokens is marked with, so that these
tokens can be told apart from the rest of a stream. A channel is an object
implementing `ChannelInterface`, whose `$name` MUST be a non-empty string.

The `Channel` enum is the basic set of channels:

| Case         | Meaning                                                    |
|--------------|------------------------------------------------------------|
| `Default`    | A significant token                                        |
| `Hidden`     | A token that MUST be ignored                               |
| `Unknown`    | A fragment that has not been recognized                    |
| `EndOfInput` | The terminal token                                         |

- A stream MUST contain at most one token of the `EndOfInput` channel.
- An implementation MAY mark a token with a channel of its own, which MUST NOT
  be a member of the basic set. `UserDefinedChannel` is such a channel.

### 1.4 Errors

- Every exception thrown by a lexer MUST implement
  `LexerExceptionInterface`.
- An error that occurs **before** the analysis begins - a source that cannot
  be recognized, settings that contain errors - is a plain
  `LexerExceptionInterface`.
- An error that occurs **after** the analysis has begun, and therefore
  indicates a problem in the analyzed source, MUST implement
  `RuntimeExceptionInterface`, which carries the source it occurred in and the
  token it occurred on.

## 2. Package

The interfaces described here are provided as part of the
[phplrt/lexer-contracts](https://github.com/phplrt/lexer-contracts) package:

```bash
composer require phplrt/lexer-contracts
```

It contains no lexer, and requires nothing but PHP and
[phplrt/source-contracts](/docs/contracts/source).

A package implementing these interfaces provides the virtual package
`phplrt/lexer-contracts-implementation`, so an application can ask for an
implementation without naming one:

```bash
composer require phplrt/lexer-contracts-implementation
```

`phplrt/lexer` provides it: `Phplrt\Lexer\Lexer` implements `LexerInterface`
and `Phplrt\Lexer\Token\Token` implements `TokenInterface`.

The package ships a test declaring an anonymous implementation of every
interface in it. Changing that test is allowed only in a **major** release, so
no member can be added to any of these interfaces in a minor version.

## 3. Interfaces

### 3.1 `Phplrt\Contracts\Lexer\LexerInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Lexer;

use Phplrt\Contracts\Lexer\Exception\LexerExceptionInterface;
use Phplrt\Contracts\Lexer\Exception\RuntimeExceptionInterface;
use Phplrt\Contracts\Source\ReadableInterface;

/**
 * Converts a source into the tokens it consists of.
 */
interface LexerInterface
{
    /**
     * Performs lexical analysis of the given source and returns its tokens.
     *
     * An implementation MUST NOT change its own state during the analysis, so
     * that the same lexer can be used in asynchronous and parallel computing.
     *
     * @param int<0, max> $offset the offset in bytes from the beginning of the
     *        source the analysis starts at
     * @return iterable<array-key, TokenInterface> the analyzed tokens
     * @throws LexerExceptionInterface if the given source cannot be recognized
     *         or the lexer settings contain errors
     * @throws RuntimeExceptionInterface if the analyzed source contains errors
     */
    public function lex(ReadableInterface $source, int $offset = 0): iterable;
}
```

### 3.2 `Phplrt\Contracts\Lexer\TokenInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Lexer;

/**
 * A single lexical token, which is the smallest meaningful unit a source
 * consists of.
 *
 * The offset of a token MUST be counted in bytes from the beginning of the
 * source, starting at zero, and its size MUST be that of the source fragment
 * the token has been read from, so that the position right after the token is
 * the sum of the two.
 *
 * An implementation MUST be immutable.
 *
 * @readonly
 */
interface TokenInterface extends \Stringable
{
    /**
     * The minimal offset a token is allowed to have.
     *
     * @var int<0, max>
     */
    public const int MIN_OFFSET = 0;

    /**
     * The identifier of the token type, by which the kinds of the tokens are
     * told apart from one another.
     *
     * A significant token SHOULD be identified by a number greater than or
     * equal to zero, while a system one, like the end of input or an error,
     * SHOULD be identified by a negative number.
     */
    public int $id {
        get;
    }

    /**
     * The human-readable name of the token type, or {@see null} in case the
     * token type has no name of its own.
     *
     * @var non-empty-string|null
     */
    public ?string $name {
        get;
    }

    /**
     * The channel the token belongs to.
     */
    public ChannelInterface $channel {
        get;
    }

    /**
     * The offset in bytes from the beginning of the source the token
     * starts at.
     *
     * @var int<0, max>
     */
    public int $offset {
        get;
    }

    /**
     * The size of the source fragment the token has been read from, in bytes.
     *
     * An ordinary token is as large as its own value, while a token reading a
     * fragment using a lexer of its own is as large as that lexer has read, no
     * matter how short its own value is.
     *
     * @var int<0, max>
     */
    public int $size {
        get;
    }

    /**
     * The exact source fragment matched by the lexer.
     *
     * The value MUST be the original fragment, without any normalization or
     * transformation, which SHOULD be performed during the syntax analysis.
     */
    public string $value {
        get;
    }
}
```

### 3.3 `Phplrt\Contracts\Lexer\ChannelInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Lexer;

/**
 * A tag a token or a group of tokens is marked with, so that these tokens can
 * be told apart from the rest of a stream.
 */
interface ChannelInterface
{
    /**
     * The name of the channel.
     *
     * @var non-empty-string
     */
    public string $name {
        get;
    }
}
```

### 3.4 `Phplrt\Contracts\Lexer\Channel`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Lexer;

/**
 * The basic set of the token channels.
 *
 * An implementation MAY mark a token with a channel of its own, which MUST
 * NOT be a member of this set.
 */
enum Channel implements ChannelInterface
{
    /**
     * The channel of the significant tokens.
     */
    case Default;

    /**
     * The channel of the tokens that MUST be ignored.
     */
    case Hidden;

    /**
     * The channel of the tokens that have not been recognized.
     */
    case Unknown;

    /**
     * The channel of the terminal token.
     *
     * A stream MUST contain at most one token of this channel.
     */
    case EndOfInput;

    /**
     * The channel of the significant tokens.
     */
    public const self DEFAULT = self::Default;

    /**
     * Returns every channel of this set, indexed by its own name.
     *
     * @return non-empty-array<non-empty-string, Channel>
     */
    public static function names(): array
    {
        $result = [];

        foreach (self::cases() as $case) {
            $result[$case->name] = $case;
        }

        return $result;
    }
}
```

### 3.5 `Phplrt\Contracts\Lexer\UserDefinedChannel`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Lexer;

/**
 * A channel that is defined outside of the basic set of the token channels.
 */
readonly class UserDefinedChannel implements ChannelInterface
{
    public function __construct(
        /**
         * The name of the channel.
         *
         * @var non-empty-string
         */
        public string $name,
    ) {}
}
```

### 3.6 `Phplrt\Contracts\Lexer\Exception\LexerExceptionInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Lexer\Exception;

/**
 * An error of the lexical analysis.
 *
 * Every exception thrown by a lexer MUST implement this interface.
 */
interface LexerExceptionInterface extends \Throwable {}
```

### 3.7 `Phplrt\Contracts\Lexer\Exception\RuntimeExceptionInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Lexer\Exception;

use Phplrt\Contracts\Lexer\TokenInterface;
use Phplrt\Contracts\Source\ReadableInterface;

/**
 * An error that occurs after the lexical analysis has been started and
 * indicates a problem in the analyzed source.
 */
interface RuntimeExceptionInterface extends LexerExceptionInterface
{
    /**
     * The source the error occurred in.
     */
    public ReadableInterface $source {
        get;
    }

    /**
     * The token the error occurred on.
     */
    public TokenInterface $token {
        get;
    }
}
```

## 4. See Also

- [Usage](/docs/advanced/lexer) - the phplrt lexer itself.
- [Lexer](/docs/advanced/lexer#channels) - what a lexer does with channels.
- [Parser Contracts](/docs/contracts/parser) - what consumes these tokens.
