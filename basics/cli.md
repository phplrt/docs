# Command Line

A grammar is edited far more often than the code around it, and two things get
done over and over while editing: making sure the grammar still holds
together, and writing the parser out again. Both are one command:

```bash
php vendor/bin/phplrt check resources/grammar.pp3
php vendor/bin/phplrt compile resources/grammar.pp3 src/Parser.php --class Parser
```

The binary ships with `phplrt/compiler` - and with `phplrt/phplrt`, which
contains it - so composer puts it into `vendor/bin` on install. There is
nothing to configure: it finds your autoloader on its own, and reads no
config file of its own.

## What Is In There

```bash
php vendor/bin/phplrt
```

```
Usage:
  command [options] [arguments]

Available commands:
  check       [validate] Check the passed grammar
  compile     Compile the passed grammar
  completion  Dump the shell completion script
  help        Display help for a command
  list        List commands
```

Two commands do the actual work. `php vendor/bin/phplrt help compile`
describes either of them in full, and `-V` prints the version it runs as.

## Checking A Grammar

`check` reads a grammar - and everything it `%include`s - then compiles it in
memory and throws the result away. Nothing is written anywhere:

```bash
php vendor/bin/phplrt check resources/grammar.pp3
```

```
Checking /app/resources/grammar.pp3 grammar
Loaded 1 grammar files:
  - /app/resources/grammar.pp3

[OK] grammar is valid
```

`validate` is the same command under a different name, for whichever of the
two your fingers prefer.

The listed files are the ones that **declared a rule**, so a grammar split up
with `%include` shows where its rules came from:

```
Checking /app/resources/grammar.pp3 grammar
Loaded 3 grammar files:
  - /app/resources/grammar/literals.pp3
  - /app/resources/grammar/expressions.pp3
  - /app/resources/grammar.pp3
```

A file that only declares tokens is read all the same, but has no rules to
show up with.

The exit code is `0` for a grammar that compiles and `1` for one that does
not, which is the reason the command exists - a broken grammar should fail in
a pull request:

```yaml
- run: php vendor/bin/phplrt check resources/grammar.pp3
```

Anything the compiler will not accept is named:

```
Checking /app/resources/grammar.pp3 grammar

In RuleReferenceResolutionParserCompilerPass.php line 104:

  Rule Sum = Number refers to the rule named "Number", which has not been defined
```

```
Checking /app/resources/grammar.pp3 grammar

In UnexpectedTokenException.php line 21:

  Syntax error, unexpected ";" (T_SEMICOLON), T_ANGLE_CLOSE expected
```

Add `-v` for the exception class and the stack trace behind it.

### The Numbers Behind The Answer

"Valid" is a low bar - a grammar can compile and still not be the grammar you
meant. `-v` prints what the compiler made of it:

```bash
php vendor/bin/phplrt check resources/grammar.pp3 -v
```

```
[OK] grammar is valid

 Reducers:  2
 Lookahead: 4 / 1
 Kept:      2
 Channels:  2
 Subgroups: 0
 PCRE:      100 bytes

 Rules:
   Loaded: 7
   After:  5

 Tokens:
   Loaded: 3
   After:  4
```

| Line        | What it counts                                                        |
|-------------|-----------------------------------------------------------------------|
| `Reducers`  | Rules that build a result - the ones written with `-> { ... }`        |
| `Lookahead` | Entries in the lookahead table / rules it cannot cover (more / less)  |
| `Kept`      | Rules that need tracing at runtime (less is better)                   |
| `Channels`  | Tokens sitting on a channel other than the default one                |
| `Subgroups` | Tokens whose pattern captures a subgroup (less is better)             |
| `PCRE`      | Size of the single regular expression the lexer became                |
| `Rules`     | Rule nodes built from the grammar, and what is left once folded away  |
| `Tokens`    | Tokens declared, and tokens compiled                                  |

Two of those lines are worth reading every time.

