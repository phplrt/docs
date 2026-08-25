# Lexer Builder

> This package can be installed separately with
> `composer require phplrt/lexer-builder`

A lexer is described before it can read anything: which tokens there are, what
each of them looks like, and what the lexer does with them. That description
is what the builder is for, and what it compiles into is the
[lexer](/docs/advanced/lexer) itself.

A `.pp3` file says the same things in fewer characters, so reach for the
builder when the token list is not known in advance - generated from a config
file, a database, a plugin system. The last section shows the two spellings
side by side.

## A First Lexer

Describe the tokens, build the lexer, run it:

```php
use Phplrt\Lexer\Builder\LexerBuilder;
use Phplrt\Source\StringSource;

$builder = new LexerBuilder();
$builder->addPattern('\d++', 'T_DIGIT');
$builder->addValue('+', 'T_PLUS');
$builder->addPattern('\s++', 'T_WHITESPACE');

$lexer = $builder->build()
    ->toLexer();

foreach ($lexer->lex(StringSource::createFromString('23 + 42')) as $token) {
    echo $token, "\n";
}
```

```
"23" (T_DIGIT)
" " (T_WHITESPACE)
"+" (T_PLUS)
" " (T_WHITESPACE)
"42" (T_DIGIT)
end of input
```

Two ways to describe a token:

- `addPattern('\d++')` - a **regular expression**;
- `addValue('+')` - a **literal string**, escaped for you. `addValue('+')` and
  `addPattern('\+')` are the same thing, but the first one is harder to get
  wrong.

The last token is always `end of input`. It is how the parser knows the
source has been read to the end.

## Order Matters

The lexer tries the patterns from top to bottom and takes the **first** one
that matches - not the longest. So this does not do what it looks like:

```php
$builder->addValue('*', 'T_STAR');
$builder->addValue('**', 'T_POW');   // never matched!
```

`**` is read as two `T_STAR` tokens, because `T_STAR` was declared first.
Put the longer literal above the shorter one:

```php
$builder->addValue('**', 'T_POW');   // ✔
$builder->addValue('*', 'T_STAR');
```

The same applies to keywords and identifiers - declare `if` before
`[a-z]+`, or `if` will always be read as an identifier.

## Hiding Whitespace

You almost never want whitespace in a grammar. Mark it as **hidden** and it is
still recognized (so the offsets stay right) but it is left out of the stream
entirely:

```php
$builder->addPattern('\s++')
    ->hide();
$builder->addPattern('//[^\n]*+')
    ->hide(); // line comments too
```

Note that the token above has no name. A hidden token is not referred to by
anything, so naming it is optional.

