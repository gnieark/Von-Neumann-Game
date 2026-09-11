<?php

declare(strict_types=1);

use VonNeumannGame\Service\Storage\TransferLoadPlanner;
use VonNeumannGame\Service\AnomalyBroadcastService;

(static function ($test): void {
    $planner = new TransferLoadPlanner();
    foreach ([[0.0001,600],[2.0,600],[2.0001,1200],[5.0,1800]] as [$amount,$seconds]) {
        $test->assertEquals($seconds, $planner->plan(['metals'=>$amount],[])['durationSeconds'], 'storage planner: exact resource trip boundary ' . $amount);
    }
    $plan = $planner->plan([], [['id'=>'a','containerSpace'=>1.2],['id'=>'b','containerSpace'=>1.2],['id'=>'c','containerSpace'=>1.2]]);
    $test->assertEquals(3, $plan['tripCount'], 'storage planner: indivisible objects require three loads');
    $huge = $planner->plan(['metals'=>1000000000.0], []);
    $test->assertEquals(0, count($huge['loads']), 'storage planner: a billion ECE does not expand trips');
    foreach ([0,-1,INF,NAN,0.00001,'2'] as $bad) {
        $thrown = false;
        try { TransferLoadPlanner::units($bad); } catch (InvalidArgumentException) { $thrown = true; }
        $test->assert($thrown, 'storage planner rejects invalid numeric quantity');
    }
    $plan = $planner->plan(['metals'=>5], []);
    $test->assertEquals(['metals'=>20000], $planner->cargoAt($plan,299,'to_storage')['resources'], 'deposit carries resources at 299 seconds');
    $test->assertEquals([], $planner->cargoAt($plan,300,'to_storage')['resources'], 'deposit return is empty at 300 seconds');
    $test->assertEquals(['metals'=>20000], $planner->cargoAt($plan,300,'from_storage')['resources'], 'withdrawal return is loaded at 300 seconds');
    $test->assertEquals(['metals'=>10000], $planner->cargoAt($plan,1200,'to_storage')['resources'], 'last load contains only its remaining ECE');
    $test->assertEquals([], $planner->cargoAt($plan,1800,'to_storage')['resources'], 'completed transfer has no virtual cargo');
    $whole = $planner->plan([], [['id'=>'whole','containerSpace'=>2]], 0.05,1800,true);
    $test->assertEquals(1,$whole['tripCount'],'Manny whole-object profile preserves salvage capacity');

    $odd=$planner->plan([], [['id'=>'odd','containerSpace'=>1]],0.05,301,true);
    $test->assertEquals([], $planner->cargoAt($odd,150,'from_storage')['itemIds'], 'odd configured salvage duration keeps outbound half empty');
    $test->assertEquals(['odd'], $planner->cargoAt($odd,151,'from_storage')['itemIds'], 'odd configured salvage duration loads the return half');
})($test);
