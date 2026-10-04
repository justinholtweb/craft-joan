<?php

namespace justinholtweb\joan\services;

use Craft;
use FilesystemIterator;
use justinholtweb\joan\events\RegisterScanPathsEvent;
use justinholtweb\joan\models\CodeReference;
use justinholtweb\joan\models\Settings;
use justinholtweb\joan\Plugin;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use yii\base\Component;

/**
 * Finds the places your codebase names a field handle.
 *
 * This is the half of "is it safe to delete?" that the database can't answer. A field can
 * be in no layout and hold no content and still be referenced by a template that populates
 * it on save, by a module, or by a migration. Craft's field settings screen doesn't look,
 * and the standard advice — "grep for it first" — is exactly right and exactly what nobody
 * does before clicking Delete.
 *
 * The scan is deliberately one pass over the files rather than one pass per field: a site
 * with eighty fields and two thousand templates would otherwise read 160,000 files. Every
 * word-shaped token in every scanned file is matched against the set of known handles, and
 * the surrounding characters decide how much the hit is worth.
 *
 * Joan reports what it found and classifies it. It doesn't decide. `entry.body` in a Twig
 * template and the word `body` in a comment look identical to a regular expression, and
 * the person about to delete a field is much better at telling them apart.
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class CodeScan extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @event RegisterScanPathsEvent Fired when the scanner works out which directories to read.
     */
    public const EVENT_REGISTER_SCAN_PATHS = 'registerScanPaths';

    /**
     * Handles too common to be evidence on their own.
     *
     * A `grep` for `title` in a templates directory finds hundreds of lines and proves
     * nothing. Joan still records the hits — it just says so.
     */
    private const AMBIGUOUS_HANDLES = [
        'title', 'body', 'text', 'name', 'url', 'link', 'image', 'images', 'content',
        'description', 'summary', 'caption', 'label', 'date', 'type', 'status', 'icon',
        'color', 'size', 'price', 'value', 'id', 'slug', 'author', 'video', 'photo',
        'address', 'email', 'phone', 'width', 'height', 'position', 'order', 'file',
    ];

    // Private Properties
    // =========================================================================

    /** @var array{files: int, bytes: int, truncated: bool, ran: bool, runtime: float, roots: string[]} */
    private array $_stats = [
        'files' => 0,
        'bytes' => 0,
        'truncated' => false,
        'ran' => false,
        'runtime' => 0.0,
        'roots' => [],
    ];

    /** @var array<string, int> Field UID => total references found, including the ones past the cap. */
    private array $_totals = [];

    // Public Methods
    // =========================================================================

    /**
     * Scans the configured paths for every given handle in one pass.
     *
     * @param array<string, string[]> $handles Handle => field UIDs answering to it.
     * @return array<string, CodeReference[]> Field UID => references, capped per field.
     */
    public function scan(array $handles): array
    {
        $settings = $this->_settings();

        if (!$settings->scanCode || $handles === []) {
            return [];
        }

        $started = microtime(true);
        $this->_stats['ran'] = true;
        $roots = $this->roots();
        $this->_stats['roots'] = $roots;

        /** @var array<string, CodeReference[]> $refs */
        $refs = [];
        /** @var array<string, int> $counts */
        $counts = [];
        $maxRefs = max(1, $settings->maxRefsPerField);
        $maxFiles = $settings->maxFiles;
        $maxBytes = max(1, $settings->maxFileSize) * 1024;

        foreach ($roots as $root) {
            foreach ($this->_files($root, $settings) as $file) {
                if ($maxFiles > 0 && $this->_stats['files'] >= $maxFiles) {
                    $this->_stats['truncated'] = true;
                    break 2;
                }

                if ($file->getSize() > $maxBytes) {
                    continue;
                }

                $contents = @file_get_contents($file->getPathname());

                if ($contents === false) {
                    continue;
                }

                $this->_stats['files']++;
                $this->_stats['bytes'] += strlen($contents);
                $relative = $this->_relativePath($file->getPathname());

                foreach ($this->_matches($contents, $handles) as $match) {
                    foreach ($match['fieldUids'] as $fieldUid) {
                        $counts[$fieldUid] = ($counts[$fieldUid] ?? 0) + 1;

                        if (count($refs[$fieldUid] ?? []) >= $maxRefs) {
                            continue;
                        }

                        $refs[$fieldUid][] = new CodeReference([
                            'path' => $relative,
                            'line' => $match['line'],
                            'snippet' => $match['snippet'],
                            'context' => $match['context'],
                            'handle' => $match['handle'],
                        ]);
                    }
                }
            }
        }

        // Strong references first: a `.handle` hit is what someone actually wants to see.
        foreach ($refs as &$fieldRefs) {
            usort($fieldRefs, fn(CodeReference $a, CodeReference $b) => [!$a->isStrong(), $a->path, $a->line] <=> [!$b->isStrong(), $b->path, $b->line]);
        }
        unset($fieldRefs);

        $this->_stats['runtime'] = round(microtime(true) - $started, 3);
        $this->_totals = $counts;

        return $refs;
    }

    /**
     * References found per field in the last scan, before any per-field cap.
     *
     * @return array<string, int>
     */
    public function totals(): array
    {
        return $this->_totals;
    }

    /**
     * What the last scan read, and whether it ran at all.
     *
     * @return array{files: int, bytes: int, truncated: bool, ran: bool, runtime: float, roots: string[]}
     */
    public function stats(): array
    {
        return $this->_stats;
    }

    /**
     * Whether a handle is a word common enough that matches on it mean little.
     */
    public static function isAmbiguousHandle(string $handle): bool
    {
        return in_array(strtolower($handle), self::AMBIGUOUS_HANDLES, true);
    }

    /**
     * The absolute directories the scan will read.
     *
     * @return string[]
     */
    public function roots(): array
    {
        $base = rtrim(Craft::getAlias('@root') ?: dirname(Craft::$app->getPath()->getConfigPath()), '/');
        $realBase = realpath($base);
        $roots = [];

        foreach ($this->_settings()->normalizedScanPaths() as $path) {
            $absolute = $this->_isAbsolute($path) ? $path : $base . '/' . ltrim($path, '/');
            $absolute = realpath(rtrim($absolute, '/'));

            // Configured paths stay inside the project: snippets from whatever is scanned are
            // shown in the control panel, so `../` or `/etc` must not reach past the site.
            // Code elsewhere can still be added deliberately via EVENT_REGISTER_SCAN_PATHS.
            if ($absolute === false || $realBase === false || !is_dir($absolute)) {
                continue;
            }

            if ($absolute !== $realBase && !str_starts_with($absolute, $realBase . DIRECTORY_SEPARATOR)) {
                Craft::warning("Skipping scan path outside the project root: $path", Plugin::LOG_CATEGORY);
                continue;
            }

            $roots[] = $absolute;
        }

        // Nothing configured resolved to a real directory — fall back to wherever Craft
        // says the site's templates are, which is the one path that always exists.
        if ($roots === []) {
            $templates = rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/');

            if (is_dir($templates)) {
                $roots[] = $templates;
            }
        }

        if ($this->hasEventHandlers(self::EVENT_REGISTER_SCAN_PATHS)) {
            $event = new RegisterScanPathsEvent(['paths' => $roots]);
            $this->trigger(self::EVENT_REGISTER_SCAN_PATHS, $event);
            $roots = array_filter($event->paths, fn(string $path) => is_dir($path));
        }

        return array_values(array_unique($roots));
    }

    // Private Methods
    // =========================================================================

    /**
     * The readable files under a root, skipping excluded directories at any depth.
     *
     * @return iterable<SplFileInfo>
     */
    private function _files(string $root, Settings $settings): iterable
    {
        $extensions = $settings->normalizedExtensions();
        $excluded = array_map('strtolower', $settings->scanExclude);

        try {
            $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
            $filtered = new RecursiveCallbackFilterIterator($directory, function(SplFileInfo $file) use ($excluded) {
                if ($file->isDir()) {
                    return !in_array(strtolower($file->getFilename()), $excluded, true);
                }

                return true;
            });
            $iterator = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::LEAVES_ONLY);
        } catch (Throwable $e) {
            Craft::warning("Could not read $root: " . $e->getMessage(), Plugin::LOG_CATEGORY);
            return;
        }

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            // Symlinks can point anywhere, and dotfiles are where `.env` and its kin live —
            // neither is ever a template or module worth reading.
            if ($file->isLink() || !$file->isFile() || str_starts_with($file->getFilename(), '.')) {
                continue;
            }

            if ($extensions !== [] && !in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * Every handle occurrence in one file's contents.
     *
     * @param array<string, string[]> $handles
     * @return array<int, array{handle: string, fieldUids: string[], line: int, snippet: string, context: string}>
     */
    private function _matches(string $contents, array $handles): array
    {
        $found = [];

        // One cheap pass first. Most files mention none of the handles, and tokenizing a
        // file that can't match is the bulk of the work in a big templates directory.
        if (!$this->_couldMatch($contents, $handles)) {
            return $found;
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        foreach ($lines as $index => $line) {
            if ($line === '' || !preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $line, $tokens, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($tokens[0] as [$token, $offset]) {
                if (!isset($handles[$token])) {
                    continue;
                }

                $found[] = [
                    'handle' => $token,
                    'fieldUids' => $handles[$token],
                    'line' => $index + 1,
                    'snippet' => $this->_snippet($line),
                    'context' => $this->_classify($line, $offset, strlen($token)),
                ];
            }
        }

        return $found;
    }

    /**
     * A cheap substring check, so most files never get tokenized.
     *
     * @param array<string, string[]> $handles
     */
    private function _couldMatch(string $contents, array $handles): bool
    {
        foreach (array_keys($handles) as $handle) {
            if (str_contains($contents, $handle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decides how much a hit is worth from the characters either side of it.
     */
    private function _classify(string $line, int $offset, int $length): string
    {
        $before = $offset > 0 ? $line[$offset - 1] : '';
        $after = $line[$offset + $length] ?? '';

        if ($before === '.' || ($before === '>' && ($line[$offset - 2] ?? '') === '-')) {
            return CodeReference::CONTEXT_PROPERTY;
        }

        if (($before === "'" && $after === "'") || ($before === '"' && $after === '"')) {
            return CodeReference::CONTEXT_QUOTED;
        }

        return CodeReference::CONTEXT_MENTION;
    }

    /**
     * The matching line, trimmed and capped at 200 characters.
     */
    private function _snippet(string $line): string
    {
        $trimmed = trim($line);

        return mb_strlen($trimmed) > 200 ? mb_substr($trimmed, 0, 197) . '…' : $trimmed;
    }

    /**
     * A path relative to the project root, where it is under it.
     */
    private function _relativePath(string $path): string
    {
        $base = rtrim(Craft::getAlias('@root') ?: '', '/');

        if ($base !== '' && str_starts_with($path, $base . '/')) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }

    /**
     * Whether a configured path is absolute, Windows drive letters included.
     */
    private function _isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || (bool)preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    /**
     * Joan's settings, typed.
     */
    private function _settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
