<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';require dirname(__DIR__).'/backend/followup-time.php';
$checks=0;$now=new DateTimeImmutable('2030-01-01T00:00:00Z');
function timeCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo "PASS $label\n";}
foreach(['2030-01-02T08:00:00Z','2030-01-02T08:00:00.000Z','2030-01-02T08:00:00.1Z','2030-01-02T08:00:00.123456Z','2030-01-02T08:00:00+00:00','2030-01-02T11:00:00+03:00','2030-01-02T03:00:00-05:00','2030-01-02T13:30:00+05:30'] as $raw){
    timeCheck(normaliseFollowupTime($raw,$now)===['2030-01-02T08:00:00.000Z','2030-01-02 08:00:00'],'normalizes '.$raw);
}
timeCheck(normaliseFollowupTime('2030-01-02T01:00:00+03:00',$now)[1]==='2030-01-01 22:00:00','offset conversion crosses UTC day correctly');
foreach(['2030-01-02','2030-01-02 08:00:00','2030-01-02T08:00:00','tomorrow 11am','2030-02-30T08:00:00Z','2030-13-02T08:00:00Z','2030-01-02T24:00:00Z','2030-01-02T08:60:00Z','2030-01-02T08:00:60Z','2030-01-02T08:00:00+15:00','2030-01-02T08:00:00+14:30','2030-01-02T08:00:00+03:60','2030-01-02T08:00:00 UTC','2030-01-02T08:00:00.1234567Z','2029-01-02T08:00:00Z','2030-01-01T00:05:00Z','2030-01-01T00:05:00.999Z'] as $raw){
    try{normaliseFollowupTime($raw,$now);throw new LogicException('Accepted invalid input '.$raw);}catch(RuntimeException $e){timeCheck($e->getCode()===422&&str_contains($e->getMessage(),'timezone'),'rejects with useful format guidance: '.$raw);}
}
timeCheck(normaliseFollowupTime('2030-01-01T00:05:01Z',$now)[1]==='2030-01-01 00:05:01','first valid second after five-minute boundary accepted');
echo "Follow-up timestamp checks passed: $checks.\n";
