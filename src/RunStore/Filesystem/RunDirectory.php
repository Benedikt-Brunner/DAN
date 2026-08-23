<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Filesystem;

use Dan\Harness\RunStore\Artifact\CellId;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Harness\RunStore\Artifact\RunManifest;
use Dan\Lib\Filesystem\Path;
use RuntimeException;

/**
 * On-disk layout of a single run (one DAL implementation):
 *
 *   <root>/manifest.json
 *   <root>/cells/<scenario>--<tier>--<engine-version>.json
 *   <root>/index.sqlite (derived, see SqliteIndexer)
 */
final class RunDirectory
{
    public function __construct(
        public readonly Path $root,
    ) {}

    public function initialize(RunManifest $manifest): void
    {
        $cells = $this->root->join('cells');
        if (!is_dir($cells->toString()) && !mkdir($cells->toString(), 0o777, true) && !is_dir($cells->toString())) {
            throw new RuntimeException(sprintf('Could not create run directory "%s".', $this->root->toString()));
        }
        $this->writeJson(path: $this->root->join('manifest.json'), data: $manifest->toArray());
    }

    public function manifest(): RunManifest
    {
        return RunManifest::fromDecodedArray($this->readJson($this->root->join('manifest.json')));
    }

    public function writeCell(CellId $id, CellResult $result): void
    {
        $this->writeJson(path: $this->cellPath($id), data: $result->toArray());
    }

    public function mergeIntoCell(CellId $id, CellResult $result): void
    {
        $path = $this->cellPath($id);
        if (file_exists($path->toString())) {
            $result = CellResult::fromDecodedArray($this->readJson($path))->merge($result);
        }
        $this->writeJson(path: $path, data: $result->toArray());
    }

    /**
     * @return list<CellResult>
     */
    public function allCells(): array
    {
        $results = [];
        foreach (glob($this->root->join('cells', '*.json')->toString()) ?: [] as $path) {
            $results[] = CellResult::fromDecodedArray($this->readJson(Path::fromString($path)));
        }

        return $results;
    }

    /**
     * @return list<string> cell file names, the natural join key between two runs
     */
    public function cellFileNames(): array
    {
        return array_map(basename(...), glob($this->root->join('cells', '*.json')->toString()) ?: []);
    }

    public function readCellByFileName(string $fileName): CellResult
    {
        return CellResult::fromDecodedArray($this->readJson($this->root->join('cells', $fileName)));
    }

    public function indexPath(): Path
    {
        return $this->root->join('index.sqlite');
    }

    private function cellPath(CellId $id): Path
    {
        return $this->root->join('cells', $id->fileName());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(Path $path, array $data): void
    {
        file_put_contents($path->toString(), json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * @return array<mixed>
     */
    private function readJson(Path $path): array
    {
        $decoded = json_decode((string) file_get_contents($path->toString()), true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Malformed artifact "%s": expected a JSON object.', $path->toString()));
        }

        return $decoded;
    }
}
