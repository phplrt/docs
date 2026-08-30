# PP3 Grammar Syntax

A grammar file describes a language: the words it is made of, and the order
they may appear in. Here is one in full:

```pp3
// Any space char should be ignored
%skip  T_WHITESPACE  \s++

// What a number and a plus looks like
%token T_DIGIT       \d++
%token T_PLUS        \+

// Where to start
%pragma root Sum

// The sentences
Sum 
  : <T_DIGIT> (::T_PLUS:: <T_DIGIT>)*
  // Equivalent to the following:
  // 2
  // 2 + 3
  // 2 + 3 + 4
  // 2 + 3 + 4 + 5
  // 2 + 3 + 4 + 5 + ...etc
  ;
```

Save it as `grammar.pp3` and it is ready to use:

```php
$parser = new Compiler()
    ->load(FileSource::createFromPathname(__DIR__ . '/grammar.pp3'))
    ->getParser();
```

The syntax is a close relative of
[EBNF](https://en.wikipedia.org/wiki/Extended_Backus%E2%80%93Naur_form), so
if you have written a grammar before, most of this will look familiar.

## Comments

C-style, both kinds:

```pp3
// Everything to the end of the line

/*
   Everything between the markers
 */
```

## Includes

```pp3
%include grammar/lexemes
%include grammar/expressions.pp3
```

- the path is relative to **the file the `%include` is written in**;
- the extension may be omitted;
- a file included from several places is read **once**, so a shared
  `lexemes.pp3` can be included by everything that needs it.

Declarations land exactly where the `%include` is written, so whatever an
included file declares takes its place in that order.

## Tokens

```pp3
%token T_DIGIT  \d++
```

A name and a regular expression, separated by whitespace. The name is
whatever you like; by convention tokens are `SCREAMING_CASE` with a `T_`
prefix, which makes them obvious wherever they are referred to.

`%skip` declares a token that is read and then stepped over. Use it for
whitespace and comments - they still get recognized, so offsets stay correct,
but they never leave the lexer and do not clutter the grammar:

```pp3
%skip T_WHITESPACE  \s++
%skip T_COMMENT     //[^\n]*+
```

**Order matters.** The lexer takes the first pattern that matches, not the
longest one, so a longer token is declared before a shorter one it starts
with:

```pp3
%token T_POW   \*\*    // ✔
%token T_STAR  \*
```

See [Order Matters](/docs/advanced/lexer-builder#order-matters).

**A declaration is one line.** It is read by a lexer of its own, which starts
at `%token` and stops at the line break:

```
%token  T_QUOTE  "
        ▲        ▲
        name     expr
```

Two more things may be written on that line - the [state](#states) the token
belongs to and the [actions](#actions) it performs - and both are below.

**A pattern cannot contain a literal space** - whitespace is what separates
the parts of the declaration. Write it as `\x20` or `\s`:

```pp3
%token T_TEXT  [a-z ]++     // ✘ breaks
%token T_TEXT  [a-z\x20]++  // ✔
%token T_TEXT  [a-z\s]++    // ✔
```

Anything else on the line is an error, which is how a `.pp2` habit gets
noticed:

```
error[UnexpectedTokenException]: Syntax error, unexpected "->" (T_PATTERN)
 --> /app/grammar.pp3:1:20
1 | %token T_QUOTE  "  -> string
  |                    ^^
```

## Fragments

The same piece of a pattern tends to be written over and over - a digit, an
identifier, an escape sequence. `%fragment` names it once, and `(?&NAME)`
writes it wherever it belongs:

```pp3
%fragment DIGIT  [0-9]
%fragment EXP    [eE][+-]?(?&DIGIT)++

%token T_NUMBER  (?&DIGIT)++(\.(?&DIGIT)++)?(?&EXP)?
```

The compiled token is what the pieces spell out, so nothing of this reaches
the lexer:

```
(?:[0-9])++(\.(?:[0-9])++)?(?:[eE][+-]?(?:[0-9])++)?
```

A piece is written in as a group of its own, so a quantifier after `(?&NAME)`
counts the whole piece rather than its last character.

**A fragment is no token.** It recognizes nothing and appears in no stream -
it is a piece of an expression and nothing else. A grammar declaring nothing
but fragments declares no tokens at all.

**Order does not matter.** Unlike a token, a fragment is written in once the
whole grammar has been read, so it may be declared after what refers to it and
in another `%include`d file:

```pp3
%token T_NUMBER  (?&DIGIT)++
%fragment DIGIT  [0-9]          // ✔
```

A name that no fragment is declared under is reported at the token that refers
to it, which is what a misspelled piece looks like:

```
error[CompilationFailedException]: The /(?&DIGT)++/ (T_NUMBER) expression refers to
the "DIGT" fragment, which has not been declared
 --> /app/grammar.pp3:3:1
3 | %token T_NUMBER  (?&DIGT)++
  | ^^^^^^^^^^^^^^^^^^^^^^^^^^^
```

So is a piece written of itself, whether directly or through another one:

```pp3
%fragment A  (?&B)
%fragment B  (?&A)   // ✘ written of itself
```

**`(?&NAME)` is PCRE's own spelling** for calling a subpattern, and an
expression capturing a subpattern under that name keeps it - the call is left
alone rather than being taken for a fragment:

```pp3
%token T_NESTED  \((?<in>[^()]|(?&in))*+\)   // ✔ reads itself, no fragment involved
```

## States

A lexer reads one set of tokens at a time. A prefix before the name declares
the token in a state of its own, and a state reads nothing but the tokens
declared in it:

```pp3
%token        T_QUOTE_OPEN   "
%token string:T_TEXT         [^"]++
%token string:T_QUOTE_CLOSE  "
```

Everything written with no prefix belongs to the initial state, which is where
reading begins. Getting into `string` and back out is what [actions](#actions)
do.

**A fragment belongs to no state.** It is a piece of an expression rather than
a thing to read, so it is written into the tokens of every state, and a state
of its own is an error:

```pp3
%fragment WORD  [a-z]++

%token        T_NAME  (?&WORD)
%token string:T_TEXT  (?&WORD)    // ✔ written in here as well

%fragment string:WORD  [a-z]++    // ✘ a fragment takes no state
```

### Shared Tokens

Whitespace and comments are usually the same wherever they appear, and
repeating them in every state is how a grammar drifts out of sync with itself.
Write `*:` instead of a state name and the token is added to all of them:

```pp3
%skip  *:T_WHITESPACE  \s++
%token *:T_NAME        \w++
```

Three things worth knowing:

- the token is added to **every** state, including ones declared later or in
  a file included afterwards - the states are all known only once the whole
  grammar has been read;
- it keeps its place among the tokens around it, and inside a state of its own
  it is tried **after** the tokens that state declares itself, so a state with
  a catch-all pattern still wins;
- an [external lexer](#external-lexers) is left alone: what it recognizes is
  decided by that lexer, not by a declaration.

## Actions

A declaration may end with `->` and say what the token *does* besides being
read. There are three actions, and each is written as a call:

| Action       | What the token does                                       |
|--------------|-----------------------------------------------------------|
| `channel(x)` | Emits the token to the channel `x`                        |
| `state(x)`   | Hands the reading over to the lexer of the state `x`      |
| `exit()`     | Gives the control back to the lexer that entered this one |

A fragment is read by nothing, so it does nothing either:

```pp3
%fragment WORD  [a-z]++ -> exit()   // ✘ a fragment takes no action
```

### channel (x)

A [channel](/docs/advanced/lexer#channels) labels a token, so that a reader of the stream
can tell it apart from the code around it - documentation comments are the
usual reason:

```pp3
%token T_DOC_COMMENT  /\*\*.*?\*/  -> channel(docblocks)
```

A channel of your own is reported like any other, so the token is right there
in the stream for anything that wants it; which channels are left out is a
setting of the lexer. `%skip` is shorthand for the built-in `Hidden` channel -
the one channel left out by default - so these two lines mean the same thing:

```pp3
%skip  T_WHITESPACE  \s++
%token T_WHITESPACE  \s++  -> channel(Hidden)
```

### state (x) and exit ()

`state(x)` hands the reading over to the lexer of a state, and `exit()` gives
it back, which is how a fragment written in different lexical rules is read -
a string literal, a comment, an embedded language:

```pp3
%token        T_QUOTE_OPEN  "       -> state(string)
%token string:T_TEXT        [^"]++
%token string:T_QUOTE_CLOSE "       -> exit()
```

Everything the inner lexer reads is carried by the token that entered it, so
`T_TEXT` never reaches the outer stream. See
[Nested Lexers](/docs/advanced/embedding).

### Several At Once

Actions are separated by commas, and the order does not matter:

```pp3
%token T_QUOTE_OPEN  "  -> state(string), channel(strings)
```

A token is read once and therefore goes to exactly one place, so writing two
actions that both move the reading - `state(x), exit()` - is an error.

## External Lexers

Some fragments cannot be described by regular expressions at all - heredocs,
indentation-sensitive blocks, another language entirely. `%lexer` names a
state and gives the expression building the lexer that reads it:

```pp3
%token T_PHP_OPEN  <\?php  -> state(php)

%lexer php -> { new \App\Lexer\PhpTokenLexer() }
```

The body is an **expression**, not a block of statements - whatever it
evaluates to has to be a `LexerInterface`. Note there is no `return` and no
semicolon.

Such a lexer decides on its own where its fragment ends: it stops, and control
returns to the lexer that called it, so it needs no token doing `exit()`. See
[Nested Lexers](/docs/advanced/embedding).

## Rules

A rule is a name, a colon, a body, and an optional semicolon:

```pp3
Sum : <T_DIGIT> ::T_PLUS:: <T_DIGIT> ;
```

The colon is the only separator there is. By convention rules are
`PascalCase`, which tells them apart from tokens at a glance.

Long rules read better spread out:

```pp3
Expression
  : Term() ((<T_PLUS> | <T_MINUS>) Term())*
  ;
```

### Token References

Two spellings, and the difference is whether the token ends up in the result:

```pp3
Rule : <T_DIGIT> ;    // read it and keep it
Rule : ::T_COMMA:: ;  // read it and throw it away
```

Keep the things that carry information (names, numbers, literals). Discard the
punctuation that only holds the syntax together (commas, brackets, keywords).

```pp3
// A parenthesized expression: the brackets are required, but useless
Group : ::T_PARENTHESIS_OPEN:: Expression() ::T_PARENTHESIS_CLOSE:: ;
```

### Rule References

Parentheses after the name - that is what tells a rule reference from a token:

```pp3
Sum : Number() ::T_PLUS:: Number() ;
```

The rule may be declared anywhere, including in a file that has not been read
yet. References are resolved after everything is loaded.

### Inline Tokens

A rule may declare a token right where it reads it, without naming it. There
are two spellings, and the difference is whether what you write is text or an
expression:

| Written | Means                                            |
|---------|--------------------------------------------------|
| `"..."` | the **text** to read, exactly as it is written   |
| `/.../` | the **regular expression** recognizing the token |

```pp3
Sum  : <T_NUMBER> "+" <T_NUMBER> ;          // a plus sign
Expr : <T_NUMBER> /and|or|xor/ <T_NUMBER> ; // one of three words
```

Quotes are the one you want for punctuation: nothing inside them is special,
so `"+"`, `"("` and `"**"` mean exactly what they look like. Slashes are for
when you need a choice, a character class or a quantifier.

The same token written in several rules is declared **once**, and such a token
is always discarded - it is punctuation by definition:

```pp3
Sum     : <T_NUMBER> "+" <T_NUMBER> ;
Unary   : "+" <T_NUMBER> ;              // the very same token
```

Escaping is only ever needed for the delimiter itself - `\"` inside quotes,
`\/` inside slashes.

Handy for one-off punctuation; for anything that appears more than twice,
declare a real token so the error messages can name it.

> A slash also opens a comment, so `//` and `/*` are read as one. Write `/\//`
> for a lone slash.

### Sequence

Written one after another, the parts are read in the order they appear, and
all of them have to match:

```pp3
Pair : <T_NAME> ::T_EQUAL:: Value() ;
```

### Choice

```pp3
Primary : Number() | Name() | Group() ;
```

The alternatives are tried **in order**, and the first match wins, so a
longer alternative is written before a shorter one it starts with:

```pp3
Rule : "ab" | "a" ;   // ✔ - the other way round never reads "ab"
```

See [Alternatives Are Ordered](/docs/advanced/parser#alternatives-are-ordered).

### Grouping

```pp3
Rule : <T_A> (<T_B> | <T_C>) <T_D> ;
```

### Quantifiers

Any token, rule or group can be followed by one:

| Written  | Means                |
|----------|----------------------|
| `e?`     | zero or one time     |
| `e*`     | zero or more times   |
| `e+`     | one or more times    |
| `e{3}`   | exactly three times  |
| `e{2,5}` | between two and five |
| `e{2,}`  | two or more          |
| `e{,5}`  | up to five           |

```pp3
Arguments : Argument() (::T_COMMA:: Argument())* ;
Digits    : <T_DIGIT>{3} ;
Modifiers : Modifier()* ;
```

### Predicates

A predicate looks at what comes next **without reading it**. Nothing is
consumed and nothing lands in the tree - the only thing that happens is that
the rule either goes on or gives up.

| Written | Means                                     |
|---------|-------------------------------------------|
| `&e`    | go on only if `e` matches here            |
| `!e`    | go on only if `e` does **not** match here |

The classic use is refusing a position that belongs to somebody else. A name
that is not a function call:

```pp3
Variable : <T_NAME> !::T_PARENTHESIS_OPEN:: ;
```

`foo` matches. `foo(` does not - and, importantly, the `(` is still there
afterwards for whatever rule does want it.

The other direction is committing to a branch without reading it twice:

```pp3
// Only try the expensive rule when the line really starts with "fn"
Closure : &::T_FN:: FunctionLiteral() ;
```

A predicate is written **before** the quantifier, so it looks ahead at the
whole thing at once:

```pp3
Rule : &<T_DIGIT>+ Number() ;   // look ahead at one or more digits
```

Two things to keep in mind:

- a predicate contributes nothing to the result, so adding one does not shift
  the positions a rule reads;
- it costs a real attempt at matching. `!Expression()` will parse a whole
  expression and throw it away, so prefer looking ahead at a token.

This is the one thing in a rule body that describes *how* something is read
rather than *what* the language contains, which is why EBNF has no equivalent.

## Error Messages

By default a failure is reported by the tokens that could have been read:

```
Syntax error, unexpected "," (T_COMMA), T_NAME or T_STRING expected
```

`@error(...)` replaces that with your own sentence. Write it after the element
it describes:

```pp3
Call
  : <T_NAME>
    ::T_PARENTHESIS_OPEN::
    ArgumentList()?
    ::T_PARENTHESIS_CLOSE:: @error("The argument list is never closed")
  ;
```

```
error[UnexpectedTokenException]: The argument list is never closed
1 | foo(1, 2
  |         ^
```

Write it after a rule name to describe the whole rule:

```pp3
Statement @error("a statement is expected")
  : Assignment() | Call() | Return()
  ;
```

The rule the message belongs to becomes the code of the exception, counted from
one. When messages are nested, the innermost one wins.

### Placeholders

A message may ask about what the reading broke on, in braces, the way a logger
does:

```pp3
@error("unexpected {value} on line {line}, a closing brace is expected")
```

| Placeholder       | Is                                                |
|-------------------|---------------------------------------------------|
| {token}           | the token the reading broke on, described in full |
| {name}            | the name of that token                            |
| {value}           | the text that token is read from                  |
| {offset}          | the offset in bytes the reading broke at          |
| {line}            | the source line the reading broke on              |
| {column}          | the column within that line                       |
| {expected}        | `T_OPEN, T_CLOSE, T_COMMA (+1 more)`              |
| {expected_list}   | `T_OPEN, T_CLOSE, T_COMMA or T_NAME`              |

Write a brace twice to keep it: `@error("use {{name}} here")`. An unknown
placeholder is reported while the grammar is compiled.

### Where A Message Fires

A message fires once the parser has entered the element and could not finish
it. Put it past the token that commits the rule, never on that token itself:

```pp3
Call : <T_NAME> @error("...") ::T_PARENTHESIS_OPEN:: ;   // ✘ never fires
Call : <T_NAME> ::T_PARENTHESIS_OPEN:: @error("...") ;   // ✔
```

A message that could never fire is a compilation error, pointed at the line it
is written on. So is a message on something that always matches (`X?`, `X*`),
or one written inside a repetition or a predicate.

The rule the grammar starts at is the exception - its message covers every
failure the rules inside it leave undescribed, including a source that has been
read only in part.

## Reducers

A grammar with no reducers returns the tokens it kept. To build something
else, attach PHP:

```pp3
Number -> { return (int) $children->value; }
  : <T_DIGIT>
  ;
```

A block of code is the only form a reducer takes - build the node inside it:

```pp3
Number -> { return new \App\Ast\NumberNode($offset, $children->value); }
  : <T_DIGIT>
  ;
```

This has a page of its own: [Results and Reducers](/docs/basics/reducers).

## Settings

`%pragma` configures the compilation from the grammar itself, so a grammar
that needs a particular setting carries it instead of relying on the code that
compiles it. The one nearly every grammar wants is where parsing starts:

```pp3
%pragma root Expression
```

By default it starts at the first rule in the file - which, once a grammar is
split across files, depends on include order, and that is a fragile thing to
depend on.

| Setting                   | What it does                                       |
|---------------------------|----------------------------------------------------|
| `root <Rule>`             | Where parsing starts                               |
| `lexer.pcre.flag <M>`     | Compiles the lexer's pattern with a PCRE modifier  |
| `lexer.pcre.disable <M>`  | Compiles it without one                            |
| `lexer.pass <Class>`      | Registers a lexer pass, normalizing                |
| `lexer.check <Class>`     | Registers a lexer pass, checking                   |
| `lexer.optimize <Class>`  | Registers a lexer pass, optimizing                 |
| `lexer.complete <Class>`  | Registers a lexer pass, checking after optimizing  |
| `lexer.disable <Class>`   | Drops a lexer pass, whenever it was registered     |
| `parser.pass <Class>`     | Registers a parser pass, normalizing               |
| `parser.check <Class>`    | Registers a parser pass, checking                  |
| `parser.optimize <Class>` | Registers a parser pass, optimizing                |
| `parser.complete <Class>` | Registers a parser pass, checking after optimizing |
| `parser.disable <Class>`  | Drops a parser pass                                |

Anything else is an error.

### PCRE Modifiers

The lexer compiles its tokens into one pattern, and these say which modifiers
that pattern carries. A modifier is named either the way PCRE spells it or the
way phplrt calls it:

```pp3
%pragma lexer.pcre.flag     Caseless   // ...or "i"
%pragma lexer.pcre.disable  Utf8       // ...or "u"
```

By default, the pattern is compiled with `S`, `u`, `s` and `m`. See
[Regex Modifiers](/docs/advanced/lexer-builder#regex-modifiers) for what each of them means.

### Compiler Passes

A pass rewrites or checks the lexer or the grammar while it is being built,
and the setting is named after the moment it runs at:

```pp3
%pragma parser.check     \App\Grammar\NoLeftFactoringPass
%pragma lexer.optimize   \App\Grammar\MergeKeywordsPass
```

The class is created with no arguments and must implement
`LexerCompilerPassInterface` or `ParserCompilerPassInterface` - a class that
does not exist, or implements the wrong one, is reported at the line it is
written on.

A built-in pass can be dropped by name, which is how a grammar opts out of an
optimization it does not want:

```pp3
%pragma parser.disable \Phplrt\Parser\Builder\Compiler\NestedConcatenationParserCompilerPass
```

[Parser Builder](/docs/advanced/parser-builder) describes what the passes are and
when each priority runs.

## Naming

Nothing is enforced, but the usual style makes grammars much easier to read:

```pp3
%fragment DIGIT  [0-9]   // fragments: SCREAMING_CASE, no prefix
%token T_NUMBER  \d++    // tokens:    T_SCREAMING_CASE
Expression : ... ;       // rules:     PascalCase
```

A fragment without the `T_` prefix reads as what it is at the place it is
referred to: `(?&DIGIT)` is a piece of a pattern, `<T_DIGIT>` is a token.

## A Fuller Example

```pp3
%skip  T_WHITESPACE  \s++
%skip  T_COMMENT     //[^\n]*+

%token T_NUMBER      \d++(?:\.\d++)?
%token T_STRING      "[^"]*+"
%token T_TRUE        true
%token T_FALSE       false
%token T_NULL        null
%token T_NAME        [a-zA-Z_][a-zA-Z0-9_]*+

%pragma root Config

// name = value
// name = value
Config : Pair()* ;

Pair : <T_NAME> "=" Value() ;

Value
  : <T_NUMBER>
  | <T_STRING>
  | <T_TRUE>
  | <T_FALSE>
  | <T_NULL>
  | List()
  ;

// [a, b, c]
List : "[" (Value() ("," Value())*)? "]" ;
```

Note `T_TRUE` before `T_NAME` - otherwise `true` is read as a name. And note
that the punctuation is never declared: `"="`, `"["`, `","` and `"]"` each
declare their token where they are read, and none of them needs escaping
because a value is not an expression.
