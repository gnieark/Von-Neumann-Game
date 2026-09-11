<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use PDO;
use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Domain\ProbeItem;

final class SectorStorageMigration
{
    public function __construct(private readonly PDO $pdo) {}

    public function run(bool $apply = false, ?callable $progress = null): array
    {
        $driver=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $schema=new SchemaInitializer($driver);
        $alterations=[];
        foreach($schema->sectorStorageColumnDefinitions() as $table=>$columns){
            $existing=$driver==='mysql'
                ? array_column($this->pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),'Field')
                : array_column($this->pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
            if($existing===[]){throw new \RuntimeException('Missing canonical inventory table: '.$table);}
            foreach($columns as $column=>$definition){if(!in_array($column,$existing,true)){$alterations[]='ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition;}}
        }
        $depotUnion=$this->hasDepotTables() ? ' UNION ALL SELECT public_id FROM germination_depot_items' : '';
        $collisions=$this->pdo->query("SELECT uid,COUNT(*) AS copies FROM (
            SELECT public_id AS uid FROM others_inventory_items UNION ALL SELECT uid FROM probe_items
            UNION ALL SELECT uid FROM detached_storage_container_items WHERE is_backing_item=0 $depotUnion
        ) identities GROUP BY uid HAVING COUNT(*)>1 LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        if($collisions!==[]){throw new \RuntimeException('Identity collisions must be resolved with their active references before migration: '.json_encode($collisions,JSON_THROW_ON_ERROR));}
        $types=$this->pdo->query('SELECT DISTINCT type FROM others_inventory_items')->fetchAll(PDO::FETCH_COLUMN);
        foreach($types as $type){if($type!=='missile'&&ProbeItem::canonicalNameForType($type)===null){throw new \RuntimeException('Unknown Others inventory type requires an explicit catalogue mapping: '.$type);}}
        $newTables=[];
        foreach($schema->sectorStorageStatements() as $sql){
            if(preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/',$sql,$match)&&!$this->tableExists($match[1])){$newTables[]=$match[1];}
        }
        $before=$this->counts();
        $report=['dryRun'=>!$apply,'alterations'=>$alterations,'newTables'=>$newTables,'before'=>$before,'itemsUpdated'=>0,'detachedDatesUpdated'=>0];
        if(!$apply){return $report;}
        foreach($alterations as $sql){$this->pdo->exec($sql);if($progress!==null){$progress($sql);}}
        foreach($newTables as $table){if($progress!==null){$progress('Create canonical table '.$table);}}
        foreach($schema->sectorStorageStatements() as $sql){$this->pdo->exec($sql);}
        $cursor=0;
        do{
            $query=$this->pdo->prepare('SELECT id,type,name,metadata_json,created_at FROM others_inventory_items WHERE id>? ORDER BY id LIMIT 100');$query->execute([$cursor]);$rows=$query->fetchAll(PDO::FETCH_ASSOC);
            $this->pdo->beginTransaction();
            try{
                foreach($rows as $row){
                    $cursor=(int)$row['id'];
                    $metadata=json_decode($row['metadata_json'],true,512,JSON_THROW_ON_ERROR);
                    if(!is_array($metadata)){throw new \RuntimeException('Invalid item metadata at ID '.$cursor);}
                    $name=$row['name'];
                    if($name==='Objet'){$name=$row['type']==='missile'?'Missile Others':ProbeItem::canonicalNameForType($row['type']);}
                    $updated=$metadata;
                    // Explicit migration only: old Others inventory technology is known from its catalogue.
                    if($row['type']==='missile' && ($metadata===[] || $metadata===['technology'=>'others','fabricator'=>'others'] || $row['name']==='Objet')){$updated+=['technology'=>'others','fabricator'=>'others','recipe'=>'missile','craftedAt'=>$row['created_at']];}
                    if($name!==$row['name']||$updated!==$metadata){
                        $this->pdo->prepare('UPDATE others_inventory_items SET name=?,metadata_json=? WHERE id=?')->execute([$name,json_encode($updated,JSON_THROW_ON_ERROR),$cursor]);$report['itemsUpdated']++;
                    }
                }
                $this->pdo->commit();
            }catch(\Throwable $error){if($this->pdo->inTransaction()){$this->pdo->rollBack();}throw $error;}
            if($rows!==[]&&$progress!==null){$progress('Others item cursor '.$cursor);}
        }while(count($rows)===100);
        $report['detachedDatesUpdated']=$this->pdo->exec("UPDATE detached_storage_container_items SET created_at=(SELECT created_at FROM detached_storage_containers c WHERE c.object_id=container_object_id),updated_at=(SELECT updated_at FROM detached_storage_containers c WHERE c.object_id=container_object_id) WHERE created_at='' OR updated_at=''");
        $report['after']=$this->counts();
        if($report['before']!==$report['after']){throw new \RuntimeException('Inventory counts or quantities changed during schema migration.');}
        return $report;
    }

    private function hasDepotTables(): bool { return $this->tableExists('germination_depot_items'); }

    private function tableExists(string $table): bool
    {
        $name=$this->pdo->quote($table);
        $sql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ? "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=$name"
            : "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=$name";
        return (int)$this->pdo->query($sql)->fetchColumn()===1;
    }

    private function counts():array
    {
        $result=[];
        $exists=$this->hasDepotTables();
        $result['germination_depot_items']=$exists?(int)$this->pdo->query('SELECT COUNT(*) FROM germination_depot_items')->fetchColumn():0;
        $result['germination_depot_resources']=$exists?$this->pdo->query('SELECT resource_type,ROUND(SUM(amount),4) AS amount FROM germination_depot_resources GROUP BY resource_type ORDER BY resource_type')->fetchAll(PDO::FETCH_ASSOC):[];
        foreach(['others_inventory_items','probe_items','detached_storage_container_items'] as $table){$result[$table]=(int)$this->pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
        foreach(['others_inventory_resources','storage_container_resources','detached_storage_container_resources'] as $table){
            $negative=(int)$this->pdo->query('SELECT COUNT(*) FROM '.$table.' WHERE amount<0')->fetchColumn();
            if($negative!==0){throw new \RuntimeException('Negative resource amounts in '.$table);}
            $result[$table]=$this->pdo->query('SELECT resource_type,ROUND(SUM(amount),4) AS amount FROM '.$table.' GROUP BY resource_type ORDER BY resource_type')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $result;
    }
}
