import {cp,mkdir,rm,readFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {execFileSync} from 'node:child_process';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const source=path.resolve(root,'../sales-workspace-hosted');
const manifest=JSON.parse(await readFile(path.join(source,'.codex-plugin/plugin.json'),'utf8'));
if(!/^\d+\.\d+\.\d+$/.test(manifest.version))throw Error('Invalid plugin version');
const publish=path.join(root,'publish'),stage=path.join(publish,'plugin-stage-'+manifest.version),archive=path.join(publish,`fieldwork-reach-plugin-${manifest.version}.zip`);
// Fixed staging paths are checked before recursive removal.
if(path.dirname(stage)!==publish||!stage.startsWith(publish+path.sep))throw Error('Unsafe staging path');
await rm(stage,{recursive:true,force:true});await mkdir(stage,{recursive:true});
await cp(source,path.join(stage,'sales-workspace-hosted'),{recursive:true});
await cp(path.join(root,'deploy',`PLUGIN-UPDATE-${manifest.version}.md`),path.join(stage,`PLUGIN-UPDATE-${manifest.version}.md`));
await rm(archive,{force:true});execFileSync('tar',['-a','-cf',archive,'-C',stage,'.'],{stdio:'inherit'});
console.log(`Hosted plugin archive: ${archive}`);

const local=path.resolve(root,'../sales-workspace');
const localManifest=JSON.parse(await readFile(path.join(local,'.codex-plugin/plugin.json'),'utf8'));
if(!/^\d+\.\d+\.\d+$/.test(localManifest.version))throw Error('Invalid local plugin version');
const localStage=path.join(publish,'local-plugin-stage-'+localManifest.version);
if(path.dirname(localStage)!==publish)throw Error('Unsafe local plugin stage');
await rm(localStage,{recursive:true,force:true});await mkdir(localStage,{recursive:true});
await cp(local,path.join(localStage,'sales-workspace'),{recursive:true});
await cp(path.join(root,'deploy',`PLUGIN-UPDATE-${manifest.version}.md`),path.join(localStage,`PLUGIN-UPDATE-${manifest.version}.md`));
const localZip=path.join(publish,`fieldwork-local-plugin-${localManifest.version}.zip`);
await rm(localZip,{force:true});execFileSync('tar',['-a','-cf',localZip,'-C',localStage,'.'],{stdio:'inherit'});
console.log(`Local plugin archive: ${localZip}`);
