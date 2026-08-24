# Automation and CI

Rebuilding the parser after a grammar change is the kind of step that works
right up until the day somebody forgets. Two composer scripts and a CI job
turn it from a habit into something the build enforces.

## Fitting It Into A Project

The usual layout:

```
resources/grammar/
    grammar.pp3
    lexemes.pp3
src/Parser/
    LanguageParser.php   <- generated, committed
```

The command is short enough to type and long enough to get wrong, so it
usually ends up in `composer.json`:

```json
{
    "scripts": {
        "grammar:check": "phplrt check resources/grammar.pp3",
        "grammar:build": "phplrt compile resources/grammar.pp3 src/Parser/LanguageParser.php --class LanguageParser --namespace \"App\\\\Parser\""
    }
}
```

```bash
composer grammar:build
```

Composer puts `vendor/bin` on the path for its own scripts, so `phplrt` needs
no prefix there.

Run it whenever the grammar changes, and commit the result. Two reasons to
commit rather than generate on deploy: the file is what static analysis and
your IDE actually see, and a broken grammar fails in a pull request instead of
in production.

## Checking It In CI

Two things are worth automating, and both are exit codes:

```bash
# 1. does the grammar still compile?
php vendor/bin/phplrt check resources/grammar.pp3

# 2. does the committed parser still match the grammar?
php vendor/bin/phplrt compile resources/grammar.pp3 src/Parser/LanguageParser.php \
    --class LanguageParser --namespace "App\Parser"
git diff --exit-code -- src/Parser/LanguageParser.php
```

The first fails on a grammar that no longer compiles. The second regenerates
the parser and fails if the result differs from what is in the repository -
the day someone edits the grammar and forgets to rebuild.

Run `check` with `-v`: the numbers land in the build log, so a reviewer can
see the rule and token counts move in a pull request instead of guessing what
a grammar change did. [Command Line](/docs/basics/cli) explains what they
mean.

### GitHub Actions

```yaml
name: Grammar

on:
  push:
    paths: [ 'resources/**', 'src/Parser/**' ]
  pull_request:
    paths: [ 'resources/**', 'src/Parser/**' ]

jobs:
  grammar:
    name: Grammar
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v7
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - name: Install Dependencies
        run: composer install --prefer-dist --no-interaction --no-progress
      - name: Check The Grammar
        run: php vendor/bin/phplrt check resources/grammar.pp3 -v
      - name: Check The Parser Is Not Stale
        run: |
          php vendor/bin/phplrt compile resources/grammar.pp3 src/Parser/LanguageParser.php \
              --class LanguageParser --namespace "App\Parser"
          git diff --exit-code -- src/Parser/LanguageParser.php
```

The `paths` filter keeps the job off every unrelated commit. Drop the last
step if the parser is built on deploy rather than committed.

### GitLab CI

```yaml
grammar:
  image: php:8.4-cli
  rules:
    - changes:
        - 'resources/**/*'
        - 'src/Parser/**/*'
  before_script:
    - apt-get update -yqq && apt-get install -yqq git unzip
    - curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    - composer install --prefer-dist --no-interaction --no-progress
  script:
    - php vendor/bin/phplrt check resources/grammar.pp3 -v
    - >
      php vendor/bin/phplrt compile resources/grammar.pp3 src/Parser/LanguageParser.php
      --class LanguageParser --namespace "App\Parser"
    - git diff --exit-code -- src/Parser/LanguageParser.php
```

`git` and `unzip` are what composer needs and the bare `php` image does not
have. `git diff` needs the checkout to be a repository, which the default
`GIT_STRATEGY` gives you - a job running with `GIT_STRATEGY: none` can still
run `check`, but not the staleness step.

### More Than One Grammar

The obvious loop is a trap:

```bash
# WRONG - find exits 0 even when a grammar fails to compile
find resources -name '*.pp3' -exec php vendor/bin/phplrt check {} \;
```

`xargs` reports the failure instead, which is what CI reads:

```bash
find resources -name '*.pp3' -print0 \
    | xargs -0 -n1 php vendor/bin/phplrt check
```

## Doing It From PHP

A project that generates through the API rather than the binary - a generator
of its own, several grammars in one parser - runs a script instead:

```
bin/
    build-parser.php     <- runs the generator
```

```json
{
    "scripts": {
        "grammar:build": "php bin/build-parser.php"
    }
}
```

The staleness check has a PHP form too, since the output is `Stringable`:

```php
$expected = (string) $output;
$actual = \file_get_contents(__DIR__ . '/LanguageParser.php');

if ($expected !== $actual) {
    throw new \RuntimeException('Parser is out of date, run "composer grammar:build"');
}
```

[Code Generation](/docs/basics/generation) covers the API this leans on.
