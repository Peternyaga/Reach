import React,{useState} from 'react';
import {requestKey as newRequestKey} from './request-key.js';
import './lead-capture.css';
import {ContactFields,blankContact} from './lead-contacts.jsx';
import {ResearchFields,blankResearch} from './reach-workspace.jsx';

const fields=[['Lead name / title','name',true],['Organisation','organisation'],['Email','email'],['Phone · include country code','phone'],['Product or service','offering'],['Lead source','source'],['Collected by','collector'],['Suggested owner','owner']];

export function LeadCapture({api,projectId,onClose,onCreated,onOpen,role='collector'}){
 const [busy,setBusy]=useState(false),[error,setError]=useState(''),[matches,setMatches]=useState([]),[requestKey]=useState(newRequestKey);
 const [contacts,setContacts]=useState([]);
 const [includeResearch,setIncludeResearch]=useState(false),[research,setResearch]=useState(blankResearch),[identity,setIdentity]=useState({});
 async function submit(event){
  event.preventDefault();setBusy(true);setError('');setMatches([]);
  try{if(contacts.some(c=>!c.phone.trim()&&!c.email.trim()))throw new Error('Each added contact needs a phone or email.');const data=Object.fromEntries(new FormData(event.currentTarget));delete data['contact-primary'];await api('/leads',{...data,project_id:projectId,request_key:requestKey,...(contacts.length?{contacts}:{}),...(includeResearch?{research}:{} )});await onCreated();onClose()}
  catch(cause){setError(cause.message);if(cause.matches)setMatches(cause.matches)}
  finally{setBusy(false)}
 }
 return <div className="overlay"><section className="modal" role="dialog" aria-modal="true" aria-labelledby="add-title"><button className="close" aria-label="Close" onClick={onClose}>×</button><span className="eyebrow">LEAD INTAKE</span><h2 id="add-title">Start a new relationship</h2><p>Keep what you know. Leave unknown details empty.</p>
  <form onSubmit={submit} data-sync-lock onChange={event=>{if(['name','phone','email'].includes(event.target.name))setIdentity({...identity,[event.target.name]:event.target.value})}}><div className="form-grid">{fields.map(([label,name,required])=><label className="field" key={name}><span>{label}</span><input name={name} type={name==='email'?'email':'text'} required={required} maxLength={name==='email'?254:name==='phone'?50:150}/></label>)}<label className="field wide"><span>Collection notes</span><textarea name="notes" rows="3" placeholder="What are they interested in? How should we approach them?"/></label></div>
   <section className="intake-contacts"><h3>Additional labelled contacts</h3><p>Phone and email above remain supported. Add separate routes for owners, reception or departments. Unknown names can stay empty.</p>{contacts.map((contact,index)=><article className="intake-contact" key={index}><ContactFields contact={contact} onChange={value=>setContacts(contacts.map((c,i)=>i===index?value:c))}/><label><input type="radio" name="contact-primary" checked={contact.is_primary} onChange={()=>setContacts(contacts.map((c,i)=>({...c,is_primary:i===index})))}/> Primary contact</label> <button type="button" className="text-btn" onClick={()=>setContacts(contacts.filter((_,i)=>i!==index))}>Remove contact {index+1}</button></article>)}<button type="button" className="secondary" onClick={()=>setContacts([...contacts,blankContact()])}>Add labelled contact</button></section>
   {role!=='collector' &&<><label className="research-intake-toggle"><input type="checkbox" checked={includeResearch} onChange={e=>setIncludeResearch(e.target.checked)}/> Include structured research (optional)</label>{includeResearch&&<ResearchFields doc={research} setDoc={setResearch} lead={identity}/>}</>}
   {error&&<p className="error" role="alert">{error}</p>}
   {matches.length>0&&<div className="duplicate-review"><strong>Possible existing relationship</strong><p>Review the match before adding a separate record. If this is a new sale for the same person, open their profile and add another opportunity.</p>{matches.map((match,index)=>match.id?<button type="button" key={match.id} onClick={()=>onOpen(match.id)}>{match.name}{match.organisation?' · '+match.organisation:''} ↗</button>:<span key={index}>A matching contact exists. Ask a workspace manager to review it.</span>)}</div>}
   <div className="form-actions"><button type="button" className="secondary" onClick={onClose}>Cancel</button><button disabled={busy}>{includeResearch&&research.research_status==='draft'?'Save lead & research draft':'Save lead →'}</button></div>
  </form>
 </section></div>
}
