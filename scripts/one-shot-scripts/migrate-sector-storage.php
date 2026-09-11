<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

$config=null;$apply=false;
foreach(array_slice($argv,1) as $argument){
    if($argument==='--apply'){$apply=true;}
    elseif($argument==='--dry-run'){$apply=false;}
    elseif(str_starts_with($argument,'--database-config=')){$config=substr($argument,18);}
    else{fwrite(STDERR,"Usage: php scripts/one-shot-scripts/migrate-sector-storage.php [--database-config=PATH] [--dry-run|--apply]\n");exit(2);}
}
try{
    $pdo=(new \VonNeumannGame\AppFactory(dirname(__DIR__,2)))->pdo($config,initializeSchema:false);
    $report=(new \VonNeumannGame\Service\SectorStorageMigration($pdo))->run($apply,static fn(string $message)=>fwrite(STDERR,$message."\n"));
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
