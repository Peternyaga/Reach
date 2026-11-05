<?php
declare(strict_types=1);
require_once __DIR__.'/lead-records.php';
require_once __DIR__.'/projects.php';
function applySchema(): void {
    foreach(explode(';',file_get_contents(__DIR__.'/schema.sql')) as $sql)if(trim($sql))db()->exec($sql);
    $database=db()->query('SELECT DATABASE()')->fetchColumn();
    // Research revisions retain both documents without truncating historical evidence.
    $activityType=query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='activities' AND COLUMN_NAME='body'",[$database])->fetchColumn();
    if($activityType==='text')db()->exec('ALTER TABLE activities MODIFY COLUMN body MEDIUMTEXT NOT NULL');
    foreach([
        ['users','role',"VARCHAR(20) NOT NULL DEFAULT 'owner'"],
        ['users','active','TINYINT(1) NOT NULL DEFAULT 1'],
        ['users','setup_pending','TINYINT(1) NOT NULL DEFAULT 0'],
        ['users','auth_version','INT NOT NULL DEFAULT 1'],
        ['users','access_scope',"VARCHAR(20) NOT NULL DEFAULT 'workspace'"],
        ['users','membership_version','INT NOT NULL DEFAULT 1'],
        ['oauth_codes','auth_version','INT NOT NULL DEFAULT 0'],
        ['plugin_tokens','last_used_at','DATETIME NULL'],
        ['leads','contacts_initialized','TINYINT NOT NULL DEFAULT 0'],
        ['leads','owner_user_id','CHAR(32) NULL'],
        ['leads','collector_user_id','CHAR(32) NULL'],
        ['leads','project_id','CHAR(32) NULL'],
        ['contacts','project_id','CHAR(32) NULL'],
        ['organisations','project_id','CHAR(32) NULL'],
        ['organisations','origin_organisation_id','CHAR(32) NULL'],
        ['outbound_emails','project_id','CHAR(32) NULL'],
        ['outbound_emails','account_id','CHAR(32) NULL'],
        ['outbound_emails','sender_email',"VARCHAR(254) NULL"],
        ['outbound_emails','sender_revision','INT NULL'],
        ['leads','website',"VARCHAR(500) NOT NULL DEFAULT ''"],
        ['leads','facebook',"VARCHAR(500) NOT NULL DEFAULT ''"],
        ['leads','instagram',"VARCHAR(500) NOT NULL DEFAULT ''"],
        ['leads','linkedin',"VARCHAR(500) NOT NULL DEFAULT ''"],
        ['leads','contact_role',"VARCHAR(150) NOT NULL DEFAULT ''"],
        ['leads','location',"VARCHAR(250) NOT NULL DEFAULT ''"],
        ['opportunities','version','INT NOT NULL DEFAULT 1'],
        ['opportunities','request_key','VARCHAR(80) NULL'],
        ['oauth_grants','created_at','TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'],
        ['agent_work_items','parent_work_id','CHAR(32) NULL'],
        ['agent_poll_pairings','device_name',"VARCHAR(120) NOT NULL DEFAULT 'Windows computer'"]
    ] as [$table,$column,$type]){
        $found=query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?',[$database,$table,$column])->fetchColumn();
        if(!$found)db()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $type");
    }
    foreach([
        ['users','tenant_user','workspace_id,id'],
        ['leads','assigned','workspace_id,owner_user_id'],
        ['leads','submitted','workspace_id,collector_user_id'],
        ['leads','lead_project','workspace_id,project_id'],
        ['opportunities','opportunity_request','workspace_id,request_key'],
        ['agent_work_items','reversal_once','workspace_id,parent_work_id']
    ] as [$table,$index,$columns]){
        $found=query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?',[$database,$table,$index])->fetchColumn();
        if(!$found)db()->exec("CREATE ".(in_array($index,['opportunity_request','reversal_once'],true)?'UNIQUE ':'')."INDEX `$index` ON `$table` ($columns)");
    }
    foreach(query('SELECT id FROM workspaces')->fetchAll() as $workspace){
        $projectId=defaultProject($workspace['id']);
        query('UPDATE leads SET project_id=? WHERE workspace_id=? AND project_id IS NULL',[$projectId,$workspace['id']]);
    }
    $projectFk=query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME='leads' AND CONSTRAINT_NAME='leads_project_fk'",[$database])->fetchColumn();
    if(!$projectFk)db()->exec('ALTER TABLE leads ADD CONSTRAINT leads_project_fk FOREIGN KEY (workspace_id,project_id) REFERENCES projects(workspace_id,id)');
    db()->beginTransaction();
    try{
    query('UPDATE contacts c JOIN leads l ON l.workspace_id=c.workspace_id AND l.id=c.source_lead_id SET c.project_id=l.project_id WHERE c.project_id IS NULL');
    // Split any old shared organisation link spanning projects; preserve the old row.
    foreach(query('SELECT c.id,c.workspace_id,c.project_id,c.organisation_id,o.name,o.project_id AS org_project FROM contacts c JOIN organisations o ON o.workspace_id=c.workspace_id AND o.id=c.organisation_id')->fetchAll() as $contact){
        if($contact['org_project']===null)query('UPDATE organisations SET project_id=? WHERE workspace_id=? AND id=? AND project_id IS NULL',[$contact['project_id'],$contact['workspace_id'],$contact['organisation_id']]);
        $actual=query('SELECT project_id FROM organisations WHERE workspace_id=? AND id=?',[$contact['workspace_id'],$contact['organisation_id']])->fetchColumn();
        if($actual!==$contact['project_id']){
            $new=uid();query('INSERT INTO organisations (id,workspace_id,project_id,name,origin_organisation_id) VALUES (?,?,?,?,?)',[$new,$contact['workspace_id'],$contact['project_id'],$contact['name'],$contact['organisation_id']]);
            query('UPDATE contacts SET organisation_id=? WHERE workspace_id=? AND id=?',[$new,$contact['workspace_id'],$contact['id']]);
        }
    }
    foreach(query('SELECT id FROM workspaces')->fetchAll() as $space)query('UPDATE organisations SET project_id=? WHERE workspace_id=? AND project_id IS NULL',[defaultProject($space['id']),$space['id']]);
    db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    foreach(['contacts','organisations'] as $table){
        $constraint=$table.'_project_fk';
        if(!query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME=?',[$database,$table,$constraint])->fetchColumn())db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$constraint` FOREIGN KEY (workspace_id,project_id) REFERENCES projects(workspace_id,id)");
    }
    query('UPDATE outbound_emails e JOIN leads l ON l.workspace_id=e.workspace_id AND l.id=e.lead_id SET e.project_id=l.project_id WHERE e.project_id IS NULL');
    // Copy credentials exactly once; never infer which project should send with them.
    // Legacy rows remain as migration history. Unassigned accounts cannot send.
    db()->beginTransaction();
    try{
        foreach(query('SELECT * FROM mailboxes')->fetchAll() as $old){
            $id=substr(hash('sha256','legacy-mailbox:'.$old['workspace_id'].':'.$old['user_id']),0,32);
            $marker=query('INSERT IGNORE INTO data_migrations (name) VALUES (?)',['legacy-mailbox:'.$id]);
            if($marker->rowCount()!==1)continue;
            query('INSERT IGNORE INTO mail_accounts (id,workspace_id,user_id,smtp_host,smtp_port,security,username,password_cipher,from_email,from_name,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$id,$old['workspace_id'],$old['user_id'],$old['smtp_host'],$old['smtp_port'],$old['security'],$old['username'],$old['password_cipher'],$old['from_email'],$old['from_name'],$old['updated_at']]);
        }
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    // Repeatable backfill: never infer a person or direct ownership from a lead title.
    foreach(query('SELECT * FROM leads WHERE contacts_initialized=0')->fetchAll() as $lead){
        db()->beginTransaction();try{initialiseLeadContacts($lead);db()->commit();}catch(Throwable $e){db()->rollBack();throw $e;}
    }
    // Existing lead rows are retained. Each receives a contact and, when an
    // offering is known, an opportunity without changing the legacy API yet.
    $legacy=query('SELECT l.* FROM leads l LEFT JOIN contacts c ON c.workspace_id=l.workspace_id AND c.source_lead_id=l.id WHERE c.id IS NULL')->fetchAll();
    foreach($legacy as $lead){
        db()->beginTransaction();
        try{materialiseLeadRecords($lead);db()->commit();}
        catch(Throwable $error){db()->rollBack();throw $error;}
    }
}
