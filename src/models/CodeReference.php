<?php

namespace justinholtweb\joan\models;

use craft\base\Model;

/**
 * One line in the codebase that names a field handle.
 *
 * Joan reports these rather than judging them. A hit inside a comment and a hit inside
 * `entry.myField.one()` look the same to a scanner, and the person deciding whether to
 * delete a field is much better at telling them apart than a regular expression is.
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class CodeReference extends Model
{
    // Const Properties
    // =========================================================================

    /** `entry.handle`, `element.handle` — almost certainly a real use. */
    public const CONTEXT_PROPERTY = 'property';

    /** `'handle'` or `"handle"` — a query param, an array key, a `fields()` argument. */
    public const CONTEXT_QUOTED = 'quoted';

    /** The handle appears, but not in a shape that suggests a field access. */
    public const CONTEXT_MENTION = 'mention';

    // Public Properties
    // =========================================================================

    /** @var string Path relative to the scanned root. */
    public string $path = '';

    public int $line = 0;

    /** @var string The line itself, trimmed and length-capped. */
    public string $snippet = '';

    public string $context = self::CONTEXT_MENTION;

    /** @var string The handle that matched — a field can answer to several. */
    public string $handle = '';

    // Public Methods
    // =========================================================================

    /**
     * Whether this reference is strong enough to argue against deletion on its own.
     */
    public function isStrong(): bool
    {
        return $this->context !== self::CONTEXT_MENTION;
    }
}
