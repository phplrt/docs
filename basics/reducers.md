# Results and Reducers

A parser does two things: it decides whether the input is valid, and it builds
something out of it. The first part is the grammar. The second part is
**reducers**.

## What You Get Without Reducers

A grammar with no reducers still returns something - the tokens it kept,
nested the way the rules were:

```pp3
Sum : <T_DIGIT> (::T_PLUS:: <T_DIGIT>)* ;
```

```php
$parser->parse(StringSource::createFromString('2 + 3'));
// [Token("2"), Token("3")]
```

That is occasionally enough. Usually you want numbers, or AST nodes, or a
configuration array - and for that you attach a reducer.

## Attaching A Reducer

A reducer is a block of PHP between `->` and the rule body, and it runs when
the rule matches:

```pp3
Number -> { return (int) $children->value; }
  : <T_DIGIT>
  ;
```

Whatever it returns becomes the value of that rule, and gets handed to the
rule above. The code is ordinary PHP - loops, conditionals, whatever you need:

```pp3
Name -> {
    $name = $children->value;

    if (\str_starts_with($name, '$')) {
        return new \App\Ast\VariableNode($offset, \substr($name, 1));
    }

    return new \App\Ast\ConstantNode($offset, $name);
}
  : <T_NAME>
  ;
```

Braces inside strings are safe - the block is read by a real PHP lexer, so
`"{"` is a string, not the end of the block. An empty block (`-> {}`) is the
same as writing no reducer at all.

The same thing through [the builder](/docs/advanced/parser-builder):

```php
use Phplrt\Parser\Context;

$number->setReducer(static fn(Context $ctx, mixed $children): int
    => (int) $children->value);
```

## The Variables

Inside a code block, these are available:

| Variable              | What it is                                            |
|-----------------------|-------------------------------------------------------|
| `$children`           | What the rule matched. **This is the important one.** |
| `$ctx`                | The full `Context` object                             |
| `$offset` or `$begin` | Where the rule starts, in bytes                       |
| `$length`             | How many bytes the rule covers                        |
| `$end`                | Where the rule ends - `$offset + $length`             |
| `$source`             | The source being parsed                               |
| `$content`            | The whole content of that source                      |
| `$rule`               | The id of the rule being reduced                      |

All except `$children` and `$ctx` are shorthands the compiler expands for
you - `$offset` becomes `$ctx->begin`, and so on. They are only declared if
you use them, so there is no cost to the ones you do not.

The position covers the tokens the rule **kept**, so a token dropped by
`::T_NAME::` counts for nothing: the span of `::T_LP:: Expr() ::T_RP::`
starts and ends where `Expr` does, parentheses aside. A rule that kept no
tokens at all - an optional that matched nothing, say - is empty at the
position the reading has reached, and its `$length` is zero.

## What `$children` Contains

This is the part worth reading twice.

**A rule that recognizes a sequence** - a concatenation or a repetition -
gets an **array**:

```pp3
Pair : <T_DIGIT> <T_DIGIT> ;      // $children = [Token, Token]
List : <T_DIGIT>+ ;               // $children = [Token, Token, ...]
```

**Any other rule** gets the single value it recognized:

```pp3
Number : <T_DIGIT> ;              // $children = Token
Choice : Number() | Name() ;      // $children = whatever matched
Maybe  : Number()? ;              // $children = the Number, or nothing
```

Which of the two it is depends on the kind of rule, not on the input.

And the arrays are **flattened into the parent**. If a nested rule returns a
list, its items are spliced into the list of the rule above rather than
nested inside it:

```pp3
Root : <T_A> Pair() <T_B> ;
Pair : <T_DIGIT> <T_DIGIT> ;

// Root's $children = [Token(a), Token(1), Token(2), Token(b)]
//               not  [Token(a), [Token(1), Token(2)], Token(b)]
```

This is deliberate - it keeps the result flat and predictable - but it means
that a rule which *should* produce a group must say so by returning a value
of its own. A reducer returning an object or a scalar is never flattened:

