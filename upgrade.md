# Upgrade Guide

## Upgrading To 4.0 From 3.x

Version 4.0 is a rewrite, so this is a porting guide rather than a list of
renames. The grammar files mostly survive, and that is where the bulk of the
work usually lives.

### PHP 8.4 Required

> Likelihood Of Impact: **High**

The API uses property hooks, asymmetric visibility and `new` in initializers
throughout - which is also why so much of it looks different.

### Getters Became Properties

> Likelihood Of Impact: **High**

Everything that was `getX()` is now a property. This is the single most
common change you will hit.

```php
// 3.x
$token->getName();
$token->getOffset();
$token->getValue();
$token->getBytes();

$source->getContents();
$source->getPathname();

// 4.x
$token->name;
$token->offset;
$token->value;
$token->size;

$source->content;
$source->pathname;
```

### Tokens Are Identified By Number

> Likelihood Of Impact: **High**

In 3.x a token was addressed by its name. In 4.x it has an `int $id`, and the
name is optional metadata for error messages.

```php
// 3.x
if ($token->getName() === 'T_DIGIT') { /* ... */ }

// 4.x
if ($token->id === MyParser::T_DIGIT) { /* ... */ }
```

[Generated parsers](/docs/compiler/generation) expose the ids as class
constants, so you do not have to track the numbers yourself.

### The Lexer Is Built, Not Configured

> Likelihood Of Impact: **High**

`Phplrt\Lexer\Lexer` no longer takes a map of names and patterns. It takes a
single compiled regular expression and a set of tables - which you get from
`LexerBuilder`.

```php
// 3.x
$lexer = new Lexer([
    'T_DIGIT' => '\d+',
    'T_PLUS'  => '\+',
]);

// 4.x
use Phplrt\Lexer\Builder\LexerBuilder;

$builder = new LexerBuilder();
$builder->addPattern('\d++', 'T_DIGIT');
$builder->addValue('+', 'T_PLUS');

$lexer = $builder->build()
    ->toLexer();
```

`append()`, `prepend()`, `prependMany()` and `skip()` are gone; `addPattern()`,
`addValue()` and `hide()` cover the same ground.

### Channels Replaced "Skipped Tokens"

> Likelihood Of Impact: **Medium**

The lexer no longer takes a list of names to skip. Every token carries a
[channel](/docs/lexer/tokens), and the lexer reports every channel except the
ones it is told to leave out - `Hidden` alone, unless you say otherwise.

```php
// 3.x
$lexer = new Lexer($tokens, ['T_WHITESPACE']);

// 4.x
$builder->addPattern('\s++', 'T_WHITESPACE')
    ->hide();
```

### The Parser Takes Explicit Arguments

> Likelihood Of Impact: **High**

The `Parser::CONFIG_*` options are gone, replaced by constructor parameters:

```php
// 3.x
$parser = new Parser($lexer, $grammar, [
    Parser::CONFIG_INITIAL_RULE => 'expression',
    Parser::CONFIG_AST_BUILDER  => new MyBuilder(),
]);

// 4.x
$parser = new Parser(
    lexer: $lexer,
    grammar: $grammar,
    initial: 0,
    reducers: [0 => $callback],
);
```

Rules are keyed by **integers** rather than by name, and they refer to each
other by index. In practice you do not write this array by hand - see
[the parser builder](/docs/parser/builder).

### BuilderInterface Became Per-Rule Reducers

> Likelihood Of Impact: **High**

The single AST builder with a `switch` over rule names is gone. Each rule now
carries its own reducer.

```php
// 3.x
class MyBuilder implements BuilderInterface
{
    public function build(Context $ctx, $children)
    {
        switch ($ctx->getState()) {
            case 'Number': return new NumberNode($children);
            case 'Sum':    return new SumNode($children);
        }

        return null;
    }
}

// 4.x - in the grammar file
Number -> { return new \NumberNode($offset, $children->value); } 
    : <T_DIGIT> ;
Sum    -> { return new \SumNode($children); } 
    : Number() ::T_PLUS:: Number() ;
```

The signature is still `callable(Context $ctx, mixed $children): mixed`, but
`$ctx->getState()` is now `$ctx->rule` and holds an integer. Returning `null`
still means "leave the children alone".

### `check()` And Trailing Tokens Became `analyze()`

> Likelihood Of Impact: **Medium**

3.x answered "is this valid?" with `check()`, and read a source the grammar
does not describe in full through a setting, picking up where it stopped from
the parser afterwards. Both are `analyze()` now:

