#!/usr/bin/env python3
"""One-time read-only SQLite import. Python's stdlib needs no PHP extensions."""
import fcntl
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile

root=Path(__file__).resolve().parents[1]
source=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else root/'storage/quiz.sqlite'
target=Path(sys.argv[2]).resolve() if len(sys.argv)>2 else root/'storage/quiz.json.php'
if not source.is_file(): raise SystemExit('Nie znaleziono źródłowej bazy SQLite.')
target.parent.mkdir(parents=True,exist_ok=True)
with open(str(target)+'.lock','a+b') as lock:
    os.chmod(str(target)+'.lock',0o600)
    fcntl.flock(lock,fcntl.LOCK_EX)
    if target.exists(): raise SystemExit('Plik JSON już istnieje. Niczego nie nadpisano.')
    data={'version':1,'next_ids':{}}
    with sqlite3.connect(source.as_uri()+'?mode=ro',uri=True) as db:
        db.row_factory=sqlite3.Row
        db.execute('BEGIN')
        for table in ['admins','quizzes','questions','runs','players','answers','limits']:
            rows=[dict(row) for row in db.execute('SELECT * FROM '+table)]
            data[table]={}
            for index,row in enumerate(rows):
                if table=='limits': row['id']=index+1
                if table=='questions': row['options']=json.loads(row['options'])
                if table=='runs':
                    row['snapshot']=json.loads(row['snapshot'])
                    for question in row['snapshot']:
                        if isinstance(question['options'],str): question['options']=json.loads(question['options'])
                data[table][str(row['id'])]=row
            data['next_ids'][table]=max((row['id'] for row in rows),default=0)
    temporary=None
    try:
        with tempfile.NamedTemporaryFile(mode='w',dir=target.parent,delete=False) as stream:
            temporary=Path(stream.name)
            os.chmod(temporary,0o600)
            stream.write('<?php http_response_code(404); exit; __halt_compiler();\n')
            json.dump(data,stream,ensure_ascii=False)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary,target)
    finally:
        if temporary and temporary.exists(): temporary.unlink()
print('Przeniesiono dane do JSON. Oryginalna baza SQLite pozostaje bez zmian.')
