#!/usr/bin/env python3
"""Concurrency, atomic reads, rollback and corruption protection for JSON storage."""
import hashlib
import json
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import time

root=Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BIN','php')
flags=shlex.split(os.environ.get('PHP_ARGS',''))
def run_profile(shared):
    with tempfile.TemporaryDirectory(prefix='iskra-locks-') as temporary:
        directory=Path(temporary)/'data'
        directory.mkdir(mode=0o700)
        group=os.getgid()
        if shared:
            group=next((g for g in os.getgroups() if g!=os.getgid()),group)
            os.chown(directory,-1,group)
            directory.chmod(0o2770)
        mode=0o660 if shared else 0o600
        file=directory/'quiz.json.php'
        command=[php,*flags,str(root/'tests/store-worker.php'),str(file)]
        processes=[subprocess.Popen(command,stdout=subprocess.PIPE,stderr=subprocess.PIPE,umask=0o077) for _ in range(4)]
        reads=0
        while any(process.poll() is None for process in processes):
            if file.exists():
                assert json.loads(file.read_text().partition(chr(10))[2])['version']==1
                reads+=1
            time.sleep(.003)
        for process in processes:
            out,err=process.communicate()
            assert process.returncode==0,err.decode()
        data=json.loads(file.read_text().partition(chr(10))[2])
        assert len(data['quizzes'])==160
        assert sorted(row['id'] for row in data['quizzes'].values())==list(range(1,161))
        assert len({row['title'] for row in data['quizzes'].values()})==160
        private_response=subprocess.run([php,*flags,str(file)],capture_output=True)
        assert private_response.returncode==0 and private_response.stdout==b''
        before=hashlib.sha256(file.read_bytes()).hexdigest()
        subprocess.run(command+['rollback'],check=True)
        assert hashlib.sha256(file.read_bytes()).hexdigest()==before
        lock=Path(str(file)+'.lock')
        for path in [file,lock]:
            assert path.stat().st_mode&0o777==mode
            if shared: assert path.stat().st_gid==group

        if shared:
            # Upgrade existing PHP-owned 600 files to a shared group on a read,
            # preserving every byte and without needing the deploy user to own them.
            for path in [file,lock]:
                os.chown(path,-1,os.getgid())
                path.chmod(0o600)
            subprocess.run(command+['read'],check=True)
            assert hashlib.sha256(file.read_bytes()).hexdigest()==before
            for path in [file,lock]:
                assert path.stat().st_mode&0o777==0o660 and path.stat().st_gid==group

        file.write_text('{broken JSON')
        result=subprocess.run(command,capture_output=True)
        assert result.returncode!=0 and file.read_text()=='{broken JSON'
        assert file.stat().st_mode&0o777==mode
        print('PASS:', 'shared 660 + inherited group' if shared else 'private 600',
              '- 160 concurrent inserts, atomic reads, rollback, corrupt-file protection.')

def test_permissions_script():
    import shutil
    with tempfile.TemporaryDirectory(prefix='iskra-permissions-') as temporary:
        project=Path(temporary)
        for folder in ['app','public/uploads','scripts','storage']:
            (project/folder).mkdir(parents=True,exist_ok=True)
        for filename in ['app/store.php','public/index.php','storage/.htaccess','storage/.gitkeep',
                         'public/uploads/.htaccess','public/uploads/.gitkeep']:
            (project/filename).touch()
        script=project/'scripts/permissions.sh'
        shutil.copy(root/'scripts/permissions.sh',script)
        data=project/'storage/quiz.json.php'
        data.write_bytes(b'Existing data sentinel')
        lock=project/'storage/quiz.json.php.lock'
        lock.touch()
        image=project/'public/uploads/existing.png'
        image.write_bytes(b'Existing upload sentinel')
        before={p:p.read_bytes() for p in [data,lock,image]}
        def run(*args):
            subprocess.run(['sh',str(script),*args],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        run()
        assert data.stat().st_mode&0o777==0o600
        # Use only a group the test user already belongs to.
        group=next((g for g in os.getgroups() if g!=os.getgid()),os.getgid())
        run('--shared-data',str(group))
        run()  # A later pull/default permission repair must keep team access.
        assert (project/'storage').stat().st_mode&0o7777==0o2770
        for path in [data,lock]:
            assert path.stat().st_mode&0o777==0o660 and path.stat().st_gid==group
        assert image.stat().st_mode&0o777==0o644
        for path,content in before.items(): assert path.read_bytes()==content
        print('PASS: permissions script preserves shared mode and contents across later updates.')

run_profile(False)
run_profile(True)
test_permissions_script()
