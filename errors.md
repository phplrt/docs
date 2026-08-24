# Error Reporting

> This package can be installed separately with
> `composer require phplrt/exception`

An error that says "syntax error at offset 137" is technically correct and
practically useless. Phplrt errors point at the source:

```
error[UnexpectedTokenException]: Syntax error, unexpected "3" (T_NUMBER), T_PLUS expected
 --> expr.txt:2:1
1 | 1 + 2
2 | 3 * (4 + )
  | ^
3 |
```

This works out of the box: install `phplrt/exception` and any parser or lexer
exception renders like this when converted to a string.

The package has three entry points. `ErrorPrinter` is the one you use, and it
is what this page is about. The other two are the halves it is built from:
[`Analyzer`](/docs/errors/analyzer) works out where an error happened, and
[`SnippetReader`](/docs/errors/snippet-reader) reads the source code lines around
that place. Reach for them when you need the data rather than the picture.

## Catching Errors

```php
use Phplrt\Parser\Exception\UnexpectedTokenException;
use Phplrt\Source\VirtualSource;

try {
    $parser->parse(VirtualSource::createFromString('expr.txt', $input));
} catch (UnexpectedTokenException $e) {
    echo $e->getMessage(); // Syntax error, unexpected "3" (T_NUMBER), T_PLUS expected
    echo $e;               // ...plus the snippet above
}
```

The exception carries everything needed to build your own message:

```php
$e->token;         // the token it choked on
$e->token->name;   // T_NUMBER
$e->token->offset; // 6
$e->source;        // the source it was reading
```

## Naming Sources

This is the one thing you have to do yourself. A bare `StringSource` has no
name, so an error can only show the snippet:

```php
$parser->parse(StringSource::createFromString($input));
```

```
error[UnexpectedTokenException]: Syntax error, unexpected "3" (T_NUMBER), T_PLUS expected
1 | 3
  | ^
```

Wrap the input in a `FileSource` or a `VirtualSource` and the error can
say *where*:

```php
$parser->parse(VirtualSource::createFromString('user-input.txt', $input));
```

```
 --> user-input.txt:2:1
```

`VirtualSource` costs nothing - it is a string with a name attached - so
use it even when the input never touched the disk.

## Catching By Stage

The contracts give you one interface per stage, which is usually the right
granularity:

```php
use Phplrt\Contracts\Lexer\Exception\LexerExceptionInterface;
use Phplrt\Contracts\Parser\Exception\ParserExceptionInterface;
use Phplrt\Contracts\Source\Exception\SourceExceptionInterface;

try {
    $parser->parse($source);
} catch (SourceExceptionInterface $e) {
    // The source could not be read at all
} catch (LexerExceptionInterface $e) {
    // The text could not be turned into tokens
} catch (ParserExceptionInterface $e) {
    // The tokens did not match the grammar
}
```

Each has a `RuntimeExceptionInterface` counterpart for errors that happened
while reading a *particular* source, as opposed to errors in the setup:

```php
use Phplrt\Contracts\Parser\Exception\RuntimeExceptionInterface;

catch (RuntimeExceptionInterface $e) {
    // Something in the input, not something in the grammar
}
```

## Your Own Errors

Parsing is rarely the last step. The stages after it - resolving names, type
checking, evaluating - find their own problems, and those deserve the same
treatment.

`ErrorPrinter::print()` takes an exception and works out everything else from
it. An exception implementing the lexer or parser contracts already knows the
source and the token it failed on, so nothing else is needed:

```php
use Phplrt\Exception\ErrorPrinter;

echo new ErrorPrinter()->print($e);
```

An ordinary exception knows only the PHP file and line it was thrown from, so
that is what gets printed. To point it at your own source instead, say where:

```php
use Phplrt\Exception\ErrorPrinter;
use Phplrt\Source\VirtualSource;

$source = VirtualSource::createFromString('config.txt', <<<'TXT'
    name = "phplrt"
    version = four
    debug = true
    TXT);

echo new ErrorPrinter()
    ->print(new \DomainException('Expected a number'))
    ->withSource($source)
    ->withInterval(offset: 26, length: 4);
```

```
error[DomainException]: Expected a number
 --> config.txt:2:11
1 | name = "phplrt"
2 | version = four
  |           ^^^^
3 | debug = true
```

This is where the `$offset` you kept on every AST node pays for itself.

## Overrides

`print()` returns a `PrintableError`, a builder in which every `with*()`
method returns a new object. The source code is read only at the moment the
result is turned into a string, so building it costs nothing:

```php
use Phplrt\Exception\Analysis\FailureLevel;

$printer->print($e)
    ->withMessage('...')                // the message above the snippet
    ->withClass('MyError')              // the name in brackets after the level
    ->withLevel(FailureLevel::Warning)  // the severity
    ->withSource($source)               // the source the snippet is read from
    ->withInterval(26, 4)               // the underlined fragment, in bytes
    ->withoutInterval();                // no fragment at all, just the position
```

Every one of them replaces a value inside the
[analysis of the error](/docs/errors/analyzer), which is the only thing a
`PrintableError` holds besides the renderer:

```php
$printed = $printer->print($e);

$printed->error->class;   // the class of the exception
$printed->error->message; // the message of the exception
$printed->error->level;   // FailureLevel::Error, or the severity of an ErrorException
$printed->error->source;
$printed->error->interval;
```

