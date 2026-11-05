"""Exercise the real installer against disposable paths and a fake PHP/backup process."""
from pathlib import Path
import os, shutil, subprocess, tempfile
root = Path(__file__).resolve().parents[1]
bash = Path('C:/Program Files/Git/bin/bash.exe')
assert bash.exists(), 'Git Bash is required for isolated installer validation'
def unix(path):
    path = path.resolve().as_posix()
    return '/' + path[0].lower() + path[2:]
checks = 0
for failure in ['', 'backup', 'migrate']:
    with tempfile.TemporaryDirectory(prefix='team-installer-', dir=root/'var') as temp:
        base=Path(temp);domain=base/'domain';package=base/'package';binpath=base/'bin'
        for p in [domain/'public_html',domain/'fieldwork/backend',package/'public_html',package/'fieldwork/backend',binpath]:p.mkdir(parents=True,exist_ok=True)
        (domain/'fieldwork/config.local.php').write_text('private fixture configuration')
        (domain/'fieldwork/backend/router.php').write_text('old backend')
        (domain/'public_html/index.html').write_text('old website')
        (domain/'public_html/.htaccess').write_text('RewriteEngine On\n')
        for name in ['router.php','hosted-router.php','migrate.php']:(package/'fieldwork/backend'/name).write_text('new backend')
        (package/'public_html/index.html').write_text('new website')
        shutil.copy(root/'deploy/public_html/.htaccess',package/'public_html/.htaccess')
        (package/'backup-database.php').write_text('fake backup entry')
        script=(root/'deploy/install-upgrade.sh').read_text().replace('domain=/home/supashop/domains/reach.vumbualabs.com','domain="'+unix(domain)+'"')
        (package/'install-upgrade.sh').write_text(script,newline='\n')
        (binpath/'php').write_text('''#!/usr/bin/env bash
case "$1" in
  *backup-database.php) [[ "$FAKE_FAIL" != backup ]] || exit 13; printf 'synthetic SQL backup' > "$3";;
  *migrate.php) [[ "$FAKE_FAIL" != migrate ]] || exit 11;;
  -r) printf 'synthetic-mail-key';;
esac
exit 0
''',newline='\n')
        env=os.environ.copy();env['FAKE_FAIL']=failure
        command='export PATH="'+unix(binpath)+':$PATH"; bash "'+unix(package/'install-upgrade.sh')+'"'
        result=subprocess.run([str(bash),'-c',command],env=env,capture_output=True,text=True)
        assert (result.returncode==0)==(failure==''), f'exit={result.returncode} scenario={failure!r} '+result.stdout+result.stderr
        backups=list((domain/'fieldwork-backups').glob('*/database.sql'))
        if failure=='backup':
            assert not (domain/'public_html/.reach-maintenance').exists()
            assert (domain/'public_html/index.html').read_text()=='old website'
        else:
            assert backups and backups[0].read_text()=='synthetic SQL backup'
            assert (backups[0].parent/'backend/router.php').read_text()=='old backend'
            assert (domain/'fieldwork/config.local.php').read_text()=='private fixture configuration'
            if failure=='migrate':
                assert (domain/'public_html/.reach-maintenance').exists()
                assert 'reach-maintenance' in (domain/'public_html/.htaccess').read_text()
                assert (domain/'fieldwork/backend/router.php').read_text()=='old backend'
                assert (domain/'public_html/index.html').read_text()=='old website'
            else:
                assert not (domain/'public_html/.reach-maintenance').exists()
                assert (domain/'public_html/index.html').read_text()=='new website'
        checks+=1
        print('PASS installer '+(failure+' failure recovery' if failure else 'backup-first success'))
print(f'Installer scenarios passed: {checks}. No deployed paths or database accessed.')