`hide()` is shorthand for putting the token on the `Hidden` channel, and that
is the one channel a lexer leaves out by default - see
[Channels](#channels) below, and
[Choosing What Is Reported](/docs/advanced/lexer#channels) for how to have the
hidden tokens reported after all.

## Fragments

A piece of a pattern that keeps coming back is named once and referred to by
`(?&NAME)`:

```php
$builder->addFragment('DIGIT', '[0-9]');
$builder->addFragment('EXP', '[eE][+-]?(?&DIGIT)++');

$builder->addPattern('(?&DIGIT)++(\.(?&DIGIT)++)?(?&EXP)?', 'T_NUMBER');
```

The pieces are written into the patterns while the lexer is built, so what it
compiles is what they spell out:

```
(?:[0-9])++(\.(?:[0-9])++)?(?:[eE][+-]?(?:[0-9])++)?
```

A fragment recognizes nothing on its own and becomes no token. It is written
in as a group of its own, so a quantifier after `(?&NAME)` counts the whole
piece; it may be written of another fragment, but not of itself; and it
reaches the patterns of every [nested lexer](/docs/advanced/embedding), in
whatever order it is added.

A name that no fragment is added under is left alone in case the pattern
captures a subpattern under it - which is what `(?&NAME)` means to PCRE - and
is reported otherwise:

```php
$builder->addPattern('\((?<in>[^()]|(?&in))*+\)', 'T_NESTED'); // ✔ left alone
$builder->addPattern('(?&DIGT)++', 'T_NUMBER');               // ✘ reported
```

## Regex Modifiers

By default, patterns are compiled with `S`, `u`, `s` and `m`. Add or remove
modifiers for the whole lexer:

```php
use Phplrt\Lexer\Builder\Definition\RegexModifier;

$builder->enable(RegexModifier::Caseless);  // /i
$builder->disable(RegexModifier::Utf8);     // no /u

$builder->addValue('true', 'T_TRUE');
// now matches "true", "TRUE" and "True"
```

## Channels

A channel is a label on a token, and it is declared where the token is:

```php
use Phplrt\Contracts\Lexer\Channel;

$builder->addPattern('\s++')->setChannel(Channel::Hidden);
$builder->addPattern('\s++')->hide();  // the same, shorter
$builder->addPattern('\s++')->show();  // back to Default
```

There are four built-in channels:

| Channel      | Meaning                                                           |
|--------------|-------------------------------------------------------------------|
| `Default`    | An ordinary token. This is what a grammar is written in terms of. |
| `Hidden`     | Read, but not reported: whitespace, comments.                     |
| `Unknown`    | Text the lexer did not recognize.                                 |
| `EndOfInput` | The terminal token, exactly one per stream.                       |

Which of them a lexer actually reports is decided when it is built rather than
when it is described - see
[Choosing What Is Reported](/docs/advanced/lexer#channels).

### Custom Channels

Hiding a token throws it away. Sometimes you want to *keep* it, just told
apart from the code around it - documentation comments are the usual example.
Give it a channel of its own:

```php
$builder->addPattern('\d++', 'T_DIGIT');
$builder->addPattern('//[^\n]*+', 'T_COMMENT')->setChannel('comments');
$builder->addPattern('\s++')->hide();

foreach ($lexer->lex(StringSource::createFromString("1 // hi\n2")) as $token) {
    echo $token->name, ' on ', $token->channel->name, "\n";
}
```

```
T_DIGIT on Default
T_COMMENT on comments
T_DIGIT on Default
```

`T_COMMENT` is in the stream like any other token, and the channel is what
tells it apart: a documentation generator reads the `comments` tokens and
ignores the rest.

## Reusing The Result

`build()` gives you a `LexerBuilderResult` - the compiled description - and
`toLexer()` turns that into a runnable lexer:

```php
$result = $builder->build();

$result->pattern;  // the single regex the whole lexer compiles down to
$result->names;    // [0 => 'T_DIGIT', 1 => 'T_PLUS', ...]
$result->channels; // [2 => 'Hidden', ...]

$lexer = $result->toLexer();
```

Building is not free - it validates every pattern, drops unreachable tokens
and merges everything into one big regular expression. Do it once and keep
the lexer around, or better still,
[generate the code](/docs/basics/compiler) and skip building entirely.

## How The Pattern Is Built

All the token definitions compile into a single PCRE pattern, and which token
matched is recorded with
[`(*MARK:n)`](https://www.pcre.org/current/doc/html/pcre2pattern.html):

```
/\G(?|(?:(?:\d++)(*MARK:0))|(?:(?:\s++)(*MARK:1))|(?:(?:[^\s]++)(*MARK:2)))/Ssum
```

One pass over the input, one regex, no per-token loop - this is where the
lexer's speed comes from. The last branch is the catch-all that produces
`Unknown` tokens.

`MarkersRegexGenerator` does this, and it is swappable if you ever need a
different strategy:

```php
use Phplrt\Lexer\Builder\Analysis\RegexConstructionLexerAnalysisPass;
use Phplrt\Lexer\Builder\Regex\RegexGeneratorInterface;

final class MyRegexGenerator implements RegexGeneratorInterface
{
    public function generate(array $tokens, array $flags): string
    {
        // $tokens is a map of token id => TokenDefinition
    }
}

$builder->addAnalysisPass(
    new RegexConstructionLexerAnalysisPass(new MyRegexGenerator()),
);
```

## Writing It In A Grammar Instead

Everything above has a shorter spelling in a `.pp3` file:

```pp3
%fragment DIGIT      [0-9]

%token T_DIGIT       (?&DIGIT)++
%token T_PLUS        \+
%skip  T_WHITESPACE  \s++
```

A token nothing refers to by name does not have to be declared at all - a rule
declares it where it reads it, and the two spellings are the two methods above:

```pp3
Sum  : <T_DIGIT> "+" <T_DIGIT> ;          // addValue('+')
Expr : <T_DIGIT> /and|or/ <T_DIGIT> ;     // addPattern('and|or')
```

The modifiers and the compiler passes are settings of the grammar there, so a
grammar carries the way it wants to be compiled:

```pp3
%pragma lexer.pcre.flag  Caseless
%pragma lexer.check      \App\Grammar\MyValidationPass
```

That is usually where you want to be - see
[Compiling a Grammar](/docs/basics/compiler). The builder API is for the cases where
the token list is not known in advance: generated from a config file, a
database, a plugin system.

## What's Next?

- [Lexer](/docs/advanced/lexer) - reading a source with what this compiles into.
- [Nested Lexers](/docs/advanced/embedding) - string interpolation, PHP inside
  HTML and other "a different language starts here" situations.
- [Contracts](/docs/contracts/lexer) - `phplrt/lexer-contracts`, for code that
  needs a lexer without needing this one.
