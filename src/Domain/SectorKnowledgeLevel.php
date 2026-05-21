<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

enum SectorKnowledgeLevel: string
{
    case Detailed = 'detailed';
    case NeighborScan = 'neighbor_scan';
    case DistantScan = 'distant_scan';
    case LongRangeEstimation = 'long_range_estimation';
}
