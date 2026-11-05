import {defineConfig} from 'vite';
export default defineConfig({server:{port:5178,strictPort:true,proxy:{'/api':process.env.FIELDWORK_API_PROXY||'http://127.0.0.1:8788'}}});
