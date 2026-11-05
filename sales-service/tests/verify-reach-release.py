"""Validate prepared ZIP integrity, exclusions, source parity and known-secret absence."""
import hashlib
import json
from pathlib import Path, PurePosixPath
import zipfile

root = Path(__file__).resolve().parents[1]
repo = root.parent
publish = root / 'publish'
manifest = json.loads((repo / 'sales-workspace-hosted/.codex-plugin/plugin.json').read_text(encoding='utf-8'))
version = manifest['version']
secrets = []
for name in ['local-account.json', 'reach-preview-account.json', 'plugin-access.json']:
    file = root / 'var' / name
    if file.exists():
        value = json.loads(file.read_text(encoding='utf-8-sig'))
        for key in ['password', 'token']:
            if isinstance(value.get(key), str) and len(value[key]) >= 12:
                secrets.append(value[key].encode())

def inspect(name, staging):
    archive = publish / name
    with zipfile.ZipFile(archive) as z:
        assert z.testzip() is None, f'{name}: CRC failure'
        files = [i for i in z.infolist() if not i.is_dir()]
        expected = {p.relative_to(staging).as_posix(): p for p in staging.rglob('*') if p.is_file()}
        actual = set()
        for item in files:
            path = PurePosixPath(item.filename)
            assert not path.is_absolute() and '..' not in path.parts and ':' not in item.filename, 'Unsafe path'
            rel = str(path)
            actual.add(rel)
            assert not any(part.lower() in {'var', 'sessions', 'node_modules', '.git', 'tests', 'tmp'} for part in path.parts), f'Private path: {rel}'
            assert path.name not in {'config.local.php', 'local-account.json', 'credential.txt', 'mail.key', '.env'}, f'Private file: {rel}'
            assert not path.name.startswith('.env.'), f'Environment file: {rel}'
            content = z.read(item)
            assert not any(secret in content for secret in secrets), f'Known local credential in {rel}'
            assert b'-----BEGIN PRIVATE KEY-----' not in content, f'Private key in {rel}'
            assert rel in expected and content == expected[rel].read_bytes(), f'Staging parity: {rel}'
        assert actual == set(expected), f'{name}: archive file inventory differs from staging'
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    result = {'file': name, 'bytes': archive.stat().st_size, 'sha256': digest, 'files': len(files)}
    print(json.dumps(result))
    return result

server_stage = publish / 'upgrade-stage'
server = inspect('fieldwork-reach-upgrade.zip', server_stage)
local_only = {'add-user.php', 'provision-local.php', 'recover-local-owner.php', 'setup.php'}
for file in (root / 'backend').glob('*'):
    if file.is_file() and file.name not in local_only:
        assert file.read_bytes() == (server_stage / 'fieldwork/backend' / file.name).read_bytes(), f'Backend source parity: {file.name}'
for file in (root / 'dist').rglob('*'):
    if file.is_file():
        assert file.read_bytes() == (server_stage / 'public_html' / file.relative_to(root / 'dist')).read_bytes(), f'Built frontend parity: {file.name}'
for name in local_only:
    assert not (server_stage / 'fieldwork/backend' / name).exists(), 'Local bootstrap helper packaged'
assert (server_stage / 'RESEARCH-2026-10-15.md').exists()
assert (server_stage / 'TEAM-ACCESS-2026-10-16.md').exists()
assert (server_stage / 'CONTACTS-2026-10-19.md').exists()
assert (server_stage / 'SCHEDULING-2026-10-20.md').exists()
assert b'\r' not in (server_stage / 'install-upgrade.sh').read_bytes()
assert b'reach-maintenance' in (server_stage / 'public_html/.htaccess').read_bytes()

plugin_stage = publish / ('plugin-stage-' + version)
plugin = inspect(f'fieldwork-reach-plugin-{version}.zip', plugin_stage)
source = repo / 'sales-workspace-hosted'
for file in source.rglob('*'):
    if file.is_file():
        assert file.read_bytes() == (plugin_stage / 'sales-workspace-hosted' / file.relative_to(source)).read_bytes(), f'Plugin source parity: {file.name}'
assert json.loads((plugin_stage / 'sales-workspace-hosted/.codex-plugin/plugin.json').read_text())['version'] == version
assert (plugin_stage / 'sales-workspace-hosted/skills/agent-work/references/research.md').exists()
local_source = repo / 'sales-workspace'
local_version = json.loads((local_source / '.codex-plugin/plugin.json').read_text())['version']
local_stage = publish / ('local-plugin-stage-' + local_version)
local_plugin = inspect(f'fieldwork-local-plugin-{local_version}.zip', local_stage)
for file in local_source.rglob('*'):
    if file.is_file():
        assert file.read_bytes() == (local_stage / 'sales-workspace' / file.relative_to(local_source)).read_bytes(), f'Local plugin parity: {file.name}'
(publish / 'RELEASE-CHECKSUMS-2026-10-20.json').write_text(json.dumps({'status': 'archives_verified_see_publication_record_for_live_status', 'artifacts': [server, plugin, local_plugin]}, indent=2) + '\n', encoding='utf-8')
print('PASS: CRC, safe paths, private-file exclusions, known local credential absence, source/staging parity, plugin version/reference.')
