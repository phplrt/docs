# Quick Start

Let's build a parser for a small configuration format from scratch: text in, a
tree of objects out.

```
// A small config
name = "phplrt"
version = 4
debug = true
```

```php
[
    Property { name: "name",    value: Literal { value: "phplrt" } },
    Property { name: "version", value: Literal { value: 4 } },
    Property { name: "debug",   value: Literal { value: true } },
]
```

It takes about forty lines of grammar, and along the way you will meet every
part of phplrt you are likely to need.

```bash
composer require phplrt/runtime
composer require phplrt/compiler --dev
```

## Step 1: Describe The Words

Before anything can be parsed, the text has to be cut into pieces. Create
`grammar.pp3` and start with the tokens:

```pp3
%skip  T_WHITESPACE  \s++
%skip  T_COMMENT     //[^\n]*+

%token T_BOOLEAN  (?:true|false)\b
%token T_NAME     [a-zA-Z_][a-zA-Z0-9_]*+
%token T_STRING   "[^"]*+"
%token T_NUMBER   \d++
%token T_EQUAL    =
```

Each line is a name and a regular expression. `%skip` is the same as `%token`,
except those tokens never reach the parser - which is exactly what you want
for whitespace and comments.

The order matters: the lexer tries the patterns from top to bottom and takes
the first one that matches. `T_BOOLEAN` is above `T_NAME` for that very
reason - the other way round, `true` would be read as a name.

## Step 2: Describe The Sentences

Now the rules. A rule has a name, a `:`, a body, and an optional `;`:

```pp3
Property : <T_NAME> ::T_EQUAL:: Value() ;
```

Three things are going on in that line:

- `<T_NAME>` reads a token **and keeps it**;
- `::T_EQUAL::` reads a token and **throws it away** - the `=` has done its job
  by being there, and nobody needs it afterwards;
- `Value()` refers to another rule.

`|` means "or". The alternatives are tried in order, and the first one that
matches wins - so put the more specific ones first:

```pp3
Value
  : String()
  | Number()
  | Boolean()
  ;
```

A config is a list of properties, so the rule the parsing starts at is a
repetition:

```pp3
%pragma root Config

Config : Property()* ;
```

`*` means "zero or more times", so an empty file is a valid config. There is
also `+` (one or more), `?` (optional) and `{2,5}` (between two and five
times). `%pragma root` names the rule everything else hangs off.

## Step 3: Build The Result

At this point the grammar is complete - it can already tell `name = 4` from
`name =`. But recognizing text is not the same as getting something out of it.
To build a value, attach a **reducer**: a block of PHP that runs when the rule
matches.

Start with the nodes. They are plain objects - no base class, nothing from
phplrt in them:

```php
namespace App\Ast;

final readonly class Property
{
    public function __construct(
        public string $name,
        public Literal $value,
        public int $offset,
    ) {}
}

final readonly class Literal
{
    public function __construct(
        public mixed $value,
        public int $offset,
    ) {}
}
```

Now the rules that build them:

```pp3
String -> { return new \App\Ast\Literal(\substr($children->value, 1, -1), $offset); }
  : <T_STRING>
  ;

Number -> { return new \App\Ast\Literal((int) $children->value, $offset); }
  : <T_NUMBER>
  ;

Boolean -> { return new \App\Ast\Literal($children->value === 'true', $offset); }
  : <T_BOOLEAN>
  ;
```

Inside the block, `$children` holds whatever the rule matched. For `String`
that is the one token it kept, so `$children->value` is the text `"phplrt"` -
quotes included, which is what `substr()` takes off.

`$offset` is where the token starts, in bytes. Keeping it on every node is a
habit worth forming: it is what lets a later stage - a validator, a linter,
your own error - point at the right place in the file.

For a rule that matched several things, `$children` is a list:

```pp3
Property -> {
    return new \App\Ast\Property(
        name: $children[0]->value,
        value: $children[1],
        offset: $children[0]->offset,
    );
}
  : <T_NAME> ::T_EQUAL:: Value()
  ;
```

Two elements, not three: `::T_EQUAL::` was discarded by the grammar, so the
reducer never sees it. And `$children[1]` is a `Literal` rather than a token,
already built - reducers run bottom-up, innermost rule first.

`Value` and `Config` get no reducer at all. A rule without one hands its
children up as they are, which is exactly right here: `Value` is a choice
between three rules that already build nodes, and `Config` is the list of
properties itself.

