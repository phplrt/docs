# Tokens and Channels

A token is the smallest thing a lexer produces. It is immutable, and it
carries five pieces of information.

```php
foreach ($lexer->lex(StringSource::createFromString('23 + 42')) as $token) {
    $token->id;      // 0       - which definition matched
    $token->name;    // T_DIGIT - its human-readable name, or null
    $token->value;   // "23"    - the exact text that matched
    $token->offset;  // 0       - where it starts, in bytes
    $token->size;    // 2       - how long it is, in bytes
    $token->channel; // Channel::Default
}
```

## Identifiers, Not Names

Tokens are compared **by identifier**, never by name:

```php
if ($token->id === MyParser::T_DIGIT) {
    // ...
}
```

The identifier is just the position of the token's definition in the lexer,
assigned when the lexer is built. Names exist for you and for error messages;
the parser does not use them at all, which is why a token is allowed to have
no name:

```php
$builder->addPattern('\s++')->hide(); // anonymous, and that is fine
```

System tokens use negative identifiers, so they can never collide with yours:

| Token             | Identifier | Channel      |
|-------------------|------------|--------------|
| `EndOfInputToken` | `-1`       | `EndOfInput` |
| `UnknownToken`    | `-2`       | `Unknown`    |

## Offsets

`offset` and `size` are counted in **bytes**, not in characters, so the two of
them address the fragment directly:

```php
$content = $source->content;

// The exact fragment the token was read from
$text = \substr($content, $token->offset, $token->size);

// Where the next token starts
$next = $token->offset + $token->size;
```

For an ordinary token `size` is simply `strlen($value)`. It differs only for a
token that [entered a nested lexer](/docs/advanced/embedding), which is as large
as everything that lexer read.

## Printing

Tokens are `Stringable`, and they print in a form meant for error messages:

```php
echo $token;
```

```
"23" (T_DIGIT)      // a named token
"@" (unknown token) // unrecognized input
end of input        // the terminal token
```

Long values are cut off, and control characters are escaped, so you can put a
token straight into a message without worrying about what is in it.

## Channels

A channel is a label on a token. The lexer decides which channels it reports,
and a token on any other channel is read like any other - so the offsets stay
right - and then stepped over.

There are four built-in channels:

| Channel      | Meaning                                                    |
|--------------|------------------------------------------------------------|
| `Default`    | An ordinary token. This is what a grammar is written in terms of. |
| `Hidden`     | Read, but not reported: whitespace, comments.               |
| `Unknown`    | Text the lexer did not recognize.                           |
| `EndOfInput` | The terminal token, exactly one per stream.                 |

```php
use Phplrt\Contracts\Lexer\Channel;

$builder->addPattern('\s++')->setChannel(Channel::Hidden);
$builder->addPattern('\s++')->hide();  // the same, shorter
$builder->addPattern('\s++')->show();  // back to Default
```

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

### Choosing What Is Reported

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

## Captures

If a token's pattern has capturing groups, whatever they matched is on the
token:

```php
$builder->addPattern('"([^"]*)"', 'T_STRING');
$builder->addPattern('(\d++)\.(\d++)', 'T_FLOAT');

// "hi" 3.14
$string->captures; // ["hi"]
$float->captures;  // ["3", "14"]
```

Captures are numbered per token, starting at zero - the first group of *this*
token is `captures[0]`, no matter how many groups the tokens above it have.

This saves you from parsing the value twice:

```php
// Instead of trimming the quotes off $token->value by hand
$content = $token->captures[0];
```

A group that matched nothing still counts, so the numbering stays stable:

```php
$builder->addPattern('(\+|-)?(\d++)', 'T_NUMBER');

// "42"  => captures: ["", "42"]
// "-42" => captures: ["-", "42"]
```

## The Interface

Write against the contract, not the implementation:

```php
use Phplrt\Contracts\Lexer\TokenInterface;

function describe(TokenInterface $token): string
{
    return \sprintf('%s at %d', $token->name ?? 'anonymous', $token->offset);
}
```

`Phplrt\Lexer\Token\Token` is the standard implementation;
`TokenEmbedding` extends it for [nested lexers](/docs/advanced/embedding).
