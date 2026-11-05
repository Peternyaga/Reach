import './style.css';
import React,{useState,useEffect,useRef} from 'react';
import {createRoot} from 'react-dom/client';
import {SetupOwner,Signup,AccessPage,TeamPanel} from './account.jsx';
import {Sidebar} from './sidebar.jsx';
import {ConnectionsPanel} from './connections.jsx';
import {AgentReportsPanel} from './agent-reports.jsx';
import {AutomationPairPage} from './automation-pair.jsx';
import {PhoneActions} from './phone-actions.jsx';
import {ProfileDialog,ProfileEssentials, ProfileDisclosure,ProfileHistory,RecentContext} from './lead-profile.jsx';
import {ResearchBrief} from './research-brief.jsx';
import {AgentRequestForm} from './agent-request.jsx';
import {LeadEmail} from './mailbox.jsx';
import {CloseLead} from './close-lead.jsx';
import {LeadCapture} from './lead-capture.jsx';
import {AssignmentQueue} from './assignment-queue.jsx';
import {ProjectMove} from './projects.jsx';
import {ConnectCodexGuide} from './connect-codex-guide.jsx';
import {requestKey} from './request-key.js';
import {startWorkspaceSync,installDraftProtection,hasWorkspaceDrafts,workspaceChanged,workspaceRefreshed} from './workspace-sync.js';
import {LeadWorkspace,leadTitle,personLabel,ResearchStatus} from './reach-workspace.jsx';
import {LeadsView,ReachLeadTable,TodayTasks} from './reach-views.jsx';

