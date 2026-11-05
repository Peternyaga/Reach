<?php
declare(strict_types=1);

// A scheduling input must identify an absolute instant. Never guess a timezone
// from a date-only value, SQL timestamp or natural-language phrase.
function normaliseFollowupTime(string $raw,?DateTimeImmutable $now=null): array {
    $example='Use an ISO 8601 timestamp with a timezone, for example 2030-01-02T08:00:00Z or 2030-01-02T11:00:00+03:00. Milliseconds are optional.';
    if(!preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:0\d|1[0-4]):[0-5]\d)\z/',$raw,$parts))fail($example,422);
    $offset=$parts[3];
    if($offset!=='Z'&&substr($offset,1,2)==='14'&&substr($offset,4,2)!=='00')fail($example,422);
    try{$due=new DateTimeImmutable($raw);}catch(Throwable){fail('Invalid calendar date or time. '.$example,422);}
    $errors=DateTimeImmutable::getLastErrors();
    if(($errors&&($errors['warning_count']||$errors['error_count']))||$due->format('Y-m-d\TH:i:s')!==$parts[1])fail('Invalid calendar date or time. '.$example,422);
    $due=$due->setTimezone(new DateTimeZone('UTC'));
    // Reach stores task deadlines at second precision. Apply the future check
    // to the same precision that will be saved, rather than a discarded fraction.
    $due=$due->setTime((int)$due->format('H'),(int)$due->format('i'),(int)$due->format('s'),0);
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    if($due<=$now->modify('+5 minutes'))fail('Choose a time more than five minutes from now. '.$example,422);
    return [$due->format('Y-m-d\TH:i:s.v\Z'),$due->format('Y-m-d H:i:s')];
}
