<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Storage;

/** Integer manifests; resource-only trips are never expanded into individual loads. */
final class TransferLoadPlanner
{
    public const RESOURCE_TYPES = ['deuterium', 'metals', 'ice', 'carbon_compounds'];
    public const MAX_UNITS = 9007199254740991;

    public static function units(mixed $amount): int
    {
        if ((!is_int($amount) && !is_float($amount)) || !is_finite((float) $amount) || $amount <= 0) {
            throw new \InvalidArgumentException('A positive finite numeric quantity is required.');
        }
        $scaled = $amount * 10000;
        if ($scaled > self::MAX_UNITS || abs($scaled - round($scaled)) > 0.000001 || round($scaled) < 1) {
            throw new \InvalidArgumentException('Quantity must be representable in units of 0.0001 ECE.');
        }
        return (int) round($scaled);
    }

    /** @param array<string,int|float> $resources @param list<array{id:string,containerSpace:int|float}> $items */
    public function plan(array $resources, array $items, float $capacity = 2.0, int $tripSeconds = 600, bool $wholeItems = false): array
    {
        $capacityUnits = self::units($capacity);
        if ($tripSeconds < 1 || count($items) > 500 || ($resources === [] && $items === [])) {
            throw new \InvalidArgumentException('Invalid transfer manifest or duration.');
        }
        if ($wholeItems && $resources !== [] && $items !== []) {
            throw new \InvalidArgumentException('Whole-item transport cannot mix resources and items.');
        }
        $resourceUnits = [];
        if (array_diff(array_keys($resources), self::RESOURCE_TYPES) !== []) {
            throw new \InvalidArgumentException('Unknown resource type.');
        }
        $total = 0;
        foreach (self::RESOURCE_TYPES as $type) {
            if (array_key_exists($type, $resources)) {
                $resourceUnits[$type] = self::units($resources[$type]);
                $total = $this->add($total, $resourceUnits[$type]);
            }
        }
        $seen = [];
        foreach ($items as &$item) {
            if (!isset($item['id']) || !is_string($item['id']) || $item['id'] === '' || isset($seen[$item['id']])) {
                throw new \InvalidArgumentException('Item identities must be unique nonempty strings.');
            }
            $seen[$item['id']] = true;
            $item['units'] = self::units($item['containerSpace'] ?? null);
            if (!$wholeItems && $item['units'] > $capacityUnits) {
                throw new \InvalidArgumentException('Item exceeds the cargo capacity.');
            }
            $total = $this->add($total, $item['units']);
        }
        unset($item);
        usort($items, static fn(array $a, array $b): int => ($b['units'] <=> $a['units']) ?: strcmp($a['id'], $b['id']));

        // Segment tree of maximum free capacity: find the first fitting bin in O(log I).
        $leaves = 1;
        while ($leaves < count($items)) { $leaves *= 2; }
        $tree = array_fill(0, 2 * $leaves, 0);
        $loads = [];
        foreach ($items as $item) {
            if ($wholeItems || $tree[1] < $item['units']) {
                $index = count($loads);
                $loads[] = ['itemIds' => [], 'resources' => [], 'freeUnits' => $wholeItems ? $item['units'] : $capacityUnits];
            } else {
                $node = 1;
                while ($node < $leaves) {
                    $node = $tree[2 * $node] >= $item['units'] ? 2 * $node : 2 * $node + 1;
                }
                $index = $node - $leaves;
            }
            $loads[$index]['itemIds'][] = $item['id'];
            $loads[$index]['freeUnits'] -= $item['units'];
            $node = $leaves + $index;
            $tree[$node] = $loads[$index]['freeUnits'];
            while ($node > 1) {
                $node = intdiv($node, 2);
                $tree[$node] = max($tree[2 * $node], $tree[2 * $node + 1]);
            }
        }
        $remaining = $resourceUnits;
        foreach ($loads as &$load) {
            foreach ($remaining as $type => &$amount) {
                $take = min($amount, $load['freeUnits']);
                if ($take > 0) { $load['resources'][$type] = $take; }
                $amount -= $take;
                $load['freeUnits'] -= $take;
            }
            unset($amount);
            unset($load['freeUnits']);
        }
        unset($load);
        $tailUnits = array_sum($remaining);
        $tripCount = count($loads) + intdiv($tailUnits, $capacityUnits) + (int) ($tailUnits % $capacityUnits !== 0);
        if ($tripCount > intdiv(PHP_INT_MAX, $tripSeconds)) {
            throw new \InvalidArgumentException('Transfer duration overflows.');
        }
        return ['capacityUnits' => $capacityUnits, 'tripSeconds' => $tripSeconds, 'tripCount' => $tripCount,
            'durationSeconds' => $tripCount * $tripSeconds, 'totalUnits' => $total, 'loads' => $loads, 'tailResources' => $remaining];
    }

    public function endsAt(array $plan, \DateTimeImmutable $start): \DateTimeImmutable
    {
        $duration = $plan['durationSeconds'];
        $maximum = 253402300799; // Last second with a four-digit UTC year, also portable to SQL dates.
        if ($start->getTimestamp() < 0 || $duration > $maximum - $start->getTimestamp()) {
            throw new \InvalidArgumentException('Transfer deadline is not representable.');
        }
        return $start->setTimestamp($start->getTimestamp() + $duration);
    }

    /** @return array{resources:array<string,int>,itemIds:list<string>} quantities are integer units */
    public function cargoAt(array $plan, int $elapsedSeconds, string $direction): array
    {
        if (!in_array($direction, ['to_storage', 'from_storage'], true)) {
            throw new \InvalidArgumentException('Invalid transfer direction.');
        }
        $empty = ['resources' => [], 'itemIds' => []];
        if ($elapsedSeconds < 0 || $elapsedSeconds >= $plan['durationSeconds']) { return $empty; }
        $returning = $elapsedSeconds % $plan['tripSeconds'] >= $plan['tripSeconds'] / 2;
        if ($returning !== ($direction === 'from_storage')) { return $empty; }
        $trip = intdiv($elapsedSeconds, $plan['tripSeconds']);
        if ($trip < count($plan['loads'])) { return $plan['loads'][$trip]; }
        $offset = ($trip - count($plan['loads'])) * $plan['capacityUnits'];
        $end = $offset + $plan['capacityUnits'];
        $position = 0;
        foreach ($plan['tailResources'] as $type => $units) {
            $take = max(0, min($end, $position + $units) - max($offset, $position));
            if ($take > 0) { $empty['resources'][$type] = $take; }
            $position += $units;
        }
        return $empty;
    }

    private function add(int $left, int $right): int
    {
        if ($right > self::MAX_UNITS - $left) { throw new \InvalidArgumentException('Manifest quantity overflows.'); }
        return $left + $right;
    }
}