```pp3
Pair -> { return $children; /* an array */ }  // still flattened
Pair -> { return new PairNode($children); }   // stays one value ✔
```

Because a rule can match one thing or a list depending on the input, reducers
often start with a check:

```pp3
Expression -> {
    // Just one operand: nothing to fold
    if (!\is_array($children)) {
        return $children;
    }

    // ...
}
  : Number() ((<T_PLUS> | <T_MINUS>) Number())*
  ;
```

## What A Token Is

Most of what a reducer is handed in `$children` is tokens:

```php
$token->id;      // int    - what the token is, compared against a constant
$token->name;    // string - what it is called, for messages; may be null
$token->value;   // string - the exact text it was read from
$token->offset;  // int    - where that text starts, in bytes
$token->size;    // int    - how many bytes it covers
$token->channel; // Channel - the label it was read on
```

**Compare by `id`, never by name.** The identifier is the position of the
token's definition in the lexer; names exist for you and for error messages,
and the parser does not use them at all - which is why a token is allowed to
have none:

```php
if ($token->id === MyParser::T_DIGIT) {
    // ...
}
```

[Generated parsers](/docs/basics/compiler) expose the ids as class constants,
so you never have to write the numbers yourself.

**`offset` and `size` are bytes**, not characters, so the two of them address
the fragment directly:

```php
$content = $source->content;

// The exact fragment the token was read from
$text = \substr($content, $token->offset, $token->size);

// Where the next token starts
$next = $token->offset + $token->size;
```

For an ordinary token `size` is simply `strlen($value)`. It differs only for a
token that [entered a nested lexer](/docs/advanced/embedding), which is as
large as everything that lexer read.

**Tokens print themselves** in a form meant for error messages, with long
values cut off and control characters escaped, so one can go straight into a
message without worrying about what is in it:

```php
echo $token;
```

```
"23" (T_DIGIT)      // a named token
"@" (unknown token) // unrecognized input
end of input        // the terminal token
```

### Captures

If a token's pattern has capturing groups, whatever they matched is on the
token, which saves parsing the value twice:

```php
// "hi" from  "([^"]*)"      3.14 from  (\d++)\.(\d++)
$string->captures; // ["hi"]
$float->captures;  // ["3", "14"]

// Instead of trimming the quotes off $token->value by hand
$content = $token->captures[0];
```

Captures are numbered per token, starting at zero - the first group of *this*
token is `captures[0]`, no matter how many groups the tokens above it have. A
group that matched nothing still counts, so the numbering stays stable:

```
"42"  => captures: ["", "42"]     from  (\+|-)?(\d++)
"-42" => captures: ["-", "42"]
```

### The Interface

Write against the contract rather than the implementation:

```php
use Phplrt\Contracts\Lexer\TokenInterface;

function describe(TokenInterface $token): string
{
    return \sprintf('%s at %d', $token->name ?? 'anonymous', $token->offset);
}
```

