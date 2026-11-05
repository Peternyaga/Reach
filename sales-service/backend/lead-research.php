<?php
declare(strict_types=1);

// Research remains separate from sales qualification and from immutable collection notes.
function researchTextFields(): array {
    return ['business_summary'=>3000,'background'=>4000,'contact_rationale'=>3000,'website_assessment'=>3000,'proposed_approach'=>3000,'industry'=>150,'service_area'=>250,'priority_rationale'=>1500,'missing_contact_reason'=>1000,'next_action'=>1500,'hold_reason'=>1500,'researched_at'=>10,'proposed_service'=>500,'proposed_price'=>500];
}
function researchInputSchema(): array {
    $properties=['document_version'=>['type'=>'integer','enum'=>[1]]];
    foreach(researchTextFields() as $name=>$limit)$properties[$name]=['type'=>'string','maxLength'=>$limit];
    $properties['research_status']=['type'=>'string','enum'=>['draft','ready_for_review','on_hold'],'description'=>'Agents cannot certify human review. On hold requires hold_reason. Ready requires narrative, rationale, business contact or missing_contact_reason, dated evidence, uncertainty and next action.'];
    $properties['priority']=['type'=>'string','enum'=>['unset','high','medium','low'],'description'=>'Research fit only; explain in priority_rationale. Never a purchase probability.'];
    foreach(['uncertainties','qualification_questions'] as $name)$properties[$name]=['type'=>'array','maxItems'=>20,'items'=>['type'=>'string','minLength'=>1,'maxLength'=>1000]];
    $evidence=[];foreach(['id'=>80,'claim'=>2000,'source_url'=>1000,'source_title'=>250,'observed_at'=>10] as $name=>$limit)$evidence[$name]=['type'=>'string','maxLength'=>$limit];
    foreach(['source_type'=>['public_source','user_supplied'],'confidence'=>['low','medium','high'],'status'=>['unconfirmed','observed','inferred','conflicting']] as $name=>$values)$evidence[$name]=['type'=>'string','enum'=>$values];
    $properties['evidence']=['type'=>'array','maxItems'=>30,'items'=>['type'=>'object','properties'=>$evidence,'required'=>['id','claim'],'additionalProperties'=>false],'description'=>'Public evidence requires an http(s) source_url without credentials. observed_at is date checked (YYYY-MM-DD). Confidence describes evidence strength, never sales probability.'];
    return ['type'=>'object','properties'=>$properties,'additionalProperties'=>false,'description'=>'Bounded document version 1, maximum encoded size 60 KB. Supply complete replacement preserving existing corrections and evidence.'];
}
function researchUrl(string $url): bool {
    return strlen($url)<=1000&&filter_var($url,FILTER_VALIDATE_URL)&&in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true)&&parse_url($url,PHP_URL_USER)===null&&parse_url($url,PHP_URL_PASS)===null;
}
function researchDate(string $value): bool {
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))return false;
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $parsed!==false&&$parsed->format('Y-m-d')===$value;
}
function researchDocument(mixed $value): array {
    if(!is_array($value)||array_is_list($value)&&$value!==[])fail('Research document must be an object',422);
    $allowed=array_merge(array_keys(researchTextFields()),['document_version','research_status','priority','evidence','uncertainties','qualification_questions']);
    foreach(array_keys($value) as $key)if(!in_array($key,$allowed,true))fail('Unsupported research field: '.$key,422);
    if(isset($value['document_version'])&&$value['document_version']!==1)fail('Unsupported research document version',422);
    $doc=['document_version'=>1];
    foreach(researchTextFields() as $key=>$max){$doc[$key]=textField($value,$key,$max);if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$doc[$key]))fail('Invalid characters in '.$key,422);}
    if($doc['researched_at']!==''&&!researchDate($doc['researched_at']))fail('Invalid research date',422);
    foreach(['research_status'=>['draft','ready_for_review','on_hold','reviewed'],'priority'=>['unset','high','medium','low']] as $key=>$choices){$doc[$key]=$value[$key]??$choices[0];if(!is_string($doc[$key])||!in_array($doc[$key],$choices,true))fail('Invalid '.$key,422);}
    foreach(['uncertainties','qualification_questions'] as $key){
        $items=$value[$key]??[];if(!is_array($items)||!array_is_list($items)||count($items)>20)fail('Use at most 20 '.$key,422);
        $doc[$key]=[];foreach($items as $item){if(!is_string($item)||trim($item)===''||mb_strlen($item)>1000)fail('Invalid '.$key.' entry',422);$doc[$key][]=trim($item);}
    }
    $items=$value['evidence']??[];if(!is_array($items)||!array_is_list($items)||count($items)>30)fail('Use at most 30 evidence records',422);
    $doc['evidence']=[];$ids=[];
    foreach($items as $item){
        if(!is_array($item)||array_is_list($item))fail('Evidence must be an object',422);
        foreach(array_keys($item) as $key)if(!in_array($key,['id','claim','source_url','source_title','source_type','observed_at','confidence','status'],true))fail('Unsupported evidence field',422);
        $e=[];foreach(['id'=>80,'claim'=>2000,'source_url'=>1000,'source_title'=>250,'observed_at'=>10] as $key=>$max)$e[$key]=textField($item,$key,$max,in_array($key,['id','claim'],true));
        if(isset($ids[$e['id']]))fail('Evidence IDs must be unique',422);$ids[$e['id']]=true;
        foreach(['source_type'=>['public_source','user_supplied'],'confidence'=>['low','medium','high'],'status'=>['unconfirmed','observed','inferred','conflicting']] as $key=>$choices){$e[$key]=$item[$key]??$choices[0];if(!is_string($e[$key])||!in_array($e[$key],$choices,true))fail('Invalid evidence '.$key,422);}
        if(($e['source_type']==='public_source'&&$e['source_url']==='')||($e['source_url']!==''&&!researchUrl($e['source_url'])))fail('Public evidence requires a safe http(s) source URL without credentials',422);
        if($e['observed_at']!==''&&!researchDate($e['observed_at']))fail('Invalid evidence date',422);
        $doc['evidence'][]=$e;
    }
    if(strlen(json_encode($doc,JSON_THROW_ON_ERROR))>60000)fail('Research document exceeds 60 KB',422);
    if($doc['research_status']==='on_hold'&&$doc['hold_reason']==='')fail('On hold requires a reason',422);
    return $doc;
}
function researchMissing(array $doc,array $lead): array {
    $missing=[];
    if(trim($lead['name']??'')==='')$missing[]='Business or contact identity';
    foreach(['business_summary'=>'Business summary','contact_rationale'=>'Why this lead','next_action'=>'Next action'] as $key=>$label)if(empty($doc[$key]))$missing[]=$label;
    if(empty($lead['phone'])&&empty($lead['email'])&&empty($lead['website'])&&empty($doc['missing_contact_reason']))$missing[]='Business contact or missing-contact reason';
    if(empty($doc['evidence']))$missing[]='Evidence';
    elseif(array_filter($doc['evidence'],fn($item)=>empty($item['observed_at'])))$missing[]='Date checked for each evidence record';
    if(empty($doc['uncertainties']))$missing[]='Explicit unknowns or none-known statement';
    return $missing;
}
function researchLead(string $id,bool $lock=false): array {
    requireRole(['owner','manager','salesperson']);
    $lead=query('SELECT * FROM leads WHERE workspace_id=? AND id=?'.($lock?' FOR UPDATE':''),[workspace(),$id])->fetch();
    if(!$lead||!canAccessLead($lead))fail('Lead not found',404);
    return $lead;
}
function getLeadResearch(string $id): array {
    $lead=researchLead($id);
    $row=query('SELECT * FROM lead_research WHERE workspace_id=? AND lead_id=?',[workspace(),$id])->fetch();
    if(!$row)return ['revision'=>0,'document'=>null,'missing_fields'=>researchMissing([], $lead),'reviewed_by'=>null,'reviewed_at'=>null];
    $doc=json_decode($row['document'],true,32,JSON_THROW_ON_ERROR);
    return ['revision'=>(int)$row['revision'],'document'=>$doc,'updated_at'=>$row['updated_at'],'updated_by'=>$row['updated_by'],'reviewed_by'=>$row['reviewed_by'],'reviewed_at'=>$row['reviewed_at'],'missing_fields'=>researchMissing($doc,$lead)];
}
function updateLeadResearch(string $id,array $args,bool $agent=false,bool $commit=true): array {
    foreach(array_keys($args) as $key)if(!in_array($key,['revision','document','request_key'],true))fail('Unexpected research argument',422);
    if(!is_int($args['revision']??null)||$args['revision']<0)fail('Supply the current research revision (0 for new research)',422);
    $doc=researchDocument($args['document']??null);$request=textField($args,'request_key',80,true);
    if($agent&&$doc['research_status']==='reviewed')fail('Only an authenticated human review can mark research reviewed',403);
    $hash=hash('sha256',json_encode([$id,$args['revision'],$doc,$agent],JSON_THROW_ON_ERROR));
    beginWorkTransaction();
    try{
        $lead=researchLead($id,true);
        $prior=query('SELECT payload_hash,revision FROM research_requests WHERE workspace_id=? AND lead_id=? AND actor_id=? AND request_key=?',[workspace(),$id,$_SESSION['user_id'],$request])->fetch();
        if($prior){if($prior['payload_hash']!==$hash)fail('Request key already used for different research',409);if($commit)db()->commit();return ['ok'=>true,'revision'=>(int)$prior['revision'],'replayed'=>true];}
        $row=query('SELECT * FROM lead_research WHERE workspace_id=? AND lead_id=? FOR UPDATE',[workspace(),$id])->fetch();
        $revision=(int)($row['revision']??0);
        if($revision!==$args['revision'])fail('Research changed. Your draft is preserved; reload the saved research before retrying.',409);
        $missing=researchMissing($doc,$lead);
        if(in_array($doc['research_status'],['ready_for_review','reviewed'],true)&&$missing)fail('Research readiness missing: '.implode(', ',$missing),422);
        $reviewed=$doc['research_status']==='reviewed';$actor=$_SESSION['user_id'];$now=gmdate('Y-m-d H:i:s');$revision++;
        query('INSERT INTO lead_research (workspace_id,lead_id,revision,document,created_by,updated_by,reviewed_by,reviewed_at) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),document=VALUES(document),updated_by=VALUES(updated_by),reviewed_by=VALUES(reviewed_by),reviewed_at=VALUES(reviewed_at),updated_at=CURRENT_TIMESTAMP',[workspace(),$id,$revision,json_encode($doc,JSON_THROW_ON_ERROR),$actor,$actor,$reviewed?$actor:null,$reviewed?$now:null]);
        query('INSERT INTO research_requests (workspace_id,lead_id,actor_id,request_key,payload_hash,revision) VALUES (?,?,?,?,?,?)',[workspace(),$id,$actor,$request,$hash,$revision]);
        audit($id,'research_updated',['revision'=>$revision,'research_status'=>$doc['research_status'],'actor_user_id'=>$actor,'agent_generated'=>$agent,'previous_document'=>$row?json_decode($row['document'],true):null,'document'=>$doc]);
        query('UPDATE leads SET updated_at=CURRENT_TIMESTAMP WHERE workspace_id=? AND id=?',[workspace(),$id]);
        if($commit)db()->commit();return ['ok'=>true,'revision'=>$revision,'missing_fields'=>$missing];
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
}
function withResearchSummaries(array $leads,bool $collector=false): array {
    if(!$leads||$collector)return $leads;
    $rows=query('SELECT lead_id,revision,document,updated_at FROM lead_research WHERE workspace_id=? AND lead_id IN ('.implode(',',array_fill(0,count($leads),'?')).')',array_merge([workspace()],array_column($leads,'id')))->fetchAll();
    $map=[];foreach($rows as $row){$doc=json_decode($row['document'],true);$map[$row['lead_id']]=array_intersect_key($doc,array_flip(['business_summary','contact_rationale','research_status','priority','priority_rationale','next_action','hold_reason']))+['revision'=>(int)$row['revision'],'updated_at'=>$row['updated_at']];}
    return array_map(fn($lead)=>$lead+['research'=>$map[$lead['id']]??null],$leads);
}
