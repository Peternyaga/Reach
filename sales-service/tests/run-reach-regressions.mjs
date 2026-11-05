// Isolated local HTTP server port override without rewriting pre-existing tests.
import {readFile,writeFile,unlink} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
const names=process.argv.slice(2);if(!names.length)throw Error('Supply test names');
for(const name of names){if(!/^[a-z-]+$/.test(name))throw Error('Invalid test name');const source=new URL(`${name}.php`,import.meta.url),temp=new URL(`.reach-run-${name}.php`,import.meta.url);try{await writeFile(temp,(await readFile(source,'utf8')).replaceAll('127.0.0.1:8788','127.0.0.1:8793'));const result=spawnSync('php',[decodeURIComponent(temp.pathname).replace(/^\/([A-Z]:)/,'$1')],{stdio:'inherit',env:process.env});if(result.status!==0)process.exitCode=1;}finally{await unlink(temp)}}
