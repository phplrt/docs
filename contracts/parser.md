# Parser Contracts

This document describes a common interface for syntax analysis - turning a
source into whatever that source means - together with the interfaces for the
errors raised along the way.

The goal is to let anything that uses a parser - a template engine, a
configuration loader, a rule engine - depend on the ability to parse rather
than on a particular parser, whether it is handwritten, assembled at runtime
or [generated from a grammar](/docs/basics/generation).

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "MAY", and "OPTIONAL" in this document are to be
interpreted as described in [RFC 2119](https://tools.ietf.org/html/rfc2119).

## 1. Specification

### 1.1 Parser

A parser is an object implementing `ParserInterface`.

- The `parse()` method takes a source implementing `ReadableInterface` and
  returns the result of its syntax analysis.
- The shape of that result is defined by the implementation, which MAY return
  any value the analyzed source is converted into - an abstract syntax tree, a
  list of nodes, an array of settings, a number.
- An implementation SHOULD declare which of those it returns through the
  `TResult` template parameter, so that the value is known statically.

```php
use Phplrt\Contracts\Parser\ParserInterface;

/**
 * @implements ParserInterface<Node>
 */
final class ExpressionParser implements ParserInterface { /* ... */ }
```

```php
/**
 * @param ParserInterface<Node> $parser
 */
function walk(ParserInterface $parser, ReadableInterface $source): void
{
    $node = $parser->parse($source); // Node, rather than mixed
}
```

### 1.2 Errors

- Every exception thrown by a parser MUST implement
  `ParserExceptionInterface`, internal failures included.
- An error that occurs **before** the analysis begins - a source that cannot
  be recognized, settings that contain errors - is a plain
  `ParserExceptionInterface`.
- An error that occurs **after** the analysis has begun, and therefore
  indicates a problem in the analyzed source, MUST implement
  `RuntimeExceptionInterface`.

`RuntimeExceptionInterface` carries the source the error occurred in, the
token it occurred on, and the size of the fragment it covers:

- `$length` is counted in bytes and starts at the offset of `$token`.
- `$length` MAY be as large as the whole grammar rule the analysis failed on,
  since a parser fails on a token but the construct at fault is usually
  larger.
- `$length` is `null` when the size is not known.

```php
use Phplrt\Contracts\Parser\Exception\RuntimeExceptionInterface;

try {
    $ast = $parser->parse($source);
} catch (RuntimeExceptionInterface $e) {
    \printf(
        "%s at offset %d, %d bytes\n",
        $e->getMessage(),
        $e->token->offset,
        $e->length ?? $e->token->size,
    );
}
```

Rendering such an error as a snippet of the source is what
[phplrt/exception](/docs/basics/errors) does with the same three members.

## 2. Package

The interfaces described here are provided as part of the
[phplrt/parser-contracts](https://github.com/phplrt/parser-contracts) package:

```bash
composer require phplrt/parser-contracts
```

It contains no parser, and requires nothing but PHP,
[phplrt/lexer-contracts](/docs/contracts/lexer) and
[phplrt/source-contracts](/docs/contracts/source) - a syntax error has to name
the token it happened on.

A package implementing these interfaces provides the virtual package
`phplrt/parser-contracts-implementation`:

```bash
composer require phplrt/parser-contracts-implementation
```

`phplrt/parser` provides it, and every parser the compiler generates extends
`Phplrt\Parser\Parser`.

Note that `analyze()`, which the phplrt parser also has, is deliberately not
part of this specification: it is a diagnostic tool rather than part of what
makes an object a parser. See [Usage](/docs/advanced/parser).

The package ships a test declaring an anonymous implementation of every
interface in it. Changing that test is allowed only in a **major** release, so
no member can be added to any of these interfaces in a minor version.

## 3. Interfaces

### 3.1 `Phplrt\Contracts\Parser\ParserInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Parser;

use Phplrt\Contracts\Parser\Exception\ParserExceptionInterface;
use Phplrt\Contracts\Parser\Exception\RuntimeExceptionInterface;
use Phplrt\Contracts\Source\ReadableInterface;

/**
 * Converts a source into the result of its syntax analysis.
 *
 * @template TResult of mixed = mixed
 */
interface ParserInterface
{
    /**
     * Performs syntax analysis of the given source and returns its result.
     *
     * The shape of the result is defined by the implementation, which MAY
     * return any value the analyzed source is converted into, like an
     * abstract syntax tree (AST) or a list of its nodes.
     *
     * @return TResult the result of the analysis
     * @throws ParserExceptionInterface if the given source cannot be
     *         recognized or the parser settings contain errors
     * @throws RuntimeExceptionInterface if the analyzed source contains errors
     */
    public function parse(ReadableInterface $source): mixed;
}
```

### 3.2 `Phplrt\Contracts\Parser\Exception\ParserExceptionInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Parser\Exception;

/**
 * An error of the syntax analysis, including an internal one.
 *
 * Every exception thrown by a parser MUST implement this interface.
 */
interface ParserExceptionInterface extends \Throwable {}
```

### 3.3 `Phplrt\Contracts\Parser\Exception\RuntimeExceptionInterface`

```php
<?php

declare(strict_types=1);

namespace Phplrt\Contracts\Parser\Exception;

use Phplrt\Contracts\Lexer\TokenInterface;
use Phplrt\Contracts\Source\ReadableInterface;

/**
 * An error that occurs after the syntax analysis has been started and
 * indicates a problem in the analyzed source.
 */
interface RuntimeExceptionInterface extends ParserExceptionInterface
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

    /**
     * The size of the source fragment the error occurred in, in bytes, or
     * {@see null} in case the size is not known.
     *
     * The fragment starts at the offset of the token the error occurred on and
     * MAY be as large as the whole grammar rule the analysis has failed on.
     *
     * @var int<0, max>|null
     */
    public ?int $length {
        get;
    }
}
```

## 4. See Also

- [Usage](/docs/advanced/parser) - the phplrt parser itself.
- [Results and Reducers](/docs/basics/reducers) - what a result is usually made of.
- [Lexer Contracts](/docs/contracts/lexer) - the tokens a parser reads.