**`Tokens` grows by one.** Three tokens were declared and four came out: the
compiler appends a catch-all so that unrecognized input becomes an `Unknown`
token instead of an error. See [Compiling a Grammar](/docs/basics/compiler).

**`Rules` shrinks - and how much it shrinks matters.** Folding transition
rules away is normal. Losing almost everything is not:

```
 Reducers:  1

 Rules:
   Loaded: 7
   After:  1
```

Seven rules in, one rule out, one reducer left of two. The grammar is valid;
it is just wired to the wrong root. Parsing starts at the first rule declared
unless `%pragma root` says otherwise, and an `%include` that declares rules of
its own is easy to leave sitting above the rule you meant to start from -
everything unreachable from the real root is then dropped. Naming the root
brings them back:

```pp3
%pragma root Sum
```

```
 Reducers:  2

 Rules:
   Loaded: 8
   After:  5
```

`-vv` prints the same numbers with a line of explanation above each, if you
would rather not keep the table above at hand:

```
 // Lookahead table / non-optimizable rules size (more / less is better)
 Lookahead: 4 / 1

 // List of rules that requires tracing (less is better)
 Kept:      2
```

## Compiling A Grammar

`compile` writes the parser down as PHP:

```bash
php vendor/bin/phplrt compile resources/grammar.pp3 src/Parser.php
```

```
Loading /app/resources/grammar.pp3 grammar
 [OK] Generated into src/Parser.php
```

Both arguments are required: the grammar to read and the file to write. Any
directory missing on the way is created.

| Option              | Meaning                                                 |
|---------------------|---------------------------------------------------------|
| `-c`, `--class`     | The class name to declare                               |
| `--namespace`       | The namespace to declare it in                          |
| `-u`, `--use`       | A class to import; repeat it for more than one          |

```bash
php vendor/bin/phplrt compile resources/grammar.pp3 src/Parser/SumParser.php \
    --class SumParser \
    --namespace "App\Parser" \
    --use "App\Ast\Node" \
    --use "App\Ast\Number"
```

```php
namespace App\Parser;

use App\Ast\Node;
use App\Ast\Number;

readonly class SumParser extends \Phplrt\Parser\Parser { /* ... */ }
```

```php
$parser = new App\Parser\SumParser();
```

Imports are what makes the PHP inside a grammar readable - `new Node(...)`
instead of `new \App\Ast\Node(...)` - at the price of a grammar that only
works when it is generated. See [Results and Reducers](/docs/basics/reducers).

**Leave `--class` out** and the file returns an anonymous parser instead of
declaring anything:

```bash
php vendor/bin/phplrt compile resources/grammar.pp3 build/parser.php
```

```php
return new readonly class extends \Phplrt\Parser\Parser { /* ... */ };
```

```php
$parser = require __DIR__ . '/../build/parser.php';
```

Use the named form for anything autoloaded, the anonymous one for a build
artifact you do not want in the class namespace at all.
[Compiling a Grammar](/docs/basics/compiler) covers what comes out of either.

## Running It From A Script

The two commands end up in `composer.json` and in CI far more often than they
are typed by hand - [Automation and CI](/docs/advanced/automation) has the
composer scripts, the GitHub Actions and GitLab CI jobs, and what to watch out
for when there is more than one grammar.

## Shell Completion

```bash
php vendor/bin/phplrt completion bash > /etc/bash_completion.d/phplrt
```

`bash`, `zsh` and `fish` are supported - the shell is guessed from `$SHELL`
when the argument is left out. Command and option names complete from there
on.

## When The CLI Is Not Enough

The two commands cover the shape almost every project needs. Everything past
that is a few lines of PHP:

- generating into a format that is not PHP - a token list for a highlighter,
  say - needs a generator of your own;
- importing a class under an alias (`use App\Ast\Node as BaseNode`);
- loading several grammars into one parser;
- getting the code as a string without writing it, to diff or to reformat.

All of it is in [Compiling a Grammar](/docs/basics/compiler), and the compiler
itself is described in [Compiling a Grammar](/docs/basics/compiler).