```php
// 3.x
$parser->check($source); // true or false
$context = $parser->getLastExecutionContext();

// 4.x
use Phplrt\Parser\Analysis\Mode;

$result = $parser->analyze($source, Mode::SyntaxCheck);

$result->value; // what has been read reduced to
$result->token; // where the parser stopped
$result->error; // and the exception it would be rejected with
```

How far the grammar got is the class of the result: `SuccessfulResult`,
`PartialResult` or `FailureResult`. Nothing is kept on the parser between
calls, so `getLastExecutionContext()` has no replacement.

See [Analysing A Source](/docs/parser#analysing-a-source).

### A Source Is Read By Offset

> Likelihood Of Impact: **Medium**

3.x gave you the source in one piece and nothing smaller. 4.x keeps the whole
in `$content` and adds `read()`, which names the fragment it wants:

```php
// 3.x
$whole = $source->getContents();
$part = \fread($source->getStream(), 4);

// 4.x
$whole = $source->content;
$part = $source->read(0, 4);
```

`read()` takes an absolute offset, and reading a source leaves it as it is.
`getStream()` has no replacement - build a `ResourceSource` over a resource of
your own where you need one.

Sources are also constructed directly now. The static factory methods on
`File` still work, but are deprecated:

```php
// 3.x
File::fromPathname('/app/x.txt');
File::fromSources('2 + 2');

// 4.x
FileSource::createFromPathname('/app/x.txt');
StringSource::createFromString('2 + 2');
VirtualSource::createFromString('x.txt', '2 + 2'); // a string with a name
```

`SourceFactory::createDefault()` is there if you need the "figure out what this
is" behaviour, now behind a single `create()` method.

See [Source](/docs/source).

### Positions Are Calculated By A Factory

> Likelihood Of Impact: **Medium**

`phplrt/position` is still there, with a smaller API. A `Position` is the line
and the column alone - the offset it was built from is not a part of it, and
`createFromOffset()` already knows it.

`createAtStarting()` is spelled `new Position()`, `createAtEnding()` is an
offset past the end of the source, and `Interval`, `IntervalFactoryTrait` and
`PositionFactoryTrait` are gone.

See [Position](/docs/position).

### The `.pp` Format Is No Longer Read

> Likelihood Of Impact: **Medium**

Grammars written in the original Hoa-style `.pp` format are not supported.
A `.pp` file is still recognized by its extension, so you get a clear error
rather than a confusing parse failure. Rewrite the grammar in one of the
formats that are read - see [Grammar Syntax](/docs/compiler/grammar).

### Grammar Files: What To Check

> Likelihood Of Impact: **Medium**

A grammar file keeps its extension and keeps being read the way it was, so most
of them compile unchanged. What no longer works:

**The old pragmas.** Unification and the error levels are gone; the
corresponding behaviour is either the default now or is configured in PHP.
Which settings a grammar may carry is listed under
[Settings](/docs/compiler/grammar#settings).

**`$file` and `$state` in a reducer.** Use `$source` and `$rule`, which is an
`int`. See [PHP in a Grammar](/docs/compiler/code).

**Left recursion**, which is now rejected at build time. It never worked at
runtime either, but 3.x would let you compile it. Rewrite as a repetition:

```pp3
// ✘ rejected
Expression : Expression() ::T_PLUS:: Number() ;

// ✔
Expression : Number() (::T_PLUS:: Number())* ;
```

**Reducers returning `null`** mean "no result" and pass the children through.
If a rule of yours legitimately produces `null`, wrap it.

**Reducers returning arrays** are flattened into the rule above. If you
relied on nesting, return an object instead. See
[Results and Reducers](/docs/parser/ast).

### Everything Else That Is Gone

> Likelihood Of Impact: **Low**

| 3.x                                    | 4.x                                        |
|----------------------------------------|--------------------------------------------|
| `phplrt/buffer`                        | internal to `phplrt/parser`                |
| `ReadableInterface::getHash()`         | nothing - hash `$content` yourself         |
| `File::fromPsrStream()`                | a `SourceDriverInterface` of your own      |
| `SourceProviderInterface`              | `SourceDriverInterface`, given to the ctor |
| `Rule::reduce()`                       | nothing - rules are pure data              |
| `new Lexeme('T_DIGIT')`                | `new Lexeme(MyLexer::T_DIGIT)`             |

Code generation, dropped in 3.0 in favour of a config array, is back and now
emits the lexer, the token constants and the reducers as a real class - see
[Code Generation](/docs/compiler/generation).
