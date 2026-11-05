// Render the real app against synthetic data; never calls the live API.
const lead={id:'qa-lead',name:'Synthetic contact',organisation:'QA business',phone:'+254700000000',email:'contact@example.test',website:'https://example.test',source:'Synthetic fixture',location:'Nairobi',contact_role:'Manager',owner:'QA Owner',owner_user_id:'qa-owner',project_id:'qa-project',version:1,stage:'New',notes:'Original intake context is preserved.'};
const tasks=[{id:'qa-task',lead_id:lead.id,title:'Discuss requirements',owner:'QA Owner',status:'pending',due_at:'2026-10-14 07:00:00'}, {id:'qa-old-task',lead_id:lead.id,title:'Earlier preparation',owner:'QA Owner',status:'completed',due_at:'2026-10-12 07:00:00'}];
const activities=[{id:'qa-note',kind:'note',body:JSON.stringify({text:'Please check the decision-maker before the next conversation.',outcome:'connected'}),actor:'QA Owner',created_at:'2026-10-13 07:00:00'},{id:'qa-research',kind:'contact_research',body:JSON.stringify({target_role:'Business manager',source:'https://example.test/about'}),actor:'QA Owner',created_at:'2026-10-12 07:00:00'}];
const opportunities=[{id:'qa-opportunity',offering:'Website development',stage:'New',lead_id:lead.id}];
window.fetch=async(url,options={})=>{
  const path=String(url).split('?')[0];let data;
  if(path==='/api/sync'){const revision='\"synthetic-profile\"';return {status:options.headers?.['If-None-Match']===revision?304:200,headers:{get:()=>revision},json:async()=>({revision,user:{id:'qa-owner',name:'QA Owner',role:'owner'}})};}
  if(options.method==='POST')throw new Error('QA fixture is read-only. No record was saved.');
  if(path==='/api/session')data={csrf:'fixture',user:{id:'qa-owner',name:'QA Owner',role:'owner'}};
  else if(path==='/api/plugin/status')data={state:'not_prepared'};
  else if(path==='/api/projects')data=[{id:'qa-project',name:'QA project'},{id:'qa-second',name:'Other project'}];
  else if(path==='/api/leads')data=[lead];
  else if(path==='/api/tasks')data=tasks;
  else if(path==='/api/team')data=[];
  else if(path==='/api/opportunities')data=opportunities;
  else if(path==='/api/leads/qa-lead')data={lead,tasks,activities,opportunities};
  else throw new Error('Unexpected fixture route');
  return {ok:true,json:async()=>data};
};
await import('../src/main.jsx');
