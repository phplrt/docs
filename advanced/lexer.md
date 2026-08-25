# Lexer

> This package can be installed separately with `composer require phplrt/lexer`

The lexer is the first half of reading source code: it turns a stream of
characters into a stream of **tokens**. `23 + 42` becomes "a number, a plus,
a number" - and the parser never has to look at a single character again.

What it reads is fixed by the time it exists: a lexer is one compiled regular
expression plus a couple of tables, produced by the
[lexer builder](/docs/advanced/lexer-builder) or by the
[grammar compiler](/docs/basics/compiler). This page is about running one.

## Reading A Source

`lex()` returns a list of tokens. Every token knows what it is, what it says
and where it was:

```php
foreach ($lexer->lex(StringSource::createFromString('23 + 42')) as $token) {
    $token->id;      // int    - 0, the position of its definition
    $token->name;    // string - "T_DIGIT"
    $token->value;   // string - "23"
    $token->offset;  // int    - 0, in bytes
    $token->size;    // int    - 2, in bytes
    $token->channel; // Channel::Default
}
```

The last token is always `end of input`. It is how the parser knows the source
has been read to the end.

You can also start somewhere other than the beginning:

```php
$lexer->lex(StringSource::createFromString('12 34'), offset: 3);
// only "34" and the end of input
```

What is on a token, and what to do with it, is
[What A Token Is](/docs/basics/reducers#what-a-token-is).

## Channels

A lexer reports every channel except the ones it is told to skip, and by
default that is `Hidden` alone:

```php
use Phplrt\Lexer\Lexer;

Lexer::DEFAULT_SKIP_CHANNELS; // [Channel::Hidden]
```

Nothing stands between the lexer and the grammar, so this is also the answer to
"what does the parser see": whatever the lexer reports.

The list is a setting of the lexer, so it goes where the lexer is put together:

```php
use Phplrt\Lexer\Builder\Transformer\RuntimeLexerTransformer;

$result = $builder->build();

// Reports everything but the hidden tokens
$lexer = $result->toLexer();

// Reports everything, hidden tokens included
$verbose = new RuntimeLexerTransformer(skip: [])
    ->transform($result);
```

Channels are matched **by name**, so a custom one is skipped by naming it - the
instance you construct does not have to be the one the token carries:

```php
use Phplrt\Contracts\Lexer\Channel;
use Phplrt\Contracts\Lexer\UserDefinedChannel;

$parsing = new RuntimeLexerTransformer(skip: [
    Channel::Hidden,
    new UserDefinedChannel('comments'),
])->transform($result);
```

One description therefore gives you as many lexers as there are readers of the
source: one leaving the comments out for the parser, and one reporting them for
the tool that wants them.

That is where the list ends up anyway - `Lexer` takes it as an argument of its
own, so a lexer assembled without the builder is configured the same way:

```php
use Phplrt\Lexer\Lexer;

$lexer = new Lexer(
    pattern: $result->pattern,
    channels: $result->channels,
    names: $result->names,
    skip: [],
);
```

Skipping happens while the source is being read, so a token nobody is going to
see is not built in the first place.

## Unrecognized Input

The lexer never fails on text it does not recognize. It emits a token on the
`Unknown` channel instead and carries on:

```php
$builder = new LexerBuilder();
$builder->addPattern('\d++', 'T_DIGIT');
$builder->addPattern('\s++')
    ->hide();
    
$lexer = $builder->build()
    ->toLexer();

foreach ($lexer->lex(StringSource::createFromString('12 @ 34')) as $token) {
    echo $token . "\n";
}

// "12" (T_DIGIT)
// "@" (unknown token)
// "34" (T_DIGIT)
// end of input
```

This is deliberate: a lexer that stops at the first strange character can
only report one problem, while an editor or a linter usually wants to see
the whole file. The parser is the one that decides an unknown token is an
error, and it does so with a message pointing at the exact spot.

## System Tokens

Two tokens are produced by the lexer rather than declared, and they use
negative identifiers so they can never collide with yours:

| Token             | Identifier | Channel      |
|-------------------|------------|--------------|
| `EndOfInputToken` | `-1`       | `EndOfInput` |
| `UnknownToken`    | `-2`       | `Unknown`    |

## What's Next?

- [Lexer Builder](/docs/advanced/lexer-builder) - describing the tokens this
  reads, and the channels they go on.
- [Nested Lexers](/docs/advanced/embedding) - string interpolation, PHP inside
  HTML and other "a different language starts here" situations.
- [Contracts](/docs/contracts/lexer) - `phplrt/lexer-contracts`, for code that
  needs a lexer without needing this one.
