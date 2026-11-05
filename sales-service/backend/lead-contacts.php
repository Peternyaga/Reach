<?php
declare(strict_types=1);

function contactInputSchema(): array {
    $properties=[];foreach(['name'=>150,'department'=>150,'phone'=>50,'email'=>254,'role'=>150] as $key=>$max)$properties[$key]=['type'=>'string','maxLength'=>$max];
    $properties['ownership']=['type'=>'string','enum'=>['direct','shared','unknown']];
    $properties['is_primary']=['type'=>'boolean'];
    return ['type'=>'object','properties'=>$properties,'additionalProperties'=>false];
}
function contactOperationsSchema(): array {
    return ['type'=>'array','minItems'=>1,'maxItems'=>50,'items'=>['type'=>'object','properties'=>['action'=>['type'=>'string','enum'=>['add','update','remove','set_primary']],'contact_id'=>['type'=>'string'],'contact'=>contactInputSchema()],'required'=>['action'],'additionalProperties'=>false]];
}
function contactValues(mixed $input,?array $previous=null): array {
    if(!is_array($input)||array_is_list($input)&&$input!==[])fail('Contact must be an object',422);
    foreach($input as $key=>$value)if(!array_key_exists($key,contactInputSchema()['properties']))fail('Unexpected contact field',422);
    $out=[];foreach(['name'=>150,'department'=>150,'phone'=>50,'email'=>254,'role'=>150] as $key=>$max){
        if(array_key_exists($key,$input)&&!is_string($input[$key]))fail('Contact '.$key.' must be a string',422);
        $value=$input[$key]??($previous[$key]??'');
        if(!is_string($value)||mb_strlen(trim($value))>$max||preg_match('/[\x00-\x1F\x7F]/',$value))fail('Check contact '.$key,422);
        $out[$key]=trim($value);
    }
    if($out['email']!==''&&!filter_var($out['email'],FILTER_VALIDATE_EMAIL))fail('Invalid contact email',422);
    if($out['phone']!==''&&(!preg_match('/^\+?[0-9 ().-]{5,50}$/',$out['phone'])||!preg_match('/^\+?[0-9]{5,20}$/',preg_replace('/[ ().-]/','',$out['phone']))))fail('Invalid contact phone',422);
    if($out['phone']===''&&$out['email']==='')fail('Each contact needs a phone or email',422);
    if(array_key_exists('ownership',$input)&&!is_string($input['ownership']))fail('Invalid contact ownership',422);
    $out['ownership']=$input['ownership']??($previous['ownership']??'unknown');
    if(!in_array($out['ownership'],['direct','shared','unknown'],true))fail('Invalid contact ownership',422);
    if(array_key_exists('is_primary',$input)&&!is_bool($input['is_primary']))fail('is_primary must be boolean',422);
    return $out;
}
function leadContactRows(string $workspace,string $id): array {
    $rows=query('SELECT id,name,department,phone,email,role,ownership,is_primary FROM lead_contacts WHERE workspace_id=? AND lead_id=? ORDER BY is_primary DESC,created_at,id',[$workspace,$id])->fetchAll();
    foreach($rows as &$row)$row['is_primary']=(bool)$row['is_primary'];unset($row);return $rows;
}
function insertLeadContact(array $lead,array $values,bool $primary): string {
    $id=uid();query('INSERT INTO lead_contacts (id,workspace_id,lead_id,name,department,phone,email,role,ownership,is_primary) VALUES (?,?,?,?,?,?,?,?,?,?)',array_merge([$id,$lead['workspace_id'],$lead['id']],array_values($values),[$primary?1:null]));return $id;
}
function initialiseLeadContacts(array $lead): void {
    if(!empty($lead['contacts_initialized']))return;
    $claimed=query('UPDATE leads SET contacts_initialized=1 WHERE workspace_id=? AND id=? AND contacts_initialized=0',[$lead['workspace_id'],$lead['id']]);
    if($claimed->rowCount()!==1)return;
    // A lead's title and assigned salesperson do not establish who uses a route.
    if(($lead['phone']??'')!==''||($lead['email']??'')!=='')insertLeadContact($lead,['name'=>'','department'=>'','phone'=>$lead['phone'],'email'=>$lead['email'],'role'=>$lead['contact_role']??'','ownership'=>'unknown'],true);
}
function mirrorPrimaryContact(array $lead): void {
    $primary=query('SELECT phone,email,role FROM lead_contacts WHERE workspace_id=? AND lead_id=? AND is_primary=1',[$lead['workspace_id'],$lead['id']])->fetch()?:['phone'=>'','email'=>'','role'=>''];
    query('UPDATE leads SET phone=?,email=?,contact_role=? WHERE workspace_id=? AND id=?',[$primary['phone'],$primary['email'],$primary['role'],$lead['workspace_id'],$lead['id']]);
    query('UPDATE contacts SET phone=?,email=? WHERE workspace_id=? AND source_lead_id=?',[$primary['phone'],$primary['email'],$lead['workspace_id'],$lead['id']]);
}
function createLeadContacts(array $lead,mixed $contacts): void {
    if(!is_array($contacts)||!array_is_list($contacts)||count($contacts)>50)fail('Supply up to 50 contacts',422);
    $primary=null;$rows=[];foreach($contacts as $index=>$input){$rows[]=contactValues($input);if(($input['is_primary']??false)===true){if($primary!==null)fail('Select only one primary contact',422);$primary=$index;}}
    // Retain flat values too when a mixed old/new client submits a different route.
    if(($lead['phone']??'')!==''||($lead['email']??'')!==''){
        $found=false;foreach($rows as $row)if($row['phone']===($lead['phone']??'')&&strcasecmp($row['email'],$lead['email']??'')===0)$found=true;
        if(!$found){array_unshift($rows,['name'=>'','department'=>'','phone'=>$lead['phone'],'email'=>$lead['email'],'role'=>$lead['contact_role']??'','ownership'=>'unknown']);if($primary!==null)$primary++;}
    }
    if(count($rows)>50)fail('Maximum 50 contacts including legacy fields',422);
    $primary??=0;
    query('UPDATE leads SET contacts_initialized=1 WHERE workspace_id=? AND id=?',[$lead['workspace_id'],$lead['id']]);
    foreach($rows as $index=>$values)insertLeadContact($lead,$values,$index===$primary);
    mirrorPrimaryContact($lead);
}
function legacyPrimaryContactEdit(array $lead,array $values): void {
    if(!array_intersect(array_keys($values),['phone','email','contact_role']))return;
    initialiseLeadContacts($lead);
    $row=query('SELECT * FROM lead_contacts WHERE workspace_id=? AND lead_id=? AND is_primary=1',[$lead['workspace_id'],$lead['id']])->fetch();
    $phone=$values['phone']??$lead['phone'];$email=$values['email']??$lead['email'];$tag=$values['contact_role']??($lead['contact_role']??'');
    // A legacy client can clear both channels. Keep its labelled row for history/editing.
    if($row)query('UPDATE lead_contacts SET phone=?,email=?,role=? WHERE workspace_id=? AND lead_id=? AND id=?',[$phone,$email,$tag,$lead['workspace_id'],$lead['id'],$row['id']]);
    elseif($phone!==''||$email!=='')insertLeadContact($lead,['name'=>'','department'=>'','phone'=>$phone,'email'=>$email,'role'=>$tag,'ownership'=>'unknown'],true);
}
function editLeadContacts(string $id,array $args): array {
    requireRole(['owner','manager','salesperson']);
    foreach(array_keys($args) as $key)if(!in_array($key,['version','operations','provenance','source_activity_id'],true))fail('Unexpected contact argument',422);
    $version=$args['version']??null;$ops=$args['operations']??null;$provenance=textField($args,'provenance',2000,true);
    if(!is_int($version)||$version<1||!is_array($ops)||!array_is_list($ops)||count($ops)<1||count($ops)>50)fail('Supply current lead version and 1–50 contact operations',422);
    beginWorkTransaction();
    try{
        requireRole(['owner','manager','salesperson']);
        $lead=query('SELECT * FROM leads WHERE workspace_id=? AND id=? FOR UPDATE',[workspace(),$id])->fetch();
        if(!$lead||!canAccessLead($lead))fail('Lead not found',404);
        if((int)$lead['version']!==$version)fail('This lead changed. Reload before editing contacts.',409);
        $source=$args['source_activity_id']??null;
        if($source!==null){
            if(!is_string($source)||!preg_match('/^[a-f0-9]{32}$/',$source))fail('Invalid source activity',422);
            if(!query('SELECT id FROM activities WHERE workspace_id=? AND lead_id=? AND id=?',[workspace(),$id,$source])->fetch())fail('Source activity not found',404);
            $work=query('SELECT status FROM agent_work_items WHERE workspace_id=? AND source_activity_id=?',[workspace(),$source])->fetch();
            if($work){if($work['status']!=='pending')fail('Resolved work cannot authorize another contact change',403);requireQueuedRequester(['source_activity_id'=>$source,'lead_id'=>$id]);}
        }
        materialiseLeadRecords($lead);$before=leadContactRows(workspace(),$id);
        foreach($ops as $op){
            if(!is_array($op))fail('Invalid contact operation',422);
            foreach(array_keys($op) as $key)if(!in_array($key,['action','contact_id','contact'],true))fail('Unexpected contact operation field',422);
            $action=$op['action']??'';if(!in_array($action,['add','update','remove','set_primary'],true))fail('Invalid contact action',422);
            if($action==='add'){
                if(isset($op['contact_id']))fail('New contacts receive server IDs',422);
                if(count(leadContactRows(workspace(),$id))>=50)fail('Maximum 50 contacts per lead',422);
                $values=contactValues($op['contact']??null);$primary=$op['contact']['is_primary']??false;
                if($primary)query('UPDATE lead_contacts SET is_primary=NULL WHERE workspace_id=? AND lead_id=?',[workspace(),$id]);
                insertLeadContact($lead,$values,$primary);continue;
            }
            $contactId=$op['contact_id']??null;if(!is_string($contactId)||!preg_match('/^[a-f0-9]{32}$/',$contactId))fail('Supply contact_id from get_lead',422);
            $row=query('SELECT * FROM lead_contacts WHERE workspace_id=? AND lead_id=? AND id=?',[workspace(),$id,$contactId])->fetch();if(!$row)fail('Contact not found',404);
            if($action==='update'){
                if(!isset($op['contact'])||!is_array($op['contact']))fail('Contact must be an object',422);
                if(array_key_exists('is_primary',$op['contact']??[]))fail('Use set_primary to change primary contact',422);
                $values=contactValues($op['contact']??null,$row);
                query('UPDATE lead_contacts SET name=?,department=?,phone=?,email=?,role=?,ownership=? WHERE workspace_id=? AND lead_id=? AND id=?',array_merge(array_values($values),[workspace(),$id,$contactId]));
            }else{
                if(isset($op['contact']))fail('Unexpected contact data for this action',422);
                if($action==='remove')query('DELETE FROM lead_contacts WHERE workspace_id=? AND lead_id=? AND id=?',[workspace(),$id,$contactId]);
                else{query('UPDATE lead_contacts SET is_primary=NULL WHERE workspace_id=? AND lead_id=?',[workspace(),$id]);query('UPDATE lead_contacts SET is_primary=1 WHERE workspace_id=? AND lead_id=? AND id=?',[workspace(),$id,$contactId]);}
            }
        }
        mirrorPrimaryContact($lead);query('UPDATE leads SET version=version+1 WHERE workspace_id=? AND id=?',[workspace(),$id]);
        $after=leadContactRows(workspace(),$id);audit($id,'contacts_updated',['before'=>$before,'after'=>$after,'provenance'=>$provenance,'source_activity_id'=>$source,'actor_user_id'=>$_SESSION['user_id'],'verification'=>'Supplied details; not independently verified']);
        db()->commit();return ['ok'=>true,'version'=>$version+1,'contacts'=>$after];
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
}
