<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use VonNeumannGame\Sector\SectorDetachedContainer;

final class DetachedContainerJsonAuditService
{
    private const COLLECTIONS = [
        'detachedContainers',
        'hiddenDetachedContainers',
        'planetDroppedContainers',
    ];

    private const MODES = [
        SectorDetachedContainer::MODE_DRIFTING,
        SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID,
        SectorDetachedContainer::MODE_DROPPED_ON_PLANET,
    ];

    /**
     * @return array{
     *   universePath:string,
     *   filesScanned:int,
     *   containersScanned:int,
     *   findings:list<array{code:string,message:string,file:string,collection:?string,index:?int,containerId:?string}>,
     *   summary:array<string,int>
     * }
     */
    public function audit(string $universePath): array
    {
        $sectorDirectory = rtrim($universePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';
        if (!is_dir($sectorDirectory)) {
            throw new \RuntimeException("Sector directory not found: {$sectorDirectory}");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sectorDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'json') {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);

        $findings = [];
        $occurrencesById = [];
        $idsBySourceContainerUid = [];
        $filesScanned = 0;
        $containersScanned = 0;

        foreach ($files as $file) {
            $filesScanned++;
            $data = $this->decodeFile($file, $findings);
            if ($data === null) {
                continue;
            }

            $regularObjectIds = $this->regularObjectIds($data['objects'] ?? []);
            foreach ($this->containerEntries($data, $file, $findings) as $entry) {
                $containersScanned++;
                $container = $entry['container'];
                $id = trim((string) ($container['id'] ?? ''));
                $entry['containerId'] = $id !== '' ? $id : null;

                $this->validateContainer($container, $entry, $findings);
                if ($id === '') {
                    continue;
                }

                if (isset($regularObjectIds[$id])) {
                    $this->addFinding(
                        $findings,
                        'object_id_collision',
                        "Detached container id '{$id}' is also used by a regular sector object.",
                        $entry,
                    );
                }

                $fingerprint = hash('sha256', $this->canonicalJson($container));
                $occurrencesById[$id][] = $entry + ['fingerprint' => $fingerprint];

                $payload = is_array($container['payload'] ?? null) ? $container['payload'] : [];
                $sourceUid = trim((string) ($payload['sourceContainerId'] ?? ''));
                if ($sourceUid !== '') {
                    $idsBySourceContainerUid[$sourceUid][$id] = true;
                }
            }
        }

        foreach ($occurrencesById as $id => $occurrences) {
            if (count($occurrences) < 2) {
                continue;
            }
            $fingerprints = array_values(array_unique(array_column($occurrences, 'fingerprint')));
            $locations = implode(', ', array_map(
                static fn(array $occurrence): string => $occurrence['file']
                    . ':' . $occurrence['collection']
                    . '[' . $occurrence['index'] . ']',
                $occurrences,
            ));
            $first = $occurrences[0];
            if (count($fingerprints) === 1) {
                $this->addFinding(
                    $findings,
                    'duplicate_container_id',
                    "Container id '{$id}' is duplicated at {$locations}.",
                    $first,
                );
                continue;
            }
            $this->addFinding(
                $findings,
                'container_id_collision',
                "Container id '{$id}' refers to different payloads at {$locations}.",
                $first,
            );
        }

        foreach ($idsBySourceContainerUid as $sourceUid => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $containerIds = array_keys($ids);
            sort($containerIds, SORT_STRING);
            $this->addFinding(
                $findings,
                'source_container_uid_collision',
                "Source container uid '{$sourceUid}' is referenced by several detached ids: " . implode(', ', $containerIds) . '.',
                ['file' => '', 'collection' => null, 'index' => null, 'containerId' => null],
            );
        }

        usort($findings, static fn(array $a, array $b): int => [
            $a['file'],
            $a['collection'] ?? '',
            $a['index'] ?? -1,
            $a['code'],
            $a['message'],
        ] <=> [
            $b['file'],
            $b['collection'] ?? '',
            $b['index'] ?? -1,
            $b['code'],
            $b['message'],
        ]);

        $summary = [];
        foreach ($findings as $finding) {
            $summary[$finding['code']] = ($summary[$finding['code']] ?? 0) + 1;
        }
        ksort($summary, SORT_STRING);

        return [
            'universePath' => $universePath,
            'filesScanned' => $filesScanned,
            'containersScanned' => $containersScanned,
            'findings' => $findings,
            'summary' => $summary,
        ];
    }

    /**
     * @param list<array{code:string,message:string,file:string,collection:?string,index:?int,containerId:?string}> $findings
     * @return array<string, mixed>|null
     */
    private function decodeFile(string $file, array &$findings): ?array
    {
        $json = @file_get_contents($file);
        if ($json === false) {
            $this->addFinding($findings, 'unreadable_file', 'Unable to read sector JSON file.', [
                'file' => $file,
                'collection' => null,
                'index' => null,
                'containerId' => null,
            ]);
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->addFinding($findings, 'invalid_json', 'Invalid JSON: ' . $e->getMessage(), [
                'file' => $file,
                'collection' => null,
                'index' => null,
                'containerId' => null,
            ]);
            return null;
        }
        if (!is_array($data)) {
            $this->addFinding($findings, 'invalid_json_root', 'JSON root must be an object.', [
                'file' => $file,
                'collection' => null,
                'index' => null,
                'containerId' => null,
            ]);
            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{code:string,message:string,file:string,collection:?string,index:?int,containerId:?string}> $findings
     * @return list<array{container:array<string,mixed>,file:string,collection:string,index:int,containerId:?string}>
     */
    private function containerEntries(array $data, string $file, array &$findings): array
    {
        $entries = [];
        foreach (self::COLLECTIONS as $collection) {
            $values = $data[$collection] ?? [];
            if (!is_array($values)) {
                $this->addFinding($findings, 'invalid_container_collection', "'{$collection}' must be an array.", [
                    'file' => $file,
                    'collection' => $collection,
                    'index' => null,
                    'containerId' => null,
                ]);
                continue;
            }
            foreach (array_values($values) as $index => $container) {
                if (!is_array($container)) {
                    $this->addFinding($findings, 'invalid_container_entry', 'Detached container entry must be an object.', [
                        'file' => $file,
                        'collection' => $collection,
                        'index' => $index,
                        'containerId' => null,
                    ]);
                    continue;
                }
                $entries[] = [
                    'container' => $container,
                    'file' => $file,
                    'collection' => $collection,
                    'index' => $index,
                    'containerId' => null,
                ];
            }
        }

        $objects = $data['objects'] ?? [];
        if (is_array($objects)) {
            foreach (array_values($objects) as $index => $object) {
                if (is_array($object) && ($object['type'] ?? null) === 'detached_container') {
                    $entries[] = [
                        'container' => $object,
                        'file' => $file,
                        'collection' => 'objects',
                        'index' => $index,
                        'containerId' => null,
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * @param mixed $objects
     * @return array<string, true>
     */
    private function regularObjectIds(mixed $objects): array
    {
        if (!is_array($objects)) {
            return [];
        }
        $ids = [];
        foreach ($objects as $object) {
            if (!is_array($object) || ($object['type'] ?? null) === 'detached_container') {
                continue;
            }
            $id = trim((string) ($object['id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $container
     * @param array{file:string,collection:string,index:int,containerId:?string} $entry
     * @param list<array{code:string,message:string,file:string,collection:?string,index:?int,containerId:?string}> $findings
     */
    private function validateContainer(array $container, array $entry, array &$findings): void
    {
        $requiredScalarFields = ['id', 'mode', 'ownerProbeId', 'ownerPlayerId', 'capacity', 'capacityUnit', 'createdAt'];
        foreach ($requiredScalarFields as $field) {
            if (!array_key_exists($field, $container) || $container[$field] === null || $container[$field] === '') {
                $this->addFinding($findings, 'incomplete_container', "Missing detached container field '{$field}'.", $entry);
            }
        }

        $mode = (string) ($container['mode'] ?? '');
        if ($mode !== '' && !in_array($mode, self::MODES, true)) {
            $this->addFinding($findings, 'invalid_container_mode', "Unsupported detached container mode '{$mode}'.", $entry);
        }
        if ($mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID && trim((string) ($container['targetObjectId'] ?? '')) === '') {
            $this->addFinding($findings, 'incomplete_container', "Hidden detached container is missing 'targetObjectId'.", $entry);
        }
        if (isset($container['capacity']) && (!is_numeric($container['capacity']) || (float) $container['capacity'] < 0.0)) {
            $this->addFinding($findings, 'invalid_container_capacity', 'Detached container capacity must be a non-negative number.', $entry);
        }

        $payload = $container['payload'] ?? null;
        if (!is_array($payload)) {
            $this->addFinding($findings, 'incomplete_payload', "Missing or invalid detached container 'payload'.", $entry);
            return;
        }
        foreach (['sourceContainerId', 'container', 'containerItem', 'resources', 'items'] as $field) {
            if (!array_key_exists($field, $payload)) {
                $this->addFinding($findings, 'incomplete_payload', "Missing payload field '{$field}'.", $entry);
            }
        }

        $sourceUid = trim((string) ($payload['sourceContainerId'] ?? ''));
        $containerSnapshot = is_array($payload['container'] ?? null) ? $payload['container'] : null;
        $itemSnapshot = is_array($payload['containerItem'] ?? null) ? $payload['containerItem'] : null;
        if ($sourceUid !== '' && $containerSnapshot !== null && trim((string) ($containerSnapshot['id'] ?? '')) !== $sourceUid) {
            $this->addFinding($findings, 'snapshot_identifier_mismatch', "payload.container.id does not match sourceContainerId '{$sourceUid}'.", $entry);
        }
        if ($sourceUid !== '' && $itemSnapshot !== null) {
            $itemUid = trim((string) ($itemSnapshot['uid'] ?? ''));
            if ($itemUid === '' || 'container-' . $itemUid !== $sourceUid) {
                $this->addFinding($findings, 'snapshot_identifier_mismatch', "Backing item uid does not match sourceContainerId '{$sourceUid}'.", $entry);
            }
            if (($itemSnapshot['type'] ?? null) !== 'additional_container') {
                $this->addFinding($findings, 'invalid_backing_item', "payload.containerItem.type must be 'additional_container'.", $entry);
            }
        }
        if (isset($payload['resources']) && !is_array($payload['resources'])) {
            $this->addFinding($findings, 'invalid_payload_resources', "payload.resources must be an object.", $entry);
        }
        if (isset($payload['items']) && !is_array($payload['items'])) {
            $this->addFinding($findings, 'invalid_payload_items', "payload.items must be an array.", $entry);
        }
    }

    /**
     * @param list<array{code:string,message:string,file:string,collection:?string,index:?int,containerId:?string}> $findings
     * @param array{file:string,collection:?string,index:?int,containerId:?string} $entry
     */
    private function addFinding(array &$findings, string $code, string $message, array $entry): void
    {
        $findings[] = [
            'code' => $code,
            'message' => $message,
            'file' => $entry['file'],
            'collection' => $entry['collection'],
            'index' => $entry['index'],
            'containerId' => $entry['containerId'],
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            return $item;
        };

        return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
