<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\GerminationDepotRepository;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Service\GerminationDepotService;
use VonNeumannGame\Service\SectorStorageTransferService;
use VonNeumannGame\Service\SectorEffectService;
use VonNeumannGame\Service\AnomalyBroadcastService;

require_once __DIR__.'/Support/StorageTestPdo.php';

if (!function_exists('pcntl_fork')) { throw new RuntimeException('pcntl is required for actual multiprocess races.'); }
$mysqlConfig=null;
foreach(array_slice($argv,1) as $arg){
    if(str_starts_with($arg,'--mysql-config=')){$mysqlConfig=substr($arg,15);}
    else{throw new InvalidArgumentException('Usage: php tests/StorageConcurrencyTests.php [--mysql-config=PATH]');}
}
$directory=sys_get_temp_dir().'/vng-storage-race-'.bin2hex(random_bytes(6));mkdir($directory,0700);
$tables=[];
if($mysqlConfig!==null){
    $config=DatabaseConfig::fromFile($mysqlConfig);
    if($config->driver!=='mysql'){throw new RuntimeException('The configured database must use MySQL/MariaDB.');}
    $statements=(new ReflectionMethod(SchemaInitializer::class,'statements'))->invoke(new SchemaInitializer('mysql'));
    $prefix='storage_test_'.bin2hex(random_bytes(5)).'_';
    foreach($statements as $sql){if(preg_match('/CREATE TABLE IF NOT EXISTS (\\w+)/',$sql,$match)){$tables[$match[1]]=$prefix.count($tables);}}
}else{$config=new DatabaseConfig('sqlite',path:$directory.'/race.sqlite');}
$factory=new DatabaseConnectionFactory($config,dirname(__DIR__));
$connect=static function()use($factory,$tables):PDO{
    $db=$factory->create();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$db->exec('PRAGMA busy_timeout=5000');return $db;}
    return new StorageTestPdo($db,$tables);
};
$services=static function(PDO $db)use($directory):array{
    $others=new OthersRepository($db);$events=new ScheduledEventRepository($db);$depots=new GerminationDepotRepository($db);
    $sectors=new SectorService(new SectorFileRepository($directory),new SectorContentGenerator(),'concurrency-fixture',germinationDepots:$depots);
    $effects=new SectorEffectService($db,$events,$sectors);$waves=new AnomalyBroadcastService($db,$events);
    $construction=new GerminationDepotService($others,$events,$sectors,$effects,$waves);
    return [$others,$construction,new SectorStorageTransferService($db,$construction)];
};
$assert=static function(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}echo 'PASS '.$message.PHP_EOL;};
// Each child owns a distinct connection and waits on a pipe barrier after connecting.
$race=static function(array $operations)use($connect):array{
    $children=[];
    foreach($operations as $operation){
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
        if($pair===false){throw new RuntimeException('Unable to create race barrier.');}
        $pid=pcntl_fork();
        if($pid===-1){throw new RuntimeException('Unable to fork worker.');}
        if($pid===0){
            fclose($pair[0]);stream_set_timeout($pair[1],20);
            try{
                $db=$connect();fwrite($pair[1],"ready\n");fgets($pair[1]);
                try{$result=['ok'=>true,'result'=>$operation($db)];}
                catch(Throwable $error){$result=['ok'=>false,'class'=>get_class($error),'message'=>$error->getMessage(),'code'=>$error->errorCode??null];}
                fwrite($pair[1],json_encode($result,JSON_THROW_ON_ERROR)."\n");fclose($pair[1]);exit(0);
            }catch(Throwable $error){fwrite($pair[1],json_encode(['ok'=>false,'message'=>$error->getMessage()])."\n");exit(1);}
        }
        fclose($pair[1]);stream_set_timeout($pair[0],20);$children[]=[$pid,$pair[0]];
    }
    foreach($children as [$pid,$pipe]){if(trim((string)fgets($pipe))!=='ready'){throw new RuntimeException('Worker did not reach the race barrier.');}}
    foreach($children as [$pid,$pipe]){fwrite($pipe,"go\n");}
    $results=[];
    foreach($children as [$pid,$pipe]){$results[]=json_decode((string)fgets($pipe),true,512,JSON_THROW_ON_ERROR);fclose($pipe);pcntl_waitpid($pid,$status);}
    return $results;
};
try{
    $db=$connect();(new SchemaInitializer($config->driver))->initialize($db);
    $now=gmdate('c');$db->prepare('INSERT INTO players(username,created_at,updated_at) VALUES(?,?,?)')->execute(['race-player',$now,$now]);
    $others=new OthersRepository($db);$fleet=$others->createFleet(1,3,4,5);$ship=$others->findShipsByFleetId((int)$fleet['id'])[0];
    $standard=$others->createStandardShip($ship);$actors=[$others->createAuxiliary((int)$ship['id']),$others->createAuxiliary((int)$standard['id'])];
    $ships=[$others->findShipForPlayer($ship['public_id'],1),$others->findShipForPlayer($standard['public_id'],1)];
    $depots=new GerminationDepotRepository($db);$depot=$depots->create(999,new SectorCoordinates(3,4,5),$now);
    $db->prepare('INSERT INTO germination_depot_items(depot_id,public_id,type,name,container_space,metadata_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')->execute([$depot['id'],'race-last-item','steel_bar','Barre',0.01,'{}',$now,$now]);
    (new SectorFileRepository($directory))->save(new SectorContent(new SectorCoordinates(3,4,5)));
    // Release parent sockets before forking, avoiding inherited MySQL connection shutdowns.
    $db=null;$others=null;$depots=null;
    $operations=[];
    foreach([0,1] as $index){$operations[]=static function(PDO $db)use($services,$ships,$actors,$index,$depot):array{
        [,,$transfers]=$services($db);
        $action=$transfers->startOthers($ships[$index],$actors[$index],'from_storage',['depotId'=>$depot['public_id'],'resources'=>[],'itemIds'=>['race-last-item']]);
        return ['actionId'=>(int)$action['id'],'endsAt'=>$action['ends_at']];
    };}
    $results=$race($operations);$success=array_values(array_filter($results,static fn(array $r):bool=>$r['ok']));
    $assert(count($success)===1,'two connections reserve the last item exactly once: '.json_encode($results));
    $winner=$success[0]['result'];
    $complete=static function(PDO $db)use($services,$winner):bool{[,,$transfers]=$services($db);$transfers->completeOthers($winner['actionId'],$winner['endsAt']);return true;};
    $results=$race([$complete,$complete]);
    $assert($results[0]['ok']&&$results[1]['ok'],'concurrent terminal workers both finish without duplicate settlement');
    $db=$connect();
    $assert((int)$db->query("SELECT COUNT(*) FROM others_inventory_items WHERE public_id='race-last-item'")->fetchColumn()===1,'one physical destination identity remains');
    $assert((int)$db->query('SELECT COUNT(*) FROM sector_storage_item_claims')->fetchColumn()===0,'no item claims remain after concurrent completion');
    $assert((float)$db->query('SELECT SUM(inventory_reserved) FROM others_ships')->fetchColumn()===0.0,'no capacity remains reserved after concurrent completion');
    $mannyServices=static function(PDO $pdo):array{
        $probes=new \VonNeumannGame\Repository\NeumannProbeRepository($pdo);
        $mannies=new \VonNeumannGame\Repository\MannyRepository($pdo);
        $storage=new \VonNeumannGame\Service\ProbeStorageService(new \VonNeumannGame\Repository\StorageContainerRepository($pdo),new \VonNeumannGame\Repository\ProbeItemRepository($pdo),$mannies,$probes);
        return [$probes,$mannies,$storage,new \VonNeumannGame\Service\MannyStorageTransferService($pdo,$mannies,$probes,$storage)];
    };
    $fixture=static function(PDO $pdo)use($mannyServices,$services,$depot,$now):array{
        [$probes,$mannies,$storage]=$mannyServices($pdo);
        $probe=$probes->createForPlayer(1,'Race probe',new SectorCoordinates(3,4,5));
        $second=$probes->createForPlayer(1,'Second race probe',new SectorCoordinates(3,4,5));
        $manny=$mannies->createForProbe($probe->id,'Race Manny');$storage->initializeProbeStorage($probe);
        [, $construction]=$services($pdo);$construction->impact($depot['public_id']);$construction->inspect($probe,$depot['public_id'],$now);
        $pdo->prepare('INSERT INTO germination_depot_items(depot_id,public_id,type,name,container_space,metadata_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')->execute([$depot['id'],'race-cross-item','steel_bar','Cross bar',0.01,'{}',$now,$now]);
        $next=(new GerminationDepotRepository($pdo))->create(1000,new SectorCoordinates(3,4,5),$now);$construction->impact($next['public_id']);
        return [$probe,$second,$manny,$next];
    };
    [$probe,$secondProbe,$manny,$nextDepot]=$fixture($db);$db=null;
    $results=$race([
        static function(PDO $pdo)use($services,$ships,$actors,$depot):array{
            [,,$transfers]=$services($pdo);$action=$transfers->startOthers($ships[0],$actors[0],'from_storage',['depotId'=>$depot['public_id'],'resources'=>[],'itemIds'=>['race-cross-item']]);
            return ['kind'=>'others','id'=>(int)$action['id'],'endsAt'=>$action['ends_at']];
        },
        static function(PDO $pdo)use($mannyServices,$probe,$manny,$depot):array{
            [,,,$transfers]=$mannyServices($pdo);$created=$transfers->start($probe,$manny->uid,['objectId'=>$depot['public_id'],'containerId'=>'probe-core','direction'=>'from_storage','kind'=>'items','itemIds'=>['race-cross-item']]);
            return ['kind'=>'manny','id'=>$created['transfer']['id'],'endsAt'=>$created['transfer']['endsAt']];
        },
    ]);
    $success=array_values(array_filter($results,static fn(array $r):bool=>$r['ok']));
    $assert(count($success)===1,'Manny and Others race for one identity: '.json_encode($results));
    $winner=$success[0]['result'];
    $settle=static function(PDO $pdo)use($winner,$services,$mannyServices):void{
        if($winner['kind']==='others'){[,,$transfers]=$services($pdo);$transfers->completeOthers($winner['id'],$winner['endsAt']);}
        else{[,,,$transfers]=$mannyServices($pdo);$transfers->complete($winner['id'],$winner['endsAt']);}
    };
    $db=$connect();$settle($db);
    $copies=(int)$db->query("SELECT (SELECT COUNT(*) FROM others_inventory_items WHERE public_id='race-cross-item')+(SELECT COUNT(*) FROM probe_items WHERE uid='race-cross-item')+(SELECT COUNT(*) FROM germination_depot_items WHERE public_id='race-cross-item')")->fetchColumn();
    $assert($copies===1,'cross-system settlement preserves exactly one identity');$db=null;
    $operations=[];
    foreach([$probe,$secondProbe] as $visitor){$operations[]=static function(PDO $pdo)use($services,$visitor,$nextDepot,$now):bool{
        [,$construction]=$services($pdo);$construction->inspect($visitor,$nextDepot['public_id'],$now);return true;
    };}
    $results=$race($operations);$assert($results[0]['ok']&&$results[1]['ok'],'two concurrent inspections both complete');
    $db=$connect();
    $assert((int)$db->query('SELECT COUNT(*) FROM anomaly_broadcasts WHERE depot_id='.(int)$nextDepot['id'])->fetchColumn()===1,'concurrent opening creates exactly one durable broadcast');
    $explain=$db->query(($config->driver==='mysql'?'EXPLAIN ':'EXPLAIN QUERY PLAN ')."SELECT id FROM germination_depots WHERE sector_x=3 AND sector_y=4 AND sector_z=5 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $assert(str_contains(json_encode($explain,JSON_THROW_ON_ERROR),'idx_germination_depots_sector'),'sector projection uses its index');
    echo 'ENGINE '.$config->driver.' '.($config->driver==='mysql'?$db->query('SELECT VERSION()')->fetchColumn():$db->query('SELECT sqlite_version()')->fetchColumn()).PHP_EOL;
    // Rehearse the explicit upgrade on this isolated inventory snapshot, then replay it.
    $initializer=new SchemaInitializer($config->driver);
    $columnSnapshot=static function(PDO $pdo,string $table)use($config):array{
        $rows=$pdo->query($config->driver==='mysql'?'SHOW COLUMNS FROM '.$table:'PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC);
        $result=[];
        foreach($rows as $row){if(isset($row['cid'])){unset($row['cid']);}$result[$row['Field']??$row['name']]=$row;}
        ksort($result);return $result;
    };
    $expected=[];
    foreach($initializer->sectorStorageColumnDefinitions() as $table=>$columns){
        $expected[$table]=$columnSnapshot($db,$table);
        foreach($columns as $column=>$definition){$db->exec('ALTER TABLE '.$table.' DROP COLUMN '.$column);}
    }
    $migration=new \VonNeumannGame\Service\SectorStorageMigration($db);
    $dry=$migration->run(false);
    $assert(count($dry['alterations'])===10,'migration dry run identifies all ten canonical column upgrades');
    $first=$migration->run(true);
    $second=$migration->run(true);
    $assert($first['before']===$first['after'],'migration preserves every item count and resource quantity');
    $assert($second['alterations']===[]&&$second['itemsUpdated']===0,'replayed migration makes no additional changes');
    foreach($expected as $table=>$columns){$assert($columnSnapshot($db,$table)===$columns,'migrated columns equal fresh installation: '.$table);}
    $assert((int)$db->query("SELECT COUNT(*) FROM others_inventory_items WHERE public_id='race-last-item'")->fetchColumn()===1,'migration preserves referenced item identities');
    $db=null;
}finally{
    // Remove only tables bearing this run's unpredictable prefix.
    if($tables!==[]){
        $cleanup=$factory->create();$cleanup->exec('SET FOREIGN_KEY_CHECKS=0');
        try{foreach(array_reverse($tables) as $table){$cleanup->exec('DROP TABLE IF EXISTS `'.$table.'`');}}
        finally{$cleanup->exec('SET FOREIGN_KEY_CHECKS=1');}
    }
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry){if($entry->isDir()){rmdir($entry->getPathname());}else{unlink($entry->getPathname());}}rmdir($directory);
}
