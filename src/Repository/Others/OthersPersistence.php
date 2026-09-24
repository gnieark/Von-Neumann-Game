<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Others;

use PDO;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Repository\Storage\StorageLockRepository;
use VonNeumannGame\Repository\GerminationDepotRepository;

/** Connection-bound repositories assembled at the composition root; no connection escapes. */
final readonly class OthersPersistence
{
    public CombatRepository $combat;
    public ActionRepository $action;
    public MovementRepository $movement;
    public InventoryRepository $inventory;
    public ProductionRepository $production;
    public ActorRepository $actor;
    public DestructionRepository $destruction;
    public TargetRepository $target;
    public \VonNeumannGame\Repository\Storage\SectorEffectRepository $effects;
    public StorageTransaction $transaction;
    public StorageLockRepository $locks;
    public GerminationDepotRepository $depots;

    public function __construct(PDO $pdo)
    {
        $this->combat = new CombatRepository($pdo);
        $this->action = new ActionRepository($pdo);
        $this->movement = new MovementRepository($pdo);
        $this->inventory = new InventoryRepository($pdo);
        $this->production = new ProductionRepository($pdo);
        $this->actor = new ActorRepository($pdo);
        $this->destruction = new DestructionRepository($pdo);
        $this->target = new TargetRepository($pdo);
        $this->effects = new \VonNeumannGame\Repository\Storage\SectorEffectRepository($pdo);
        $this->transaction = new StorageTransaction($pdo);
        $this->locks = new StorageLockRepository($pdo);
        $this->depots = new GerminationDepotRepository($pdo);
    }
}
