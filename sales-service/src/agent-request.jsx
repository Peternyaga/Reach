import React,{useState,useEffect} from 'react';
import {requestKey} from './request-key.js';

export function AgentRequestForm({leadId,leadEmail,api,onQueued}){
  const [sender,setSender]=useState(null);
  useEffect(()=>{api('/mailbox').then(setSender).catch(()=>setSender(null))},[api,leadId]);
  const [instruction,setInstruction]=useState('');
  const [key,setKey]=useState(requestKey);
  const [busy,setBusy]=useState(false);
  const [error,setError]=useState('');
  const [success,setSuccess]=useState('');
  const [allowEmail,setAllowEmail]=useState(false);
  async function submit(event){
    event.preventDefault();
    if(busy||!instruction.trim())return;
    setBusy(true);setError('');setSuccess('');
    try{
      await api('/leads/'+leadId+'/agent-requests',{instruction:instruction.trim(),request_key:key,allow_email_send:allowEmail,...(allowEmail?{sender_id:sender.id,sender_revision:sender.revision,email_recipient:leadEmail}:{})});
      setInstruction('');setAllowEmail(false);setKey(requestKey());setSuccess('Request queued. Check Agent activity for its report.');
      await onQueued?.();
    }catch(e){setError(e.message)}finally{setBusy(false)}
  }
  return <section className="agent-request-section profile-card"><h3>Ask the agent</h3><p>Ask for profile research, a new sales opportunity, an internal follow-up, or a lead email. Review its saved changes and any questions in Agent activity.</p><form onSubmit={submit}><label className="field"><span>What should the agent do?</span><textarea value={instruction} onChange={e=>setInstruction(e.target.value)} maxLength={4000} required rows={4} placeholder="Research this business’s current website and decision-maker contacts. Update supported profile fields with source links, and flag anything uncertain."/></label>{leadEmail&&<label className="agent-email-consent"><input type="checkbox" disabled={!sender?.configured} checked={allowEmail} onChange={e=>setAllowEmail(e.target.checked)}/><span>Allow this specific task to email {leadEmail} from {sender?.from_email||'the sender selected for this project (choose in Connected apps)'}</span></label>}<button disabled={busy||!instruction.trim()}>{busy?'Queuing…':'Queue agent task'}</button></form><small className="help">Research and internal updates need no email permission. Sending requires your connected mailbox and this explicit permission; the agent cannot change the recipient. No calls, purchases, or access changes.</small>{error&&<p className="error" role="alert">{error}</p>}{success&&<p className="success" role="status">{success}</p>}</section>;
}
