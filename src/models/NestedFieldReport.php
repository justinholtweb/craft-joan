<?php

namespace justinholtweb\joan\models;

use craft\base\Model;

/**
 * A field that contains nested elements — Matrix, and CKEditor when it nests entries.
 *
 * The question this answers is the one nobody can answer from the control panel: of the
 * eight block types this Matrix field allows, which ones has anyone ever actually used?
 */
class NestedFieldReport extends Model
{
    public int $id = 0;
    public string $uid = '';
    public string $name = '';
    public string $handle = '';
    public string $type = '';
    public string $typeName = '';
    public ?string $cpEditUrl = null;

    /**
     * @var array<int, array{
     *     id: int,
     *     name: string,
     *     handle: string,
     *     entries: int,
     *     url: string|null,
     * }> Allowed entry types, with how many blocks of each exist.
     */
    public array $entryTypes = [];

    /** @var int Total nested elements this field owns. */
    public int $totalBlocks = 0;

    /** @var int Owners — elements with at least one block in this field. */
    public int $owners = 0;

    /**
     * Entry types this field allows that nobody has ever used.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnusedEntryTypes(): array
    {
        return array_values(array_filter($this->entryTypes, fn(array $t) => $t['entries'] === 0));
    }

    public function getAverageBlocksPerOwner(): float
    {
        return $this->owners > 0 ? round($this->totalBlocks / $this->owners, 1) : 0.0;
    }
}
