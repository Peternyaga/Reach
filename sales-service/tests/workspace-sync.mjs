import test from 'node:test';
import assert from 'node:assert/strict';
import {startWorkspaceSync,installDraftProtection,hasWorkspaceDrafts} from '../src/workspace-sync.js';

const flush=()=>new Promise(resolve=>setImmediate(resolve));
function harness(read,refresh=async()=>true){
 const target=new EventTarget(),doc=new EventTarget(),jobs=new Map(),states=[];let id=0,expired=0;
 target.navigator={onLine:true};doc.visibilityState='visible';
 const stop=startWorkspaceSync({read,refresh,target,documentTarget:doc,status:s=>states.push(s),unauthorized:()=>expired++,schedule:(fn,delay)=>{jobs.set(++id,{fn,delay});return id},cancel:id=>jobs.delete(id)});
 const run=async()=>{const next=[...jobs][0];assert.ok(next,'one pending poll');jobs.delete(next[0]);next[1].fn();await flush()};
 return {target,doc,jobs,states,stop,run,expired:()=>expired};
}
test('conditional unchanged polls do not reload workspace data',async()=>{
 const headers=[];let refreshes=0;
 const h=harness(async etag=>{headers.push(etag);return headers.length===1?{status:200,etag:'a'}:{status:304}},async()=>{refreshes++;return true});
 await h.run();await h.run();assert.deepEqual(headers,[null,'a']);assert.equal(refreshes,1);assert.equal([...h.jobs.values()][0].delay,3000);h.stop();
});
test('deferred revisions are acknowledged only after edits finish',async()=>{
 let blocked=true,applied=0;const headers=[];
 const h=harness(async etag=>{headers.push(etag);return {status:200,etag:'b'}},async()=>{if(blocked)return false;applied++;return true});
 await h.run();assert.equal(h.states.at(-1),'deferred');blocked=false;await h.run();await h.run();assert.deepEqual(headers,[null,null,'b']);assert.equal(applied,2);h.stop();
});
test('slow reads never overlap, even with repeated focus/resume events',async()=>{
 let reads=0,resolve;const h=harness(()=>{reads++;return new Promise(r=>resolve=r)});
 await h.run();h.target.dispatchEvent(new Event('focus'));h.target.dispatchEvent(new Event('pageshow'));assert.equal(reads,1);
 resolve({status:304});await flush();assert.equal([...h.jobs.values()][0].delay,0);h.stop();
});
test('hidden and offline sessions pause then immediately catch up on resume',async()=>{
 let reads=0;const h=harness(async()=>{reads++;return {status:304}});
 await h.run();h.doc.visibilityState='hidden';h.doc.dispatchEvent(new Event('visibilitychange'));assert.equal(h.jobs.size,0);
 h.doc.visibilityState='visible';h.doc.dispatchEvent(new Event('visibilitychange'));await flush();assert.equal(reads,2);
 h.target.navigator.onLine=false;h.target.dispatchEvent(new Event('offline'));assert.equal(h.jobs.size,0);assert.equal(h.states.at(-1),'offline');
 h.target.navigator.onLine=true;h.target.dispatchEvent(new Event('online'));await flush();assert.equal(reads,3);h.stop();
});
test('network failures back off, and focus retries without waiting for backoff',async()=>{
 let broken=true;const h=harness(async()=>{if(broken)throw new Error('Network unavailable');return {status:304}});
 await h.run();assert.equal([...h.jobs.values()][0].delay,6000);await h.run();assert.equal([...h.jobs.values()][0].delay,12000);
 broken=false;h.target.dispatchEvent(new Event('focus'));await flush();assert.equal(h.states.at(-1),'live');assert.equal([...h.jobs.values()][0].delay,3000);h.stop();
});
test('revoked sessions stop polling and notify authentication handler',async()=>{
 const h=harness(async()=>({status:401}));await h.run();assert.equal(h.expired(),1);assert.equal(h.jobs.size,0);h.target.dispatchEvent(new Event('focus'));assert.equal(h.expired(),1);h.stop();
});
test('teardown aborts pending reads and prevents late data application',async()=>{
 let signal,resolve,applied=0;const h=harness((_,s)=>{signal=s;return new Promise(r=>resolve=r)},async()=>{applied++;return true});
 await h.run();h.stop();assert.equal(signal.aborted,true);resolve({status:200,etag:'late'});await flush();assert.equal(applied,0);assert.equal(h.jobs.size,0);
});
test('draft tracking preserves edited forms and releases restored or reset drafts',async()=>{
 const input={tagName:'INPUT',name:'stage',type:'text',value:'New'},form={tagName:'FORM',elements:[input]},root=new EventTarget();input.form=form;
 let locked=false;root.querySelector=()=>locked?{}:null;root.querySelectorAll=()=>[form];
 const stop=installDraftProtection(root);assert.equal(hasWorkspaceDrafts(root),false);
 input.value='Qualified';assert.equal(hasWorkspaceDrafts(root),true);input.value='New';assert.equal(hasWorkspaceDrafts(root),false);
 locked=true;assert.equal(hasWorkspaceDrafts(root),true);locked=false;
 input.value='Reset state';const event=new Event('reset');Object.defineProperty(event,'target',{value:form});root.dispatchEvent(event);await flush();assert.equal(hasWorkspaceDrafts(root),false);stop();
});
