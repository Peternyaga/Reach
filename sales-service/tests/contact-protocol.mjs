import {spawn} from 'node:child_process';
import readline from 'node:readline';
import {randomUUID} from 'node:crypto';
import {fileURLToPath} from 'node:url';
const fixture=JSON.parse(await new Promise(resolve=>{let text='';process.stdin.on('data',chunk=>text+=chunk);process.stdin.on('end',()=>resolve(text))}));
const child=spawn(process.execPath,[fileURLToPath(new URL('../../sales-workspace/mcp-server/server.mjs',import.meta.url))],{stdio:['pipe','pipe','pipe'],env:process.env});child.stderr.pipe(process.stderr);
const lines=readline.createInterface({input:child.stdout}),pending=new Map();let sequence=0,checks=0;
lines.on('line',line=>{const message=JSON.parse(line);pending.get(message.id)?.(message)});
function call(method,params={}){const id=++sequence;return new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('MCP timeout')),10000);pending.set(id,message=>{clearTimeout(timer);pending.delete(id);resolve(message)});child.stdin.write(JSON.stringify({jsonrpc:'2.0',id,method,params})+'\n')})}
const invoke=(name,args)=>call('tools/call',{name,arguments:{project_id:fixture.project,...args}});
const value=result=>JSON.parse(result.result.content[0].text);
function check(ok,label){if(!ok)throw Error('FAIL '+label);checks++;console.log('PASS '+label)}
const contacts=[{name:'Amina',phone:'+254711111111',role:'Owner',ownership:'direct',is_primary:true},{department:'Front desk',phone:'+254722222222',role:'Reception',ownership:'shared'},{email:'sales-protocol@example.test',role:'Sales',ownership:'unknown'}];
try{
 await call('initialize');const catalog=await call('tools/list');check(catalog.result.tools.some(t=>t.name==='update_lead_contacts'),'local MCP discovers contact operations');
 const payload={name:'Synthetic three-contact protocol',request_key:randomUUID(),contacts:JSON.stringify(contacts)};
 let result=await invoke('create_lead',payload);check(!result.result.isError,'local MCP creates three contacts through web API');const id=value(result).id;
 result=await invoke('create_lead',payload);check(value(result).replayed===true,'local MCP multi-contact create retry deduplicates');
 result=await invoke('get_lead',{id});let detail=value(result);check(detail.contacts.length===3&&detail.contacts.some(c=>c.role==='Reception'&&c.ownership==='shared'&&!c.name),'web detail retains three labelled routes');
 const sales=detail.contacts.find(c=>c.role==='Sales'),owner=detail.contacts.find(c=>c.role==='Owner');
 const edit={id,version:String(detail.lead.version),operations:JSON.stringify([{action:'update',contact_id:sales.id,contact:{email:'changed-protocol@example.test'}},{action:'set_primary',contact_id:sales.id}]),provenance:'Supplied synthetic protocol evidence'};
 result=await invoke('update_lead_contacts',edit);check(!result.result.isError&&value(result).contacts.length===3,'local MCP updates one route and selects primary');
 result=await invoke('update_lead_contacts',edit);check(result.result.isError&&/changed/i.test(result.result.content[0].text),'local MCP exposes stale edit conflict');
 detail=value(await invoke('get_lead',{id}));check(detail.lead.email==='changed-protocol@example.test'&&detail.contacts.find(c=>c.id===owner.id).phone==='+254711111111','primary mirror and other contacts survive protocol edit');
 result=await invoke('update_lead_contacts',{...edit,version:String(detail.lead.version),operations:JSON.stringify([{action:'remove',contact_id:sales.id},{action:'set_primary',contact_id:owner.id}])});check(!result.result.isError&&value(result).contacts.length===2,'local MCP removes one route and explicitly reselects primary');
 result=await invoke('create_lead',{name:'Legacy protocol',phone:'+254733333333',request_key:randomUUID()});detail=value(await invoke('get_lead',{id:value(result).id}));check(detail.contacts.length===1&&detail.contacts[0].is_primary&&detail.lead.phone==='+254733333333','existing local single-contact create remains compatible');
 result=await invoke('create_lead',{...payload,request_key:randomUUID(),contacts:JSON.stringify([{email:'changed-protocol@example.test',role:'Other'}])});check(!result.result.isError,'removed routes no longer cause duplicate warnings');
 result=await invoke('create_lead',{...payload,request_key:randomUUID(),contacts:JSON.stringify([{phone:'+254722222222'}])});check(result.result.isError||value(result).matches?.length,'duplicate checks include non-primary contacts');
 result=await invoke('update_lead_contacts',{...edit,operations:'not json'});check(result.result.isError,'malformed operations JSON returns tool error');
 console.log(`Contact protocol checks passed: ${checks}.`);
}finally{child.kill();lines.close()}
