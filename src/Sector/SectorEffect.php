<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

/** The same deterministic projection is used for reads and durable file application. */
final class SectorEffect
{
    public static function apply(SectorContent $sector, string $operation, string $type, string $objectId, array $payload): void
    {
        if ($sector->hasAppliedEffect($operation)) { return; }
        if ($type === 'add_object') {
            if ($sector->findObjectById($objectId) === null) { $sector->addObject(UniverseObject::fromArray($payload)); }
        } elseif ($type === 'consume_object') {
            $sector->removeObjectById($objectId);
        } elseif ($type === 'patch_objects') {
            $objects = array_column($sector->toArray()['objects'], null, 'id');
            foreach ($payload['changes'] as $change) {
                if (($objects[$change['id']] ?? null) != $change['before']) {
                    throw new SectorStorageException('Sector object changed outside its durable operation; refusing to overwrite it: ' . $change['id']);
                }
            }
            foreach ($payload['changes'] as $change) {
                if ($change['after'] === null) { $sector->removeObjectById($change['id']); }
                else {
                    $object = UniverseObject::fromArray($change['after']);
                    if ($change['before'] === null) { $sector->addObject($object); }
                    elseif (!$sector->replaceObject($object)) { throw new SectorStorageException('Sector object disappeared during projection.'); }
                }
            }
        } else { throw new \LogicException('Unsupported sector effect.'); }
        $sector->markEffectApplied($operation);
    }
}