import './import.css';
import './agent-reports.css';
let csrf='',writesPending=0,requestProject='';
async function api(path,body,options={}){
 const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
 const abort=()=>controller.abort();options.signal?.addEventListener('abort',abort,{once:true});
 if(options.signal?.aborted)controller.abort();if(body)writesPending++;
 let saved=false;
 try{const r=await fetch('/api'+path,{method:body?'POST':'GET',headers:{...(requestProject?{'X-Fieldwork-Project':requestProject}:{}),...(body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{})},body:body?JSON.stringify(body):undefined,signal:controller.signal,cache:'no-store'});const data=await r.json();if(!r.ok){const error=new Error(data.error||'Please try again');error.matches=data.matches;error.status=r.status;throw error}saved=!!body;return data;}
 finally{clearTimeout(timeout);options.signal?.removeEventListener('abort',abort);if(body)writesPending--;if(saved)workspaceChanged();}
}
const stages=['New','Contacted','Engaged','Qualified','Proposal','Negotiation','Won','Lost'];
const date=value=>{if(!value)return 'No follow-up';const d=new Date(value.replace(' ','T')+(value.endsWith('Z')?'':'Z'));return d.toLocaleString([], {month:'short',day:'numeric',...(d.getFullYear()!==new Date().getFullYear()?{year:'numeric'}:{}),hour:'numeric',minute:'2-digit'})};
function Field({label,...props}){return <label className="field"><span>{label}</span><input {...props}/></label>}
function App(){
 const [session,setSession]=useState(null),[ready,setReady]=useState(false),[error,setError]=useState(''),[busy,setBusy]=useState(false),[view,setView]=useState('Today'),[projects,setProjects]=useState([]),[projectId,setProjectId]=useState(''),[summary,setSummary]=useState(null),[leads,setLeads]=useState([]),[opportunities,setOpportunities]=useState([]),[tasks,setTasks]=useState([]),[team,setTeam]=useState([]),[search,setSearch]=useState(''),[detail,setDetail]=useState(null),[adding,setAdding]=useState(false),[teamAdmin,setTeamAdmin]=useState(false),[projectTeamOpen,setProjectTeamOpen]=useState(false),[connectionsOpen,setConnectionsOpen]=useState(false),[agentReportsOpen,setAgentReportsOpen]=useState(false),[pairId,setPairId]=useState(()=>location.hash.startsWith('#automation=')?location.hash.slice(12):''),[bootstrap,setBootstrap]=useState(false),[accessToken,setAccessToken]=useState(()=>location.hash.startsWith('#account=')?location.hash.slice(9):''),[notice,setNotice]=useState('');
 const [signup,setSignup]=useState(()=>location.pathname==='/signup');
 const [syncStatus,setSyncStatus]=useState('live');
 const foreground=useRef(0),manualLoads=useRef(0),loadEpoch=useRef(0),scope=useRef({}),syncRefresh=useRef(null);
 scope.current={session,projectId,detailId:detail?.lead.id};
 async function load(currentRole=session?.role,preferredProject=projectId,{background=false,signal}={}){
  if(background&&(foreground.current||manualLoads.current||writesPending||hasWorkspaceDrafts()))return false;
  if(!background)manualLoads.current++;
  try{
  const epoch=++loadEpoch.current,identity=scope.current.session?.id;
  const available=await api('/projects',undefined,{signal});
  const active=available.some(project=>project.id===preferredProject)?preferredProject:available[0]?.id||'';
  requestProject=active;localStorage.setItem('fieldwork-project-'+scope.current.session?.id,active);
  const filter=active?'?project='+encodeURIComponent(active):'';
  const [totals,l,t,m,o]=await Promise.all([api('/project-summary'+filter,undefined,{signal}),api('/leads'+filter,undefined,{signal}),currentRole==='collector'?Promise.resolve([]):api('/tasks'+filter,undefined,{signal}),['owner','manager'].includes(currentRole)?api('/team',undefined,{signal}):Promise.resolve([]),currentRole==='collector'?Promise.resolve([]):api('/opportunities'+filter,undefined,{signal})]);
  if(signal?.aborted||epoch!==loadEpoch.current||(identity&&identity!==scope.current.session?.id)||(background&&(foreground.current||manualLoads.current||writesPending||hasWorkspaceDrafts())))return false;
  scope.current.projectId=active;setProjects(available);setProjectId(active);setSummary(totals);setLeads(l);setTasks(t);setTeam(m);setOpportunities(o);return true;
  }finally{if(!background)manualLoads.current--;}
 }
 async function run(fn){loadEpoch.current++;foreground.current++;setError('');setBusy(true);try{await fn()}catch(e){setError(e.message)}finally{foreground.current--;setBusy(foreground.current>0);workspaceChanged()}}
 useEffect(()=>installDraftProtection(),[]);
 syncRefresh.current=async(signal,result)=>{
  const before={...scope.current},started=loadEpoch.current;
  const identityChanged=result.user&&(result.user.id!==before.session?.id||result.user.role!==before.session?.role);
  if(!identityChanged&&(foreground.current||manualLoads.current||writesPending||hasWorkspaceDrafts()))return false;
  const fresh=await api('/session',undefined,{signal});
  if(!fresh.user){const error=new Error('Sign in to continue');error.status=401;throw error;}
  if(signal.aborted||before.session?.id!==scope.current.session?.id)return false;
  if(!identityChanged&&(started!==loadEpoch.current||before.projectId!==scope.current.projectId||foreground.current||manualLoads.current||writesPending||hasWorkspaceDrafts()))return false;
  csrf=fresh.csrf;
  if(fresh.user.id!==before.session.id||fresh.user.role!==before.session.role){
   loadEpoch.current++;scope.current.session=fresh.user;setSession(fresh.user);setDetail(null);setAdding(false);setTeamAdmin(false);setConnectionsOpen(false);setAgentReportsOpen(false);setLeads([]);setTasks([]);setOpportunities([]);setTeam([]);setProjects([]);setView('Today');
  }else if(fresh.user.name!==before.session.name||fresh.user.workspace!==before.session.workspace)setSession(fresh.user);
  if(!await load(fresh.user.role,before.projectId,{background:true,signal}))return false;
  const epoch=loadEpoch.current,id=before.detailId;
  if(id&&fresh.user.id===before.session.id&&fresh.user.role===before.session.role){
   try{
    const next=await api('/leads/'+id,undefined,{signal});
    if(signal.aborted||epoch!==loadEpoch.current||scope.current.detailId!==id||foreground.current||writesPending||hasWorkspaceDrafts())return false;
    setDetail(next);
   }catch(error){
    if(error.status!==404)throw error;
    if(epoch!==loadEpoch.current||scope.current.detailId!==id||hasWorkspaceDrafts())return false;
    setDetail(null);setError('This lead is no longer available to your account.');
   }
  }
  workspaceRefreshed();return true;
 };
 useEffect(()=>{
  if(!ready||!session)return;
  function signedOut(){loadEpoch.current++;setSession(null);setDetail(null);setLeads([]);setTasks([]);setOpportunities([]);setTeam([]);setProjects([]);setAdding(false);setTeamAdmin(false);setConnectionsOpen(false);setAgentReportsOpen(false);setNotice('Your session ended. Sign in to continue.');}
  return startWorkspaceSync({
   read:async(etag,signal)=>{
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),10000);
    const abort=()=>controller.abort();signal.addEventListener('abort',abort,{once:true});if(signal.aborted)controller.abort();
    try{const r=await fetch('/api/sync',{headers:{...(requestProject?{'X-Fieldwork-Project':requestProject}:{}),...(etag?{'If-None-Match':etag}:{})},signal:controller.signal,cache:'no-store'});const data=r.status===200?await r.json():{};return {status:r.status,etag:data.revision||r.headers.get('ETag'),user:data.user};}
    finally{clearTimeout(timeout);signal.removeEventListener('abort',abort)}
   },
   refresh:async(signal,result)=>{try{return await syncRefresh.current(signal,result)}catch(error){if(error.status===401){signedOut();return true}throw error}},
   status:state=>setSyncStatus(state==='deferred'&&!hasWorkspaceDrafts()?'live':state),unauthorized:signedOut
  });
 },[ready,session?.id,session?.role]);
 useEffect(()=>{run(async()=>{const s=await api('/session');csrf=s.csrf;setSession(s.user);if(s.user)await load(s.user.role,localStorage.getItem('fieldwork-project-'+s.user.id)||'');else setBootstrap((await api('/bootstrap-status')).available);setReady(true)})},[]);
 function closeLead(){history.pushState(null,'',location.pathname+location.search+'#leads');setDetail(null);setView('Leads')}
 async function open(id){const project=scope.current.projectId;await run(async()=>{const next=await api('/leads/'+id);if(project===scope.current.projectId&&next.lead.project_id===project){history.pushState(null,'',`#project=${project}&lead=${id}`);setDetail(next)}})}
 useEffect(()=>{
  if(!ready||!session)return;
  async function route(){
   if(location.hash.startsWith('#account=')||location.hash.startsWith('#automation='))return;
   const params=new URLSearchParams(location.hash.slice(1)),pid=params.get('project'),id=params.get('lead');
   if(hasWorkspaceDrafts()&&!confirm('Discard unsaved changes?')){if(scope.current.detailId)history.pushState(null,'',`#project=${scope.current.projectId}&lead=${scope.current.detailId}`);return}
   if(!id){setDetail(null);return}
   if(!/^[a-f0-9]{32}$/.test(id)||!pid||!/^[a-f0-9]{32}$/.test(pid)){setError('Invalid lead link.');return}
   await run(async()=>{await load(session.role,pid);if(scope.current.projectId!==pid)throw new Error('This project is not available.');const next=await api('/leads/'+id);if(next.lead.project_id!==pid)throw new Error('This lead is not in the linked project.');setDetail(next);setView('Leads')});
  }
  const handle=()=>{route().catch(e=>setError(e.message))};window.addEventListener('popstate',handle);window.addEventListener('hashchange',handle);handle();return()=>{window.removeEventListener('popstate',handle);window.removeEventListener('hashchange',handle)}
 },[ready,session?.id]);
 async function refreshDetail(){await load();if(detail)setDetail(await api('/leads/'+detail.lead.id))}
 const pending=tasks.filter(t=>t.status==='pending'),filtered=leads.filter(l=>`${l.name} ${l.organisation} ${l.owner} ${l.offering}`.toLowerCase().includes(search.toLowerCase()));
 if(!ready)return <div className="splash">Opening your workspace…{error&&<p role="alert">{error}</p>}</div>;
 if(accessToken)return <AccessPage api={api} token={accessToken} onCancel={()=>{history.replaceState(null,'',location.pathname+location.search);setAccessToken('')}} onDone={async()=>{if(session){await api('/logout',{});const s=await api('/session');csrf=s.csrf}history.replaceState(null,'',location.pathname+location.search);setAccessToken('');setSession(null);setNotice('Password saved. Sign in with your new password.')}}/>;
 if(!session&&signup)return <Signup api={api} onCancel={()=>{setSignup(false);history.replaceState(null,'','/')}} onReady={async()=>{const s=await api('/session');csrf=s.csrf;setSession(s.user);setSignup(false);setBootstrap(false);history.replaceState(null,'','/');await load(s.user.role,'')}}/>;
 if(bootstrap)return <SetupOwner api={api} onReady={async()=>{const s=await api('/session');csrf=s.csrf;setSession(s.user);setBootstrap(false);await load(s.user.role,localStorage.getItem('fieldwork-project-'+s.user.id)||'')}}/>;
 if(!session)return <main className="login"><div className="login-story"><div className="brand">◈ &nbsp; Fieldwork</div><h1>Good relationships.<br/>Thoughtful follow-through.</h1><p>Your leads, conversations and next steps.<br/>One calm place to move work forward.</p><small>SALES WORKSPACE</small></div><form className="login-card" onSubmit={e=>{e.preventDefault();const d=Object.fromEntries(new FormData(e.target));run(async()=>{await api('/login',d);const s=await api('/session');csrf=s.csrf;setSession(s.user);setView('Today');await load(s.user.role,localStorage.getItem('fieldwork-project-'+s.user.id)||'')})}}><span className="eyebrow">WELCOME BACK</span><h2>Open your workspace</h2><p>Sign in with your Fieldwork account.</p>{notice&&<p className="account-notice" role="status">{notice}</p>}<Field label="Email address" name="email" type="email" autoComplete="username" required/><Field label="Password" name="password" type="password" autoComplete="current-password" required/>{error&&<p className="error" role="alert">{error}</p>}<button disabled={busy}>Sign in →</button><a className="signup-link" href="/signup" onClick={e=>{e.preventDefault();setError('');setSignup(true);history.replaceState(null,'','/signup')}}>Create account</a><small className="login-help">Forgot your password? Ask your workspace owner for a recovery link.</small></form></main>;
 if(pairId)return <AutomationPairPage api={api} id={pairId} onDone={()=>{history.replaceState(null,'',location.pathname+location.search);setPairId('')}}/>;
 return <div className="shell" aria-busy={busy}><Sidebar busy={busy} session={session} projects={projects} projectId={projectId} view={view} pendingCount={summary?.pending_tasks??pending.length} api={api} onSelectProject={id=>{if(hasWorkspaceDrafts()&&!confirm('Discard unsaved changes?'))return;history.pushState(null,'',location.pathname+location.search);return run(async()=>{scope.current.projectId=id;setDetail(null);setAdding(false);setAgentReportsOpen(false);setConnectionsOpen(false);setSearch('');setSummary(null);setLeads([]);setTasks([]);setOpportunities([]);await load(session.role,id)})}} onCreateProject={async name=>{const project=await api('/projects',{name});await load(session.role,project.id)}} onNavigate={next=>{if(hasWorkspaceDrafts()&&!confirm('Discard unsaved changes?'))return;setView(next)}} onTeam={()=>setTeamAdmin(true)} onConnections={()=>setConnectionsOpen(true)} onAgentReports={()=>setAgentReportsOpen(true)} onSignOut={()=>run(async()=>{await api('/logout',{});const s=await api('/session');csrf=s.csrf;setSession(null);setDetail(null);setView('Today');setTeamAdmin(false)})}/><main className="content"><header><span>{projects.find(p=>p.id===projectId)?.name||'WORKSPACE'} / {view.toUpperCase()}</span>{['owner','manager'].includes(session.role)&&projectId&&<button className="text-btn" type="button" onClick={()=>setProjectTeamOpen(true)}>Project members</button>}<div className="avatar">{session.name.slice(0,1)}</div></header><section className="page-heading"><div><div className="eyebrow">{new Date().toLocaleDateString([], {weekday:'long',month:'long',day:'numeric'}).toUpperCase()}</div><h1>{view}</h1><p>{view==='Today'?'Overdue work, today’s tasks and upcoming follow-ups.':view==='Leads'?`${leads.length} leads loaded · ${projects.find(p=>p.id===projectId)?.name||'Selected project'}`:view==='Intake'?'Import a collection list, review duplicates, then add the ready leads.':view==='Pipeline'?'Commercial progress, separate from reminders and call outcomes.':'Internal follow-ups in your local timezone. These are not customer bookings.'}</p></div><button disabled={busy||!projectId} onClick={()=>setAdding(true)}>＋ Add lead</button></section>{['deferred','offline','reconnecting'].includes(syncStatus)&&<p className="sync-notice" role="status">{syncStatus==='deferred'?'Updates are waiting. Finish or cancel your edit and they will appear automatically.':'Connection interrupted. Updates will resume automatically when connected.'}</p>}{error&&<div className="error banner" role="alert">{error}<button className="text-btn" onClick={()=>setError('')}>Dismiss</button></div>}
 {view==='Today'&&session.role==='collector'&&<><div className="stats"><article><span>Submitted leads</span><strong>{(summary?.submitted_leads??leads.length).toString().padStart(2,'0')}</strong><small>Your collection records</small></article><article><span>Awaiting handoff</span><strong>{(summary?.unassigned_leads??leads.filter(l=>!l.owner_user_id).length).toString().padStart(2,'0')}</strong><small>Awaiting an owner</small></article></div><div className="section-top"><h2>Recently collected</h2><button className="text-btn" onClick={()=>setView('Leads')}>All collected leads ↗</button></div><LeadTable leads={leads.slice(0,5)} open={open}/></>}
 {view==='Today'&&session.role!=='collector'&&<><div className="stats">{[['Active leads',summary?.active_leads??leads.filter(l=>!['Won','Lost'].includes(l.stage)).length,'Relationships in progress'],['Open follow-ups',summary?.pending_tasks??pending.length,'Your next conversations'],['Needs an owner',summary?.unassigned_leads??leads.filter(l=>!l.owner_user_id).length,'Research and sales ownership']].map(([label,n,sub])=><article key={label}><span>{label}</span><strong>{n.toString().padStart(2,'0')}</strong><small>{sub}</small></article>)}</div><div className="section-top"><h2>Your next steps</h2><button className="text-btn" onClick={()=>setView('Schedule')}>View schedule ↗</button></div><TodayTasks tasks={pending} TaskList={TaskList} open={open} cancel={(id,reason)=>run(async()=>{await api('/tasks/'+id+'/cancel',{reason});await load()})} complete={id=>run(async()=>{await api('/tasks/'+id+'/complete',{});await load()})} busy={busy}/>{leads.some(l=>l.research?.research_status==='ready_for_review')&&<div className="review-entry"><span>{leads.filter(l=>l.research?.research_status==='ready_for_review').length} leads ready for human research review (loaded records)</span><button className="text-btn" onClick={()=>{sessionStorage.setItem(`reach-lead-view-${session.id}-${projectId}`,JSON.stringify({research:'ready_for_review'}));setView('Leads')}}>Review research →</button></div>}<div className="section-top"><h2>Next relationships</h2><button className="text-btn" onClick={()=>setView('Leads')}>All leads ↗</button></div><LeadTable leads={leads.slice(0,5)} open={open}/></>}
 {view==='Leads'&&<LeadsView key={session.id+projectId} leads={leads} open={open} date={date} projectId={projectId} userId={session.id}/>}
 {view==='Intake'&&<><AssignmentQueue leads={leads} team={team} open={open} assign={(lead,user_id,first_contact_at,options={})=>run(async()=>{await api('/leads/'+lead.id+'/assign',{user_id,first_contact_at,version:Number(lead.version),...options});await load()})} busy={busy}/><ImportPanel key={projectId} projectId={projectId} refresh={()=>load(session.role,projectId)}/></>}
 {view==='Pipeline'&&<div className="pipeline">{stages.map(s=><section key={s}><h3>{s}<span>{summary?.opportunity_stages?.[s]??opportunities.filter(o=>o.stage===s).length}</span></h3>{opportunities.filter(o=>o.stage===s).map(o=><button className="deal" key={o.id} onClick={()=>open(o.lead_id)}><strong>{o.offering}</strong><span>{leadTitle(o)}{personLabel(o)!=='Contact person not yet identified'?' · '+personLabel(o):''}</span><small>{o.owner_user_id?o.owner:'Unassigned'}</small></button>)}</section>)}</div>}
 {view==='Schedule'&&<TaskList items={tasks} open={open} cancel={(id,reason)=>run(async()=>{await api('/tasks/'+id+'/cancel',{reason});await load()})} complete={id=>run(async()=>{await api('/tasks/'+id+'/complete',{});await load()})} busy={busy}/>}
 <footer>Built for thoughtful follow-through. <span>All times displayed in your device timezone.</span></footer></main>{projectTeamOpen&&<TeamPanel api={api} projectId={projectId} canManage={session.role==='owner'} close={()=>setProjectTeamOpen(false)}/>}{teamAdmin&&<TeamPanel api={api} close={()=>setTeamAdmin(false)}/>}{connectionsOpen&&<ConnectionsPanel key={projectId} projectName={projects.find(p=>p.id===projectId)?.name} role={session.role} api={api} close={()=>setConnectionsOpen(false)}/>} {agentReportsOpen&&<AgentReportsPanel key={projectId} projectId={projectId} projectName={projects.find(p=>p.id===projectId)?.name} api={api} openLead={async(id,target)=>{setAgentReportsOpen(false);if(target&&target!==projectId)await load(session.role,target);open(id)}} close={()=>setAgentReportsOpen(false)}/>}
 {adding&&<LeadCapture role={session.role} api={api} projectId={projectId} onClose={()=>setAdding(false)} onCreated={()=>load(session.role,projectId)} onOpen={async id=>{await open(id);setAdding(false)}}/>}
 {detail&&session.role==='collector'?<CollectorDetail detail={detail} close={closeLead}/>:detail&&<LeadWorkspace key={detail.lead.id} detail={detail} api={api} session={session} team={team} projects={projects} projectName={projects.find(p=>p.id===projectId)?.name} onChanged={refreshDetail} onClose={closeLead} onMove={target=>run(async()=>{await api('/leads/'+detail.lead.id+'/project',{project_id:target,version:Number(detail.lead.version)});history.pushState(null,'','#leads');setDetail(null);await load(session.role,target)})} busy={busy} date={date} TaskList={TaskList}/>}
 </div>;
}
function LeadTable({leads,open}){return <ReachLeadTable leads={leads} open={open} date={date}/>}
function CollectorDetail({detail,close}){const lead=detail.lead;return <ProfileDialog close={close} titleId="collector-detail-title"><span className="eyebrow">COLLECTION RECORD</span><h2 id="collector-detail-title">{leadTitle(lead)}</h2><p>{personLabel(lead)}</p><div className="contact-info"><span>Source: {lead.source||'Not recorded'}</span><span>Offering: {lead.offering||'Not recorded'}</span><span>Collected by: {lead.collector||'Not recorded'}</span></div><h3>Handoff status</h3><div className="assigned-readout">{lead.owner_user_id?`Assigned to ${lead.owner}`:'Awaiting assignment'}</div><p className="collector-explainer">Your collection record is saved. The sales team manages follow-ups and private conversation notes after handoff.</p></ProfileDialog>}
function TaskList({items,open,complete,cancel,busy,defaultPhone}){
 const [cancelling,setCancelling]=useState(null),[reason,setReason]=useState('');
 return <div className="task-list">{!items.length?<div className="empty compact"><h3>Nothing waiting here</h3><p>Ask the agent to plan a follow-up from a lead’s profile when you’re ready.</p></div>:items.map(t=><article key={t.id} className={t.status==='cancelled'?'task-cancelled':''}>
  <div className="task-row">
   {t.status==='pending'?<button className="check" aria-label={'Complete '+t.title} disabled={busy} onClick={()=>complete(t.id)}></button>:<span className={'task-status '+t.status}>{t.status==='completed'?'Done':'Cancelled'}</span>}
   <button className="task-title" onClick={()=>open(t.lead_id)}><strong>{t.title}</strong><small>{leadTitle(t)||t.owner}{t.organisation&&t.name&&t.name.trim().toLowerCase()!==t.organisation.trim().toLowerCase()?' · '+t.name:''}</small></button>
   <PhoneActions phone={t.phone||defaultPhone} compact/>
   <span className={t.status==='pending'&&new Date(t.due_at.replace(' ','T')+'Z')<new Date()?'due overdue':'due'}>{date(t.due_at)}</span>
   {t.status==='pending'&&<button type="button" className="task-cancel-action" disabled={busy} onClick={()=>{setCancelling(t.id);setReason('')}}>Cancel</button>}
  </div>
  {cancelling===t.id&&t.status==='pending'&&<form data-sync-lock className="task-cancel-form" onSubmit={event=>{event.preventDefault();cancel(t.id,reason);setCancelling(null);setReason('')}}><label><span>Why is this task cancelled?</span><input value={reason} onChange={event=>setReason(event.target.value)} maxLength="1000" placeholder="Lead closed; follow-up no longer needed" required/></label><button type="submit" disabled={busy||!reason.trim()}>Cancel task</button><button type="button" className="secondary" onClick={()=>setCancelling(null)}>Keep task</button></form>}
 </article>)}</div>;
}

