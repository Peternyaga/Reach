import {useEffect,useRef} from 'react';
import {hasWorkspaceDrafts} from './workspace-sync.js';

// Keep modal/editor component identity while refreshing its read-only records.
export function useWorkspaceRefresh(refresh,busy=false){
  const current=useRef({refresh,busy});current.current={refresh,busy};
  useEffect(()=>{
    const update=()=>{if(!current.current.busy&&!hasWorkspaceDrafts())Promise.resolve(current.current.refresh()).catch(()=>{})};
    window.addEventListener('fieldwork:refreshed',update);
    return ()=>window.removeEventListener('fieldwork:refreshed',update);
  },[]);
}
