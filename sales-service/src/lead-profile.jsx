import React, {useEffect, useRef, useState} from 'react';
import {PhoneActions} from './phone-actions.jsx';
import './lead-profile.css';

export const profileFields = {name:'Lead name / title', organisation:'Organisation', phone:'Primary contact phone', email:'Primary contact email', website:'Website', facebook:'Facebook', instagram:'Instagram', linkedin:'LinkedIn', contact_role:'Primary contact role / tag', location:'Location', source:'Source'};
export function safeLink(value) {
  try { const url=new URL(value); return ['http:','https:'].includes(url.protocol)&&!url.username&&!url.password ? url.href : null; } catch { return null; }
}
export function ProfileDialog({close, children, titleId='detail-title',title='Lead profile',closeLabel='Close lead profile',className=''}) {
  const ref=useRef(null),closeRef=useRef(close);closeRef.current=close;
  useEffect(()=>{
    const previous=document.activeElement, overflow=document.body.style.overflow;
    document.body.style.overflow='hidden';ref.current.querySelector('button')?.focus();
    function key(event) {
      if(event.key==='Escape'){event.preventDefault();closeRef.current();}
      if(event.key==='Tab'){
        const items=[...ref.current.querySelectorAll('button,a[href],input,textarea,select,summary,[tabindex="0"]')].filter(el=>!el.disabled&&el.getClientRects().length);
        const first=items[0],last=items.at(-1);
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus();}
        else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}
      }
    }
    document.addEventListener('keydown',key);
    return ()=>{document.body.style.overflow=overflow;document.removeEventListener('keydown',key);if(previous?.isConnected)previous.focus();};
  },[]);
  return <div className="overlay profile-overlay"><section ref={ref} className={"profile-dialog "+className} role="dialog" aria-modal="true" aria-labelledby={titleId}><div className="profile-top"><span>{title}</span><button type="button" onClick={close} aria-label={closeLabel}>Close ×</button></div><div className="profile-body">{children}</div></section></div>;
}
export function ProfileEssentials({lead, api, onChanged,startEditing=false}) {
  const [editing,setEditing]=useState(startEditing),[saving,setSaving]=useState(false),[error,setError]=useState('');
  return <section className="profile-card"><div className="profile-card-heading"><h3>Lead profile & primary contact</h3><button className="secondary" onClick={()=>{setEditing(!editing);setError('')}}>{editing?'Cancel edit':'Edit profile'}</button></div>
    {!editing ? <><div className="profile-contacts"><div><small>☎ Phone</small><PhoneActions phone={lead.phone}/></div><div><small>✉ Email</small>{lead.email&&/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(lead.email)?<a href={`mailto:${lead.email}`}>{lead.email}</a>:<span>{lead.email||'Not recorded'}</span>}</div>{['website','facebook','instagram','linkedin'].filter(field=>field==='website'||lead[field]).map(field=><div key={field}><small>{field==='website'?'↗':'◎'} {profileFields[field]}</small>{safeLink(lead[field])?<a href={safeLink(lead[field])} target="_blank" rel="noopener noreferrer">{lead[field]} ↗</a>:<span>{lead[field]||'Not recorded'}</span>}</div>)}</div><dl className="profile-facts">{['contact_role','location','source'].map(field=><div key={field}><dt>{profileFields[field]}</dt><dd>{lead[field]||'Not recorded'}</dd></div>)}<div><dt>Collected by</dt><dd>{lead.collector||'Unknown'}</dd></div></dl><small>Contact details may be unverified. Review their provenance in history before outreach.</small></> : <form data-sync-lock onSubmit={async event=>{
      event.preventDefault();const data=Object.fromEntries(new FormData(event.target));const provenance=data.provenance;delete data.provenance;
      const changes=Object.fromEntries(Object.entries(data).filter(([key,value])=>value!==(lead[key]||'')));
      if(!Object.keys(changes).length){setEditing(false);return;}
      setSaving(true);setError('');try {await api(`/leads/${lead.id}/profile`,{version:Number(lead.version),changes,provenance});await onChanged();setEditing(false);}catch(error){setError(error.message);}finally{setSaving(false);}
    }}><div className="form-grid">{Object.entries(profileFields).map(([field,label])=><label className="field" key={field}><span>{label}</span><input name={field} defaultValue={lead[field]||''} required={field==='name'} maxLength={['website','facebook','instagram','linkedin'].includes(field)?500:field==='email'?254:field==='phone'?50:field==='location'?250:150} type={field==='email'?'email':['website','facebook','instagram','linkedin'].includes(field)?'url':'text'}/></label>)}</div><label className="field"><span>Where did these corrections come from?</span><textarea name="provenance" required maxLength="2000" rows="3" placeholder="Describe the source of these corrections and any uncertainty."/></label><p className="help">Changes preserve the previous values in history. Supplied details are not independently verified.</p>{error&&<p className="error" role="alert">{error}</p>}<button disabled={saving}>{saving?'Saving…':'Save corrections'}</button></form>}
  </section>;
}
function display(value){return typeof value==='object'&&value!==null?JSON.stringify(value,null,2):String(value===''||value==null?'Not recorded':value);}
export function ProfileDisclosure({title, count, preview, children}) {
  return <details className="profile-disclosure"><summary><span className="disclosure-label">{title} <span className="disclosure-count">{count}</span></span><span className="disclosure-preview">{preview}</span></summary><div className="disclosure-content">{children}</div></details>;
}
export function RecentContext({activities,date}) {
  const notes=activities.filter(activity=>activity.kind==='note').slice(0,2);
  if(!notes.length)return null;
  let preview;try{preview=JSON.parse(notes[0].body).text||notes[0].body;}catch{preview=notes[0].body;}
  return <ProfileDisclosure title="Latest conversation context" count={notes.length} preview={preview}><small>User-recorded notes · contact identity and details may need confirmation</small>{notes.map(note=>{
    let body;try{body=JSON.parse(note.body);}catch{body={text:note.body};}
    return <article className="recent-note" key={note.id}><p>{body.text||note.body}</p><small>{note.actor} · {date(note.created_at)}</small></article>;
  })}</ProfileDisclosure>;
}
export function ProfileHistory({activities,date}) {
  return <div className="timeline profile-timeline">{activities.map(activity=>{
    let body;try{body=JSON.parse(activity.body);}catch{body={text:activity.body};}
    return <article key={activity.id}><span className="timeline-dot"/><header><strong>{activity.kind.replaceAll('_',' ')}</strong><small>{activity.actor} · {date(activity.created_at)}</small></header>{activity.kind==='research_updated'?<><p>Research revision {body.revision} · {(body.research_status||'draft').replaceAll('_',' ')}{body.agent_generated?' · Agent research':' · Human action'}</p><p>{body.document?.evidence?.length||0} evidence records · {body.document?.uncertainties?.length||0} recorded uncertainties</p><ul>{Object.entries({business_summary:'Business story',contact_rationale:'Why this lead',next_action:'Next action',hold_reason:'Hold reason'}).filter(([key])=>body.document?.[key]!==body.previous_document?.[key]).map(([key,label])=><li key={key}>{label} updated</li>)}</ul><details><summary>Revision documents & technical metadata</summary><pre>{JSON.stringify(body,null,2)}</pre></details></>:activity.kind==='contacts_updated'?<><p>{body.provenance}</p>{[['before','Previous contacts'],['after','Saved contacts']].map(([key,label])=><details key={key}><summary>{label} ({body[key]?.length||0})</summary>{(body[key]||[]).map(contact=><div className="lead-contact" key={contact.id}><strong>{contact.name||contact.department||'Name / department not recorded'}</strong><span>{contact.role||'No role / tag'} · {contact.ownership} ownership{contact.is_primary?' · Primary':''}</span><span>{[contact.phone,contact.email].filter(Boolean).join(' · ')||'Channels cleared'}</span></div>)}</details>)}<small>{body.verification}</small></>:activity.kind==='profile_updated'?<><ul>{Object.entries(body.changes||{}).map(([field,change])=><li key={field}><strong>{profileFields[field]||field}</strong>: <del>{change.from||'Not recorded'}</del> → {change.to||'Cleared'}</li>)}</ul><p>{body.provenance}</p><small>{body.verification}</small>{safeLink(body.source_url)&&<a href={safeLink(body.source_url)} target="_blank" rel="noopener noreferrer">View source ↗</a>}{body.source_activity_id&&<small>Source activity: {body.source_activity_id}</small>}</>:<>{body.text&&<p>{body.text}</p>}{activity.kind==='contact_research'&&<p className="research-warning">Historical research — identity, current role and contact details need confirmation.</p>}{Object.entries(body).filter(([key,value])=>key!=='text'&&!key.endsWith('_id')&&typeof value!=='object').map(([key,value])=><p key={key}><strong>{key.replaceAll('_',' ')}:</strong> {display(value)}</p>)}{Object.entries(body).some(([key,value])=>key.endsWith('_id')||typeof value==='object')&&<details><summary>Technical metadata</summary><pre>{JSON.stringify(body,null,2)}</pre></details>}</>}</article>;
  })}</div>;
}
