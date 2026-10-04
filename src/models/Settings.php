<?php

namespace justinholtweb\joan\models;

use craft\base\Model;

/**
 * Joan's settings.
 *
 * Joan reads; it never writes. So there's nothing here about what it's allowed to change —
 * only about how hard it should look, and how long it's allowed to remember what it found.
 */
class Settings extends Model
{
    /**
     * @var bool Whether content usage is counted at all.
     *
     * Counting means one aggregate query per field per storage strategy. That's fine on
     * most sites and slow on a few very large ones, so it can be turned off — the layout
     * and code halves of the inventory still work without it.
     */
    public bool $countContent = true;

    /**
     * @var bool Whether drafts, revisions and soft-deleted elements count as usage.
     *
     * Off by default, and that default matters: a site with 50 revisions per entry will
     * report every abandoned field as "in use" if you leave revisions in.
     */
    public bool $includeDrafts = false;

    /**
     * @var bool Whether the codebase is scanned for field handles.
     */
    public bool $scanCode = true;

    /**
     * @var string[] Paths scanned for handle references, relative to the project root.
     *               Empty entries are ignored; missing directories are skipped quietly.
     */
    public array $scanPaths = ['templates', 'modules', 'config'];

    /**
     * @var string[] File extensions the code scan reads.
     */
    public array $scanExtensions = ['twig', 'html', 'php', 'js', 'vue'];

    /**
     * @var string[] Directory names never descended into, at any depth.
     */
    public array $scanExclude = ['vendor', 'node_modules', '.git', 'storage', 'cpresources'];

    /**
     * @var int Largest file the scanner will read, in kilobytes. A 4MB minified bundle
     *          contributes nothing but time.
     */
    public int $maxFileSize = 512;

    /**
     * @var int Most files the scanner will read in one pass. 0 for no limit.
     */
    public int $maxFiles = 5000;

    /**
     * @var int References kept per field. The count is always exact; this caps the list.
     */
    public int $maxRefsPerField = 50;

    /**
     * @var int Seconds a built inventory is cached for.
     *
     * The cache key also carries Craft's field version, so any change to a field or a
     * layout invalidates it immediately regardless of this. The timer is only there to
     * catch up with *content* changes, which don't move the field version.
     */
    public int $cacheDuration = 900;

    /**
     * @var bool Whether fields belonging to plugin contexts (Formie's, say) are included.
     *
     * Off by default. They're real fields, but they're the plugin's business, and a
     * hundred of them buries the twenty that are yours.
     */
    public bool $includePluginContexts = false;

    /**
     * @var string[] Field handles Joan never flags, whatever it finds. For the field you
     *               know is only ever populated by an import script.
     */
    public array $ignoredFields = [];

    /**
     * @var string Log verbosity. One of: error, warning, info, debug.
     */
    public string $logLevel = 'info';

    public function rules(): array
    {
        // Nothing is `required`: a failing required rule invalidates the whole settings
        // model, which blocks saving any setting at all — including on a fresh install.
        return [
            [['countContent', 'includeDrafts', 'scanCode', 'includePluginContexts'], 'boolean'],
            [['maxFileSize'], 'integer', 'min' => 1, 'max' => 4096],
            [['maxRefsPerField'], 'integer', 'min' => 1],
            [['maxFiles'], 'integer', 'min' => 0, 'max' => 100000],
            [['cacheDuration'], 'integer', 'min' => 0],
            [['logLevel'], 'in', 'range' => ['error', 'warning', 'info', 'debug']],
            [['scanPaths', 'scanExtensions', 'scanExclude', 'ignoredFields'], 'each', 'rule' => ['string']],
        ];
    }

    /**
     * The scan paths, cleaned of the blanks an admin's textarea leaves behind.
     *
     * @return string[]
     */
    public function normalizedScanPaths(): array
    {
        return array_values(array_filter(array_map('trim', $this->scanPaths), fn(string $p) => $p !== ''));
    }

    /**
     * @return string[]
     */
    public function normalizedExtensions(): array
    {
        return array_values(array_filter(array_map(
            fn(string $ext) => strtolower(ltrim(trim($ext), '.')),
            $this->scanExtensions,
        ), fn(string $ext) => $ext !== ''));
    }
}