## The Whole Grammar

```pp3
%skip  T_WHITESPACE  \s++
%skip  T_COMMENT     //[^\n]*+

%token T_BOOLEAN  (?:true|false)\b
%token T_NAME     [a-zA-Z_][a-zA-Z0-9_]*+
%token T_STRING   "[^"]*+"
%token T_NUMBER   \d++
%token T_EQUAL    =

// Where the parsing starts
%pragma root Config

Config : Property()* ;

Property -> {
    return new \App\Ast\Property(
        name: $children[0]->value,
        value: $children[1],
        offset: $children[0]->offset,
    );
}
  : <T_NAME> ::T_EQUAL:: Value()
  ;

Value
  : String()
  | Number()
  | Boolean()
  ;

String -> { return new \App\Ast\Literal(\substr($children->value, 1, -1), $offset); }
  : <T_STRING>
  ;

Number -> { return new \App\Ast\Literal((int) $children->value, $offset); }
  : <T_NUMBER>
  ;

Boolean -> { return new \App\Ast\Literal($children->value === 'true', $offset); }
  : <T_BOOLEAN>
  ;
```

## Step 4: Run It

```php
use Phplrt\Compiler\Compiler;
use Phplrt\Source\FileSource;
use Phplrt\Source\StringSource;

$parser = new Compiler()
    ->load(FileSource::createFromPathname(__DIR__ . '/grammar.pp3'))
    ->getParser();

$ast = $parser->parse(StringSource::createFromString(<<<'CONF'
    // A small config
    name = "phplrt"
    version = 4
    debug = true
    CONF));
```

```php
$ast[0]->name;         // "name"
$ast[0]->value->value; // "phplrt"
$ast[0]->offset;       // 18

$ast[1]->value->value; // 4    - an int, because the reducer cast it
$ast[2]->value->value; // true - a bool, same

$parser->parse(StringSource::createEmpty()); // [] - an empty config is still a config
```

Notice the two kinds of "source": `FileSource` reads from disk and
`StringSource` wraps a string you already have. The grammar is a source, and
so is the text being parsed - they are the same kind of object.

## Step 5: Errors

Feed it something broken, and you get an exception that knows where it
happened:

```php
use Phplrt\Source\StringSource;
use Phplrt\Source\VirtualSource;

// VirtualSource attaches a name, so errors can point at it
$parser->parse(VirtualSource::createFromString('config.txt', <<<'CONF'
    name = "phplrt"
    version = = 4
    debug = true
    CONF));
```

```
error[UnexpectedTokenException]: Syntax error, unexpected "=" (T_EQUAL), one of T_BOOLEAN, T_STRING, T_NUMBER expected
 --> config.txt:2:11
1 | name = "phplrt"
2 | version = = 4
  |           ^
3 | debug = true
```

If you only want to know whether the input is valid, without building
anything, use `analyze()`:

```php
use Phplrt\Parser\Analysis\Mode;
use Phplrt\Parser\Analysis\Result\SuccessfulResult;

$parser->analyze(StringSource::createFromString('name = "x"'), Mode::SyntaxCheck) instanceof SuccessfulResult; // true
$parser->analyze(StringSource::createFromString('name ='), Mode::SyntaxCheck) instanceof SuccessfulResult;     // false
```

