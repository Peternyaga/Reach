import React,{useState} from 'react';
import {createRoot} from 'react-dom/client';
import {ProfileDialog} from '../src/lead-profile.jsx';
import {CloseLead} from '../src/close-lead.jsx';
import '../src/style.css';
function Fixture(){
 const [stage,setStage]=useState('New'),[decision,setDecision]=useState(null);
 return <ProfileDialog close={()=>{}}><h2 id="detail-title">Synthetic close QA</h2><CloseLead lead={{stage}} busy={false} onClose={text=>{setDecision(text);setStage('Lost')}}/>{decision!==null&&<p role="status">Saved close decision: {decision||'(none)'}</p>}</ProfileDialog>;
}
createRoot(document.getElementById('root')).render(<Fixture/>);
