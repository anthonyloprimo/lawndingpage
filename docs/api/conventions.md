# PHPDoc conventions

These conventions govern PHPDoc usage across the codebase. The goal is
twofold: give the PHPStan analyzer enough type information to catch
array-shape drift before it merges (the bug class that originally
motivated this file), and give the future `phpDocumentor`-generated
API site enough description to render a useful reference.

PHPDoc is voluntary in PHP, but in this project it is **load-bearing**
once you start writing it: PHPStan reads your shape annotations and
will report mismatches as errors. The analyzer level is ratcheted up
one PR at a time as helpers gain docblocks — see "Running the
analyzer" at the bottom of this file.

## When to add a docblock

Add one when the function's parameters or return value carry
information the signature alone can't express. In practice that means:

- The parameter or return is an **associative array** with a known
  shape — name the keys, name their types.
- The parameter or return is a **list** (`0,1,2,…`-indexed array) of
  records — name the record shape.
- The function has a non-obvious **failure mode** that callers must
  handle (returns `null` on a specific kind of malformed input,
  returns `false` when a file lock can't be acquired, etc.) — name the
  failure mode.
- The function is `array → array` (validator, normalizer, payload
  builder) and the relationship between input and output isn't
  obvious from the body.

## When NOT to add one

Skip the docblock when the signature already says everything. A leaf
utility like `lawnding_norm_path(string $path, bool $trim = true):
string` does not need a docblock — its signature is exhaustive. Adding
one that paraphrases the signature is noise.

A useful rule of thumb: would a reader stepping through this function
in their editor learn anything new from the docblock that isn't
already on the line above? If no, skip it.

## Voice

Match the rest of the codebase. The same rules that apply to inline
comments apply to docblocks:

- Open with a tight one-line summary. Two or three lines is fine when
  the function has a real invariant; a paragraph is rare.
- Name failure modes by their externally-visible effect, not their
  internal cause. "Silent-drop on per-record validation failure"
  beats "returns array without the bad rows."
- Don't restate identifier names. The reader already has the function
  name and parameter names from the code itself.
- No decorative banners, doc-block boilerplate, or `@author` /
  `@since` / `@version` tags. The version is in version control.

## Shape-type syntax

The shape-type syntax we use is the PHPStan-flavor extension of PSR-5:

| Syntax | Meaning |
|---|---|
| `array{id: string, name: string}` | Associative array with exactly these keys and types |
| `array{id: string, label?: string}` | The `?` after a key marks it optional |
| `list<string>` | Numerically-indexed array (`0,1,2,…`) of strings |
| `array<string, mixed>` | Generic associative array, keys are strings, values are anything |
| `non-empty-list<int>` | Same as `list<int>` but guaranteed to have at least one entry |
| `array{id: string}\|null` | Nullable — either the shape or `null` |
| `array{...}` (with `...`) | Open-ended shape — declared keys plus arbitrary others |

For closed shapes (every key listed), don't include `...`. For open
shapes (callers may add their own keys), do.

## Examples

A real pure-normalizer from the codebase. Notice the summary line
names the defensive behavior, the `@param` carries the input shape,
and the `@return` carries the cleaned record shape:

```php
/**
 * Pure normalizer: takes a decoded JSON array shape and returns the
 * cleaned categories list. Drops malformed rows (defensive against
 * hand-edited files). Extracted from event_list_load_categories so
 * the shape-tolerance behavior is unit-testable without filesystem I/O.
 *
 * @param array<string, mixed> $decoded
 * @return list<array{id: string, name: string, color: string}>
 */
function event_list_normalize_categories(array $decoded): array { ... }
```

A pure validator (boolean return, single-shape parameter). The
`@param` carries the input shape; the `@return` is omitted because
`bool` is fully expressed by the signature:

```php
/**
 * Name non-empty after trim, color a strict 6-digit hex. Pure — no I/O.
 *
 * @param array<string, mixed> $category
 */
function event_list_category_is_valid(array $category): bool { ... }
```

A pure transform with structured `$payload` (the manifest's
`save_map.validator` contract). Notice the deeply-nested optional
keys on the payload shape:

```php
/**
 * Pure-transform additive merge for the categories changeset, mirror
 * of event_list_apply_events. Silent-drop on per-record validation
 * failure. Apply order is delete → update → create so id collisions
 * can't occur when an admin deletes-then-recreates inside the same
 * payload.
 *
 * @param array{categories?: mixed} $existing
 * @param array{changes?: array{delete?: list<scalar>, update?: list<array<string, mixed>>, create?: list<array<string, mixed>>}} $payload
 * @return array{categories: list<array{id: string, name: string, color: string}>}
 */
function event_list_apply_categories(array $existing, array $payload): array { ... }
```

## phpDocumentor compatibility

PHPStan-flavor shape types are an extension of PSR-5 and are
understood by `phpDocumentor` 3.6+. If a specific shape renders poorly
in the generated API site, use the dual-annotation escape hatch:
write a phpDocumentor-friendly form on the official tag, and a
PHPStan-specific form on its `@phpstan-*` companion:

```php
/**
 * @return array<string, mixed>                                      // phpDocumentor reads this
 * @phpstan-return array{id: string, label: string, icon: string}    // PHPStan reads this
 */
```

Prefer the single-annotation form. Reach for the dual form only when
the rendered HTML actually looks worse than the alternatives.

## Running the analyzer

```bash
bash tools/install-phpstan.sh        # one-time, idempotent
php -d zend.assertions=1 tests/run.php
```

PHPStan runs as Phase 4 of the test suite. The pre-commit hook runs
the same command, so analyzer findings block commits.

If `tools/phpstan.phar` is missing the runner fails loudly with the
install command in the failure message. Install the tool — don't
reshape the runner to dodge the gap.

The analyzer level lives at the top of `phpstan.neon` at the repo
root. The level is ratcheted up one PR at a time, never partway
through. The cadence is: add docblocks to a tier of helpers, run the
analyzer at the current level (must pass), raise the level by one or
two, address every new finding, commit, ship.

**Source-level `@phpstan-ignore` comments are forbidden.** PHPStan
exists to report a bug class that's easy to miss in review —
silencing findings in source files where they're hardest to track
defeats the purpose.

**Analyzer-config exceptions are allowed**, narrowly. When PHPStan
hits a genuine analyzer limitation (cross-iteration loop-state
propagation, third-party stubs missing a method, etc.) the right move
is an entry in `phpstan.neon`'s `ignoreErrors:` block. Each entry
must (a) be scoped to a specific message + path, (b) carry a comment
explaining the analyzer behavior, and (c) point at a regression test
that proves the runtime behavior is actually correct. If you can't
write that test, the finding probably isn't a false positive —
restructure the code instead.

Current level: see `phpstan.neon`. Raise it only after the codebase
passes cleanly at the new level.

## Generated API docs

A browsable HTML reference is generated from this codebase's PHPDoc
on every push to `main` by `.github/workflows/build-docs.yml`, using
`phpDocumentor` v3 (also distributed as a PHAR — same pattern as
PHPStan). Output lives at the GitHub Pages URL for this repo.

To preview locally:

```bash
bash tools/install-phpdocumentor.sh         # one-time, idempotent
php tools/phpDocumentor.phar                # generates build/docs/
```

Then open `build/docs/index.html` in a browser. `build/` is
gitignored — the artifact lives only in CI's deploy step.

phpDocumentor v3.9+ understands PHPStan-flavor shape types
(`array{...}`, `list<T>`, etc.) natively, so the same annotation
serves both the analyzer and the rendered docs. The dual-annotation
escape hatch documented above stays as a fallback for the rare case
where a specific shape doesn't render usefully.