`Phplrt\Lexer\Token\Token` is the standard implementation, and
`TokenEmbedding` extends it for [nested lexers](/docs/advanced/embedding).
Which tokens reach a reducer at all is a matter of
[channels](/docs/advanced/lexer#channels).

## Building An AST

Here is the whole pattern. Define node classes:

```php
abstract class Node
{
    public function __construct(
        public readonly int $offset,
    ) {}
}

final class NumberNode extends Node
{
    public function __construct(int $offset, public readonly float $value)
    {
        parent::__construct($offset);
    }
}

final class BinaryNode extends Node
{
    public function __construct(
        int $offset,
        public readonly string $operator,
        public readonly Node $left,
        public readonly Node $right,
    ) {
        parent::__construct($offset);
    }
}
```

Nothing here knows about the parser: a node is handed exactly the values it
needs, which is a little more typing in the grammar and leaves you with plain
value objects.

Then build them in the grammar:

```pp3
%skip  T_WHITESPACE  \s++
%token T_NUMBER      \d++(?:\.\d++)?
%token T_PLUS        \+
%token T_MINUS       \-

%pragma root Expression

Expression -> {
    if (!\is_array($children)) {
        return $children;
    }

    $node = \array_shift($children);

    // Fold left: 1 + 2 - 3  =>  ((1 + 2) - 3)
    while ($children !== []) {
        $operator = \array_shift($children);
        $right = \array_shift($children);

        $node = new \BinaryNode($node->offset, $operator->value, $node, $right);
    }

    return $node;
}
  : Number() ((<T_PLUS> | <T_MINUS>) Number())*
  ;

Number -> { return new \NumberNode($offset, (float) $children->value); }
  : <T_NUMBER>
  ;
```

Parsing `1 + 2 - 3` gives you a tree:

```
BinaryNode(-)
├── BinaryNode(+)
│   ├── NumberNode(1)
│   └── NumberNode(2)
└── NumberNode(3)
```

Note `$offset` in the `Number` reducer - that is one of the
[variables the compiler provides](#the-variables). Keeping an offset on
every node is what lets you point at the right place in the source when
something goes wrong later, during type-checking or evaluation.

## The Context

Every reducer receives a `Context` as its first argument, describing where the
analysis is:

```php
static function (Context $ctx, mixed $children): mixed {
    $ctx->rule;   // int - the id of the rule being reduced
    $ctx->begin;  // int - where this rule starts, in bytes
    $ctx->length; // int - how many bytes it covers
    $ctx->source; // the source being parsed

    return null;
}
```

In a `.pp3` reducer you rarely touch `$ctx` directly, because the common
fields have shorter names - `$offset`, `$length`, `$end`, `$source`, listed
above, and `$end` is worked out for you.

## Returning Nothing

A reducer that returns `null` leaves the value alone: the children are passed
up as if there were no reducer at all. That makes `null` useful for reducers
that only observe:

```pp3
Debug -> {
    \error_log('matched at ' . $offset);

    return null; // do not touch the result
}
  : <T_NAME>
  ;
```

## Reducers Run After Parsing

One thing to be aware of: reducers do not run while the input is being read.
The parser first recognizes the whole source, then walks what it recognized
and reduces it bottom-up.

This means:

- a reducer never sees a rule that was tried and rejected - no wasted work,
  no side effects from a branch that did not win;
- `analyze()` in `Mode::SyntaxCheck` never runs reducers at all;
- a reducer cannot influence parsing. It cannot look ahead, change what is
  matched next, or fail the parse to force a different alternative. If a
  decision depends on the input, express it in the grammar - that is what
## In Generated Code

When you [generate a parser](/docs/basics/compiler), reducers become real
methods, named after the rule they belong to:

```php
private static function reduceNumber(\Phplrt\Parser\Context $ctx, mixed $children): mixed
{
    return (float) $children->value;
}
```

Two practical consequences.

**Your code appears verbatim in the generated file.** It is debuggable and
steppable, and a syntax error in a reducer is a syntax error in that file -
so run the generator as part of your build, not at deploy time.

**A grammar file has no `use` statements**, so how a short class name resolves
depends on where the reducer ends up - the global namespace when the grammar
is read on the fly, the generated file's namespace when it is generated. The
safe answer is to write class names fully qualified:

```pp3
// ✔ works either way
Number -> { return new \App\Ast\NumberNode($offset, $children->value); }
```

If the fully qualified names make a big grammar unreadable, you can declare
the imports on the generated file instead:

```php
new Compiler()
    ->load(FileSource::createFromPathname(__DIR__ . '/grammar.pp3'))
    ->generate()
        ->withNamespaceName('App\Parser')
        ->withClassImport('App\Ast\NumberNode')
        ->save(__DIR__ . '/Parser.php');
```

```pp3
Number -> { return new NumberNode($offset, $children->value); }
```

The trade-off: that grammar now only works when generated. Pick one approach
per project rather than mixing them.
