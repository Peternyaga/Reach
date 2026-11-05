// One request at a time; no intervals accumulating during sleep or slow networks.
export function startWorkspaceSync({read, refresh, status=()=>{}, unauthorized=()=>{}, target=window, documentTarget=document, schedule=setTimeout, cancel=clearTimeout, interval=3000}) {
  let stopped=false,running=false,again=false,timer=null,etag=null,failures=0,controller=null;
  const available=()=>documentTarget.visibilityState!=='hidden'&&target.navigator?.onLine!==false;
  const later=delay=>{cancel(timer);if(!stopped&&available())timer=schedule(tick,delay)};
  async function tick(){
    cancel(timer);
    if(stopped||!available())return;
    if(running){again=true;return;}
    running=true;controller=new AbortController();
    try{
      const result=await read(etag,controller.signal);
      if(stopped||!available())return;
      if(result.status===401){stopped=true;unauthorized();return;}
      if(result.status!==200&&result.status!==304)throw new Error('Refresh unavailable');
      if(result.status===200){
        if(await refresh(controller.signal,result)) {etag=result.etag;status('live');}
        else status('deferred');
      }else status('live');
      failures=0;
    }catch(error){if(!stopped&&available()&&error.name!=='AbortError'){failures++;status('reconnecting')}}
    finally{
      running=false;controller=null;
      if(again){again=false;later(0)}else later(Math.min(30000,interval*2**failures));
    }
  }
  function wake(){if(stopped)return;if(!available()){cancel(timer);controller?.abort();status(target.navigator?.onLine===false?'offline':'paused');return;}tick()}
  for(const event of ['focus','online','offline','pageshow','fieldwork:changed'])target.addEventListener(event,wake);
  documentTarget.addEventListener('visibilitychange',wake);
  later(0);
  return ()=>{stopped=true;cancel(timer);controller?.abort();for(const event of ['focus','online','offline','pageshow','fieldwork:changed'])target.removeEventListener(event,wake);documentTarget.removeEventListener('visibilitychange',wake)};
}

let baselines=new WeakMap();
function formValues(form){return JSON.stringify([...form.elements].filter(el=>['INPUT','TEXTAREA','SELECT'].includes(el.tagName)).map(el=>[el.name,el.type==='checkbox'||el.type==='radio'?el.checked:el.value]))}
export function installDraftProtection(root=document){
  const rememberForms=()=>{for(const form of root.querySelectorAll('form'))if(!baselines.has(form))baselines.set(form,formValues(form))};
  function remember(event){const form=event.target.form;if(form&&!baselines.has(form))baselines.set(form,formValues(form))}
  function reset(event){if(event.target.tagName==='FORM')queueMicrotask(()=>baselines.set(event.target,formValues(event.target)))}
  root.addEventListener('focusin',remember,true);root.addEventListener('beforeinput',remember,true);root.addEventListener('reset',reset,true);
  rememberForms();
  const observer=typeof MutationObserver==='undefined'?null:new MutationObserver(rememberForms);
  observer?.observe(root,{childList:true,subtree:true});
  return ()=>{observer?.disconnect();root.removeEventListener('focusin',remember,true);root.removeEventListener('beforeinput',remember,true);root.removeEventListener('reset',reset,true);baselines=new WeakMap()};
}
export function hasWorkspaceDrafts(root=document){
  return !!root.querySelector('[data-sync-lock]')||[...root.querySelectorAll('form')].some(form=>baselines.has(form)&&baselines.get(form)!==formValues(form));
}
export const workspaceChanged=()=>window.dispatchEvent(new Event('fieldwork:changed'));
export const workspaceRefreshed=()=>window.dispatchEvent(new Event('fieldwork:refreshed'));
