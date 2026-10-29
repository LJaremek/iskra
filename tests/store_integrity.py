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
with tempfile.TemporaryDirectory(prefix='iskra-locks-') as directory:
    file=Path(directory)/'quiz.json.php'
    command=[php,*flags,str(root/'tests/store-worker.php'),str(file)]
    processes=[subprocess.Popen(command,stdout=subprocess.PIPE,stderr=subprocess.PIPE) for _ in range(4)]
    reads=0
    while any(process.poll() is None for process in processes):
        if file.exists():
            assert json.loads(file.read_text().partition('\n')[2])['version']==1
            reads+=1
        time.sleep(.003)
    for process in processes:
        out,err=process.communicate()
        assert process.returncode==0,err.decode()
    data=json.loads(file.read_text().partition('\n')[2])
    assert len(data['quizzes'])==160
    assert sorted(row['id'] for row in data['quizzes'].values())==list(range(1,161))
    assert len({row['title'] for row in data['quizzes'].values()})==160
    private_response=subprocess.run([php,*flags,str(file)],capture_output=True)
    assert private_response.returncode==0 and private_response.stdout==b''
    before=hashlib.sha256(file.read_bytes()).hexdigest()
    subprocess.run(command+['rollback'],check=True)
    assert hashlib.sha256(file.read_bytes()).hexdigest()==before
    file.write_text('{broken JSON')
    result=subprocess.run(command,capture_output=True)
    assert result.returncode!=0 and file.read_text()=='{broken JSON'
    assert file.stat().st_mode&0o777==0o600
    print('PASS: 160 concurrent inserts, atomic reads, rollback, corrupt-file protection, private permissions.')