It also tells you *how much* of the input is valid and what stands in the way,
which is what you want for an editor or a prompt - see
[Analysing A Source](/docs/parser#analysing-a-source).

## Step 6: Compile It Once

Reading `grammar.pp3` on every request is wasteful - the grammar does not
change between requests. Generate a PHP file instead and commit it:

```php
use Phplrt\Compiler\Compiler;
use Phplrt\Source\FileSource;

new Compiler()
    ->load(FileSource::createFromPathname(__DIR__ . '/grammar.pp3'))
    ->generate()
        ->withNamespaceName('App\Config')
        ->withClassName('CompiledConfigParser')
        ->save(__DIR__ . '/CompiledConfigParser.php');
```

You get an ordinary class, with the tokens as constants and every reducer as a
real method:

```php
namespace App\Config;

readonly class CompiledConfigParser extends \Phplrt\Parser\Parser
{
    public const int T_WHITESPACE = 0;
    public const int T_COMMENT = 1;
    public const int T_BOOLEAN = 2;
    // ...

    public function __construct()
    {
        parent::__construct(/* the whole grammar, inlined */);
    }

    private static function reduceString(\Phplrt\Parser\Context $ctx, mixed $children): mixed
    {
        // The variables below are declared by the compiler
        $offset = $ctx->token->offset;

        return new \App\Ast\Literal(\substr($children->value, 1, -1), $offset);
    }

    // ...
}
```

Which you use like any other class - no compiler, no grammar file:

```php
$parser = new App\Config\CompiledConfigParser();

$parser->parse(StringSource::createFromString('debug = true'))[0]->name; // "debug"
```

Add the generation call to your build script or a console command, and you
are done.

## Step 7: Extend The Parser

So far the config can only say what is written in it. Real formats reach
outside: environment variables, includes, a base directory, a registry of
keys that are allowed at all.

The generated parser is an ordinary class, so **extend it** - and the grammar
can reach whatever you add, through `$this`.

Add references to the grammar:

```pp3
%token T_REFERENCE  \$\{([a-zA-Z_][a-zA-Z0-9_]*+)\}

Value
  : String()
  | Number()
  | Boolean()
  | Reference()
  ;

// $this is the parser itself, so the value comes from the subclass
Reference -> { return $this->reference($children->captures[0], $offset, $source); }
  : <T_REFERENCE>
  ;
```

The pattern has a capturing group, and `$children->captures[0]` is what that
group matched - the name alone, without the `${}` around it. More on captures
in [Tokens and Channels](/docs/lexer/tokens).

Regenerate, and notice what the compiler did with the new rule:

```php
private function reduceReference(\Phplrt\Parser\Context $ctx, mixed $children): mixed
{
    // The variables below are declared by the compiler
    $source = $ctx->source;
    $offset = $ctx->token->offset;

    return $this->reference($children->captures[0], $offset, $source);
}
```

A reducer that mentions `$this` becomes a **non-static** method, and the
grammar table refers to it as `$this->reduceReference(...)` instead of
`self::reduceString(...)`. The compiler works this out per reducer, so you do
not configure anything.

Now subclass and fill in `reference()`:

```php
namespace App\Config;

use App\Ast\Literal;
use Phplrt\Contracts\Source\ReadableInterface;
use Phplrt\Exception\ErrorPrinter;

final class UnknownVariableException extends \InvalidArgumentException {}

final readonly class ConfigParser extends CompiledConfigParser
{
    /**
     * @param array<non-empty-string, string> $variables
     */
    public function __construct(
        private array $variables = [],
    ) {
        parent::__construct();
    }

    protected function reference(string $name, int $offset, ReadableInterface $source): Literal
    {
        if (!isset($this->variables[$name])) {
            throw new UnknownVariableException((string) new ErrorPrinter()
                ->print($source, $offset, \strlen($name) + 3)
                ->withMessage(\sprintf('Unknown variable "%s"', $name))
                ->withClass('UnknownVariableException'));
        }

        return new Literal($this->variables[$name], $offset);
    }
}
```

```php
$parser = new ConfigParser(['APP_ENV' => 'prod']);

$parser->parse(StringSource::createFromString('env = ${APP_ENV}'))[0]
    ->value->value; // "prod"
```

The grammar stays a description of the *language*: `${...}` is a reference in
this format whatever your variables happen to be. What a reference resolves
to is a decision, and decisions live in PHP. Each instance carries its own
data, so two parsers with different variables are two objects.

And because the reducer handed `reference()` the offset and the source, an
error from your code reads exactly like one from the parser:

```
error[UnknownVariableException]: Unknown variable "NOPE"
 --> config.txt:2:7
1 | name = "phplrt"
2 | env = ${NOPE}
  |       ^^^^^^^
3 |
```

`ErrorPrinter` renders any offset in any source - see
[Error Reporting](/docs/errors). Subclassing has a few rules of its own, and
they are collected in [Best Practices](/docs/guide/best-practice).

## Whats Next?

- [Grammar Syntax](/docs/compiler/grammar) - the full `.pp3` reference.
- [PHP in a Grammar](/docs/compiler/code) - reducers, the variables they see
  and what `$children` holds.
- [Lexer](/docs/lexer) - channels, captures and nested lexers.
- [Code Generation](/docs/compiler/generation) - namespaces, imports, and
  what the generated file looks like.
- [Best Practices](/docs/guide/best-practice) - what to do with the parser
  once it works.
