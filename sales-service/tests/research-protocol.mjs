import {spawn} from 'node:child_process';
import readline from 'node:readline';
import {randomUUID} from 'node:crypto';
import {fileURLToPath} from 'node:url';
const fixture=JSON.parse(await new Promise(resolve=>{let text='';process.stdin.on('data',chunk=>text+=chunk);process.stdin.on('end',()=>resolve(text))}));
const child=spawn(process.execPath,[fileURLToPath(new URL('../../sales-workspace/mcp-server/server.mjs',import.meta.url))],{stdio:['pipe','pipe','pipe'],env:process.env});
child.stderr.pipe(process.stderr);
const lines=readline.createInterface({input:child.stdout}),pending=new Map();let sequence=0,checks=0;
lines.on('line',line=>{const message=JSON.parse(line);const entry=pending.get(message.id);if(entry){pending.delete(message.id);entry(message)}});
function call(method,params={}){const id=++sequence;return new Promise((resolve,reject)=>{const timeout=setTimeout(()=>{pending.delete(id);reject(Error('MCP timeout'))},10000);pending.set(id,message=>{clearTimeout(timeout);resolve(message)});child.stdin.write(JSON.stringify({jsonrpc:'2.0',id,method,params})+'\n')})}
const invoke=(name,args)=>call('tools/call',{name,arguments:{project_id:fixture.project,...args}});
const value=result=>JSON.parse(result.result.content[0].text);
function check(ok,label){if(!ok)throw Error('FAIL '+label);checks++;console.log('PASS '+label)}
try{
 await call('initialize');const catalog=await call('tools/list');check(['get_lead_research','update_lead_research'].every(name=>catalog.result.tools.some(t=>t.name===name)),'local MCP discovers additive research tools');
 let result=await invoke('get_lead_research',{id:fixture.lead});check(!result.result.isError&&value(result).revision===0,'local MCP reads absent research');
 const document=JSON.stringify({document_version:1,business_summary:'Synthetic protocol research',research_status:'draft'}),request_key=randomUUID();const payload={id:fixture.lead,revision:'0',document,request_key};
 result=await invoke('update_lead_research',payload);check(!result.result.isError&&value(result).revision===1,'local MCP saves bounded document');
 result=await invoke('update_lead_research',payload);check(value(result).replayed===true,'local MCP uncertain retry deduplicated');
 result=await invoke('update_lead_research',{...payload,request_key:randomUUID()});check(result.result.isError&&/changed|revision/i.test(result.result.content[0].text),'local MCP exposes stale revision conflict');
 result=await invoke('get_lead_research',{id:fixture.lead});check(value(result).document.business_summary==='Synthetic protocol research','local MCP reads saved research');
 result=await invoke('update_lead_research',{...payload,revision:'1',document:JSON.stringify({research_status:'reviewed'}),request_key:randomUUID()});check(result.result.isError&&/human review/i.test(result.result.content[0].text),'local MCP cannot certify human review');
 result=await invoke('get_lead_research',{id:fixture.lead,project_id:fixture.other_project});check(result.result.isError&&/not found/i.test(result.result.content[0].text),'local MCP keeps project boundary');
 console.log(`Local research MCP checks passed: ${checks}.`);
}finally{child.kill();lines.close()}