An empty message hides the whole header, and an empty class prints the
severity alone:

```php
echo $printer->print($e)->withClass('');
```

```
error: Expected a number
 --> config.txt:2:11
1 | name = "phplrt"
2 | version = four
  |           ^^^^
3 | debug = true
```

### Severity

`FailureLevel::Error`, `FailureLevel::Warning` and `FailureLevel::Debug` are
available. An error that carries a severity of its own - a PHP
`ErrorException` - is printed with it, so `E_USER_WARNING` becomes
`FailureLevel::Warning` on its own:

```php
use Phplrt\Exception\Analysis\FailureLevel;

FailureLevel::fromException($e);           // the severity of an ErrorException, or the default one
FailureLevel::fromSeverity(\E_DEPRECATED); // FailureLevel::Debug
```

### Chain And Trace

Every error that has led to the one being printed is printed as well, the
innermost one first, and the stack trace of the error closes the report:

```
error[ParseError]: syntax error, unexpected token "}"
 --> config.txt:2:11
...
error[DomainException]: The configuration cannot be read
 --> config.txt:2:11
...
#0 /app/src/Config.php(42): App\Config->read()
#1 {main}
```

An error whose source cannot be read any more is left out of the report
instead of taking the rest of it down.

## Colors

Output to a terminal is colored, and output to anything else is not. The
decision is made by `RustStyleRenderer::createDefault()`, which respects
[`NO_COLOR`](https://no-color.org) and `FORCE_COLOR`.

Override it by choosing the renderer yourself:

```php
use Phplrt\Exception\ErrorPrinter;
use Phplrt\Exception\Printer\Renderer\AnsiRustStyleRenderer;
use Phplrt\Exception\Printer\Renderer\RawRustStyleRenderer;

$printer = new ErrorPrinter(new RawRustStyleRenderer());  // never colored
$printer = new ErrorPrinter(new AnsiRustStyleRenderer()); // always colored
```

Both are `RustStyleRenderer`: the same layout, printed either as plain text or
with the escape sequences. A line is printed as long as it is - nothing is
wrapped or cut - so the output is as wide as the widest line of the source.

## Your Own Renderer

`RendererInterface` takes everything that is known about the error and returns
a string, so a one-line-per-error format for a CI log is a small class. The
source code lines are read by a [`SnippetReader`](/docs/errors/snippet-reader)
of its own:

```php
use Phplrt\Contracts\Source\FileInterface;
use Phplrt\Exception\Analysis\FailureResult;
use Phplrt\Exception\Printer\Renderer\RendererInterface;
use Phplrt\Exception\Snippet\CapturedSourceLine;
use Phplrt\Exception\SnippetReader;

final class CompactRenderer implements RendererInterface
{
    public function __construct(
        private readonly SnippetReader $reader = new SnippetReader(),
    ) {}

    public function render(FailureResult $error, \Throwable $e): string
    {
        foreach ($this->reader->read($error) as $line) {
            // Only the lines containing the error itself are captured
            if ($line instanceof CapturedSourceLine) {
                return \sprintf(
                    '%s:%d:%d: %s',
                    $error->source instanceof FileInterface ? $error->source->pathname : '-',
                    $line->number,
                    $line->captured->offset + 1,
                    $error->message,
                );
            }
        }

        return $error->message;
    }
}
```

```php
echo new ErrorPrinter(new CompactRenderer())
    ->print($e)
    ->withSource($source)
    ->withInterval(26, 4);
// config.txt:2:11: Expected a number
```

The same renderer can be chosen for a single error rather than for the whole
printer:

```php
echo $printer->print($e)->withRenderer(new CompactRenderer());
```

## Self-Printing Exceptions

The pattern the parser uses works for anything: keep the source and the
offset on the exception, and render them in `__toString()`.

```php
use Phplrt\Contracts\Source\ReadableInterface;
use Phplrt\Exception\ErrorPrinter;

final class TypeException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ReadableInterface $source,
        public readonly int $offset,
        public readonly int $length = 0,
    ) {
        parent::__construct($message);
    }

    public function __toString(): string
    {
        return (string) new ErrorPrinter()
            ->print($this)
            ->withSource($this->source)
            ->withInterval($this->offset, $this->length);
    }
}
```

The message and the class are taken from the exception itself, so nothing is
repeated.

An exception that implements `Phplrt\Contracts\Lexer\Exception\RuntimeExceptionInterface`
or its parser counterpart needs none of this - `print($this)` finds the source
and the fragment through the contract:

```php
public function __toString(): string
{
    return (string) new ErrorPrinter()->print($this);
}
```

Now every error your language reports looks the same as every error phplrt
reports, which is exactly what you want.

## Whats Next?

`ErrorPrinter` is the two halves below glued together and rendered. Use them
directly when the picture is not what you are after:

- [Analysing an Error](/docs/errors/analyzer) - `Analyzer`, the source, the
  position and the fragment behind any `Throwable`, for diagnostics you report
  somewhere other than a terminal.
- [Reading a Snippet](/docs/errors/snippet-reader) - `SnippetReader`, the
  source code lines around the fragment and the exact bytes captured on each
  of them.
