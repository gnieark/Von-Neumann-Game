<?php

declare(strict_types=1);

// This scenario uses the API suite's complete router and isolated database.
(static function () use ($test,$pdo,$players,$probes,$auth,$others,$kernel,$sectorRepository,$germinationDepots,$storageTransfers,$storage,$mannies,$mannyStorageTransfers): void {
    $coordinates=new \VonNeumannGame\Sector\SectorCoordinates(315,7,-8);
    $sectorRepository->save(new \VonNeumannGame\Sector\SectorContent($coordinates));
    $player=$players->createPlayer('storage-http-owner','Storage HTTP owner',null,$coordinates);
    $player->canControlOthers=true;$players->save($player);
    $probe=$probes->createForPlayer($player->id,'Storage HTTP probe',$coordinates);
    $headers=['Authorization'=>'Bearer '.$auth->createSessionForPlayer($player)['token']];
    $fleet=$others->createFleet($player->id,315,7,-8);
    $ship=$others->findShipsByFleetId((int)$fleet['id'])[0];
    $aux=$others->createAuxiliary((int)$ship['id']);
    $pdo->prepare("UPDATE others_inventory_resources SET amount=20 WHERE ship_id=? AND resource_type='metals'")->execute([$ship['id']]);
    $buildPath='/api/others/ships/'.$ship['public_id'].'/auxiliaries/'.$aux['public_id'].'/build-germination-depot';
    $test->assertEquals(401,$kernel->handle('POST',$buildPath,[],'{}')->status,'depot build requires authentication');
    foreach(['[]','{"cost":0}','{"durationSeconds":1}'] as $body){$test->assertEquals(400,$kernel->handle('POST',$buildPath,$headers,$body)->status,'depot build rejects a forged or non-object payload');}
    $keyHeaders=$headers+['Idempotency-Key'=>'storage-http-build'];
    $built=$kernel->handle('POST',$buildPath,$keyHeaders,'{}');
    $test->assertEquals(202,$built->status,'HTTP starts a depot construction');
    $test->assertEquals($built->body,$kernel->handle('POST',$buildPath,$keyHeaders,'{}')->body,'HTTP construction retry returns the exact original action');
    $test->assertEquals(409,$kernel->handle('POST',$buildPath,$keyHeaders,'[]')->status,'idempotency distinguishes a JSON array from an empty object');
    if($built->status!==202){throw new RuntimeException(json_encode($built->body));}
    $action=$others->findActionByPublicId($built->body['action']['id']);
    $germinationDepots->completeConstruction((int)$action['id'],$action['ends_at']);
    $query=$pdo->prepare('SELECT * FROM germination_depots WHERE construction_action_id=?');$query->execute([$action['id']]);$depot=$query->fetch(PDO::FETCH_ASSOC);
    $inventoryPath='/api/others/germination-depots/'.$depot['public_id'].'/inventory';
    $test->assertEquals(200,$kernel->handle('GET',$inventoryPath,$headers)->status,'local Others can read their newly built depot');
    $depositPath=str_replace('build-germination-depot','depot-deposits',$buildPath);
    $payload=['depotId'=>$depot['public_id'],'resources'=>['metals'=>0.2],'itemIds'=>[]];
    foreach([['resources'=>['metals'=>0]],['resources'=>['metals'=>'1']],['itemIds'=>['same','same']],['capacityEce'=>99]] as $invalid){
        $test->assertEquals(400,$kernel->handle('POST',$depositPath,$headers,json_encode(array_replace($payload,$invalid)))->status,'HTTP validates canonical storage transfer payloads');
    }
    $deposit=$kernel->handle('POST',$depositPath,$headers,json_encode($payload));
    $test->assertEquals(202,$deposit->status,'HTTP deposits resources');
    $action=$others->findActionByPublicId($deposit->body['action']['id']);$storageTransfers->completeOthers((int)$action['id'],$action['ends_at']);
    $public=json_encode($kernel->handle('GET',$inventoryPath,$headers)->body);
    foreach(['sector_x','sector_y','sector_z','manifest_json','reserved_transfer_id','player_id'] as $secret){$test->assert(!str_contains($public,$secret),'public inventory hides internal column '.$secret);}
    $probeInventoryPath='/api/probe/'.$probe->id.'/sector-objects/'.$depot['public_id'].'/inventory';
    $test->assertEquals(404,$kernel->handle('GET',$probeInventoryPath,$headers)->status,'knowing a dormant object ID does not reveal stock');
    $germinationDepots->inspect($probe,$depot['public_id'],gmdate('c'));
    $germinationDepots->impact($depot['public_id']);$germinationDepots->inspect($probe,$depot['public_id'],gmdate('c'));
    $test->assertEquals(200,$kernel->handle('GET',$probeInventoryPath,$headers)->status,'personal discovery enables generic inventory route');
    $manny=$mannies->createForProbe($probe->id,'Storage HTTP Manny');$storage->initializeProbeStorage($probe);
    $path='/api/probe/'.$probe->id.'/mannies/'.$manny->uid.'/storage-transfers';
    $body=json_encode(['objectId'=>$depot['public_id'],'direction'=>'from_storage','containerId'=>'probe-core','kind'=>'resources','resources'=>['metals'=>0.05]]);
    $transferHeaders=$headers+['Idempotency-Key'=>'storage-http-manny'];
    $created=$kernel->handle('POST',$path,$transferHeaders,$body);
    $test->assertEquals(202,$created->status,'generic Manny HTTP transfer is accepted');
    if($created->status!==202){throw new RuntimeException(json_encode($created->body));}
    $test->assertEquals($created->body,$kernel->handle('POST',$path,$transferHeaders,$body)->body,'Manny HTTP retry preserves the complete original response');
    $test->assertEquals(409,$kernel->handle('POST',$path,$transferHeaders,str_replace('0.05','0.1',$body))->status,'changed Manny payload conflicts with its idempotency key');
    $transfer=$created->body['transfer'];$mannyStorageTransfers->complete($transfer['id'],$transfer['endsAt']);
    $lookup='/api/probe/'.$probe->id.'/storage-transfers/'.$transfer['id'];
    $test->assertEquals('succeeded',$kernel->handle('GET',$lookup,$headers)->body['transfer']['status']??null,'durable transfer result remains available after settlement');
    $test->assertEquals(0.05,$storage->resourceStock($probe,'metals'),'HTTP scenario conserves the resource quantity');
    $test->assertEquals(400,$kernel->handle('GET',$probeInventoryPath.'?limit=501',$headers)->status,'inventory bounds its page size');
    $test->assertEquals(400,$kernel->handle('GET',$probeInventoryPath.'?cursor=invalid',$headers)->status,'inventory rejects malformed cursors');
}) ();