function ImportPanel({refresh,projectId}){const [csv,setCsv]=useState(''),[batch,setBatch]=useState(''),[rows,setRows]=useState(null),[result,setResult]=useState(null),[busy,setBusy]=useState(false),[error,setError]=useState('');async function choose(e){setRows(null);setResult(null);setError('');const file=e.target.files?.[0];if(!file)return;if(file.size>900000){setError('Choose a CSV smaller than 900 KB.');return}setCsv(await file.text());setBatch(requestKey())}async function act(fn){setBusy(true);setError('');try{await fn()}catch(e){setError(e.message)}finally{setBusy(false)}}const ready=rows?.filter(r=>r.status==='ready').length||0;return <div className="import-panel" data-sync-lock={csv&&!result?'':undefined}><div className="import-intro"><span className="eyebrow">COLLECTION TEAM HANDOFF</span><h2>Import and review</h2><p>Start with a CSV headed <code>name</code>. Optional columns: organisation, email, phone, source, offering, owner, collector and notes. Duplicates are held back for review.</p><label className="file-pick">Choose a CSV file<input type="file" accept=".csv,text/csv" onChange={choose}/></label><small>The preview stays local until you choose to import. No messages are sent.</small></div>{csv&&!rows&&<button disabled={busy} onClick={()=>act(async()=>{const d=await api('/import/preview',{csv,project_id:projectId});setRows(d.rows)})}>Preview leads →</button>}{error&&<p className="error" role="alert">{error}</p>}{rows&&<><div className="import-summary"><strong>{ready} ready</strong><span>{rows.filter(r=>r.status==='duplicate_existing'||r.status==='duplicate_in_file').length} duplicates</span><span>{rows.filter(r=>r.status==='invalid').length} need correction</span></div><div className="table-card"><table><thead><tr><th>Row</th><th>Name</th><th>Organisation</th><th>Status</th><th>Notes</th></tr></thead><tbody>{rows.map(r=><tr key={r.line}><td>{r.line}</td><td>{r.values?.name||'—'}</td><td>{r.values?.organisation||'—'}</td><td><span className={'badge '+(r.status==='ready'?'won':'lost')}>{r.status.replaceAll('_',' ')}</span></td><td>{r.reason||'Ready to add'}</td></tr>)}</tbody></table></div><div className="import-actions"><span>Review the list before adding the ready leads.</span><button disabled={busy||!ready||!!result} onClick={()=>act(async()=>{const d=await api('/import/commit',{csv,batch_key:batch,project_id:projectId});setResult(d.rows);await refresh()})}>Import {ready} ready leads →</button></div></>}{result&&<p className="success" role="status">Imported {result.filter(r=>r.status==='imported').length} leads. Duplicates and invalid rows were left unchanged.</p>}</div>}
createRoot(document.getElementById('root')).render(['/connect-chatgpt','/connect-codex'].includes(location.pathname.replace(/\/$/,''))?<ConnectCodexGuide/>:<App/>);
