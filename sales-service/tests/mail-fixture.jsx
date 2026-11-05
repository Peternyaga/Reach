import React from 'react';
import {createRoot} from 'react-dom/client';
import {MailboxSettings,LeadEmail} from '../src/mailbox.jsx';
import {AgentRequestForm} from '../src/agent-request.jsx';
import '../src/style.css';
import '../src/lead-profile.css';

let mailbox={configured:true,smtp_host:'smtp.example.com',smtp_port:587,security:'starttls',username:'owner@example.com',from_email:'owner@example.com',from_name:'Example Owner',updated_at:'2026-10-13 00:00:00'};
async function api(path,body){
  if(path==='/mailbox'&&body){mailbox={...mailbox,...body,configured:true};return mailbox}
  if(path==='/mailbox')return mailbox;
  if(path.endsWith('/emails')&&body){document.getElementById('result').textContent='Mock email sent to lead';return {status:'sent'}}
  if(path.endsWith('/emails'))return [];
  if(path.endsWith('/agent-requests')){document.getElementById('result').textContent='Mock task queued: '+(body.allow_email_send?'email allowed':'no email permission');return {queued:true}}
  if(path==='/mailbox/disconnect')return {configured:false};
  return [];
}
createRoot(document.getElementById('root')).render(<main style={{maxWidth:720,margin:'24px auto',padding:'20px'}}><h1>Mail interface check</h1><div id="result" role="status"/><section className="profile-body"><AgentRequestForm leadId="00000000000000000000000000000001" leadEmail="lead@example.com" api={api}/><LeadEmail lead={{id:'00000000000000000000000000000001',email:'lead@example.com'}} api={api}/></section><MailboxSettings api={api}/></main>);
