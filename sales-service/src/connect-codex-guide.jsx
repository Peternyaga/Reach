import React, {useEffect,useState} from 'react';
import './connect-codex-guide.css';

const marketplace='https://github.com/Peternyaga/vumbua-labs-plugins';
const commands='codex plugin marketplace add Peternyaga/vumbua-labs-plugins --ref main\ncodex plugin add sales-workspace-hosted@vumbua-labs';

export function ConnectCodexGuide(){
 const [copied,setCopied]=useState(false);
 useEffect(()=>{const previous=document.title;document.title='Connect Reach to ChatGPT Desktop | Fieldwork Reach';return ()=>{document.title=previous}},[]);
 async function copy(){
  try{await navigator.clipboard.writeText(commands);setCopied(true);setTimeout(()=>setCopied(false),2400)}
  catch{setCopied(false)}
 }
 return <main className="codex-guide">
  <div className="codex-guide-glow" aria-hidden="true"/>
  <div className="codex-guide-wrap">
   <header className="codex-guide-header"><a className="codex-guide-brand" href="/" aria-label="Reach home"><span aria-hidden="true">◈</span> Reach <span className="codex-guide-by">by Vumbua Labs</span></a><a className="codex-guide-login" href="/">Open Reach <span aria-hidden="true">↗</span></a></header>
   <section className="codex-guide-hero" aria-labelledby="codex-guide-title"><p className="codex-guide-kicker"><span aria-hidden="true"/> CHATGPT DESKTOP · 3 SIMPLE STEPS</p><h1 id="codex-guide-title">Connect Reach<br/><em>to ChatGPT desktop.</em></h1><p>In the Codex area of the ChatGPT desktop app, use Reach to work with leads and follow-ups from chat.</p></section>
   <div className="codex-guide-grid">
    <section className="codex-guide-card codex-guide-steps" aria-label="Setup steps">
     <div className="codex-guide-step"><span className="codex-guide-number">01</span><div><h2>Get your Reach account</h2><p>Create your own workspace with <a href="/signup">Create account</a>. Joining an existing team? Ask its owner for an invitation. Already have an account? Continue below.</p></div></div>
     <div className="codex-guide-step"><span className="codex-guide-number">02</span><div><h2>Add Reach in ChatGPT desktop</h2><p>Open the <strong>Codex</strong> area, go to <strong>Plugins</strong>, add the <a href={marketplace} target="_blank" rel="noopener noreferrer">Vumbua Labs marketplace <span aria-hidden="true">↗</span></a>, then install <strong>Fieldwork Reach</strong>.</p><span className="codex-guide-id">sales-workspace-hosted@vumbua-labs</span></div></div>
     <div className="codex-guide-step"><span className="codex-guide-number">03</span><div><h2>Connect and try it</h2><p>Restart the ChatGPT desktop app, open a new Codex chat, and connect your Reach account when prompted. Choose Create account if you’re new, then sign in and approve the access you need.</p><div className="codex-guide-prompt">Try asking: <strong>“Show my current follow-ups in Reach.”</strong></div></div></div>
    </section>
    <aside className="codex-guide-card codex-guide-terminal" aria-label="Command-line installation"><div className="codex-guide-terminal-head"><span><span className="codex-guide-dot" aria-hidden="true"/> Prefer commands?</span><button type="button" onClick={copy} aria-label="Copy installation commands">{copied?'Copied ✓':'Copy commands'}</button></div><pre><code>{commands}</code></pre><p>Run these in your terminal, then continue with step 3. If Codex does not offer linking, run <code>codex mcp login fieldwork</code>.</p></aside>
   </div>
   <p className="codex-guide-footnote">You can create an independent workspace on Reach or during the plugin connection. Each person signs in to their own account.</p>
  </div>
 </main>;
}
