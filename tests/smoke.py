#!/usr/bin/env python3
"""Integration tests against a real PHP server and isolated SQLite database."""
import base64
import concurrent.futures
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shlex
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None

class Browser:
    def __init__(self, base):
        self.base = base.rstrip('/')
        parsed=urllib.parse.urlsplit(self.base)
        self.origin=parsed.scheme+'://'+parsed.netloc
        self.prefix=parsed.path.rstrip('/')
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.csrf = None

    def call(self, path, data=None, expected=200, multipart=None, csrf=True):
        headers = {}
        body = None
        if data is not None:
            data = {k: str(v) for k, v in data.items()}
            if csrf and self.csrf: data['_csrf'] = self.csrf
            body = urllib.parse.urlencode(data).encode()
            headers['Content-Type'] = 'application/x-www-form-urlencoded'
        if multipart:
            fields, field, filename, payload, mime = multipart
            boundary = 'IskraTestBoundary12345'
            if csrf: fields = dict(fields, _csrf=self.csrf)
            chunks = []
            for key, value in fields.items():
                chunks.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+str(value)+'\r\n').encode())
            chunks.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+field+'"; filename="'+filename+'"\r\nContent-Type: '+mime+'\r\n\r\n').encode()+payload+b'\r\n')
            chunks.append(('--'+boundary+'--\r\n').encode())
            body = b''.join(chunks)
            headers['Content-Type'] = 'multipart/form-data; boundary='+boundary
        full_path=self.prefix and (path==self.prefix or path.startswith(self.prefix+'/'))
        request_url=self.origin+path if full_path else self.base+path
        req = urllib.request.Request(request_url, body, headers)
        try: response = self.opener.open(req, timeout=10)
        except urllib.error.HTTPError as error: response = error
        content = response.read().decode('utf-8', errors='replace')
        assert response.code == expected, (path, response.code, expected, content[:600])
        if response.headers.get('Content-Type','').startswith('text/html'):
            for value in re.findall(r'(?:href|src|action)="([^"]*)"',content):
                if value.startswith('/') and not value.startswith('//'):
                    assert value.startswith(self.prefix+'/'), ('Unprefixed HTML URL',value)
        location=response.headers.get('Location')
        if location:
            assert location.startswith(self.prefix+'/'), ('Unprefixed redirect',location)
        token = re.search(r'name="csrf-token" content="([^"]+)"', content)
        if token: self.csrf = token.group(1)
        return content, response.headers

    def state(self, token, host=False):
        return json.loads(self.call('/api/game/'+token+('?host=1' if host else ''))[0])

def run_tests(base, dbfile):
    admin, a, b, outsider = [Browser(base) for _ in range(4)]
    _,setup_headers=admin.call('/admin/setup')
    assert any('path='+admin.prefix+'/' in value for value in setup_headers.get_all('Set-Cookie',[]))
    for asset in ['/assets/style.css','/assets/app.js','/favicon.svg']:
        assert admin.call(asset)[1].get('Content-Type')
    assert 'Wskakuj do gry' in outsider.call('/')[0]
    assert 'Wskakuj do gry' in outsider.call('/index.php')[0]
    admin.call('/admin/setup', {'username':'host','password':'ą'*6}, 422)
    admin.call('/admin/setup', {'username':'host','password':'a'*73}, 422)
    admin.call('/admin/setup', {'username':'host','password':'test password 12345'}, 303)
    a.call('/ng'); b.call('/ng'); outsider.call('/ng')
    admin.call('/admin/setup', {'username':'evil','password':'test password 12345'},303)
    outsider.call('/admin', expected=303)
    outsider.call('/admin/quiz/create', {'title':'forged'},303)
    admin.call('/admin/quiz/create', {'title':'CSRF'},403,csrf=False)
    assert 'no-store' in admin.call('/admin')[1].get('Cache-Control','')

    _, headers = admin.call('/admin/quiz/create', {'title':'Wiedza <test>','description':'HTTP test'},303)
    quiz_path = headers['Location']
    quiz_id = int(quiz_path.rsplit('/',1)[1])
    new_question_html=admin.call(quiz_path+'/question/new')[0]
    assert re.search(r'id="duration"[^>]*value="10"',new_question_html)
    q = {'id':0,'text':'2 + 2 = ?','count':2,'correct':0,'duration':5,'option_0':'4','option_1':'5'}
    admin.call(quiz_path+'/question/save',dict(q,count=1),422)
    admin.call(quiz_path+'/question/save',dict(q,correct=2),422)
    admin.call(quiz_path+'/question/save',q,303)
    png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII=')
    image_q = dict(q,text='',count=3,option_2='6')
    admin.call(quiz_path+'/question/save',multipart=(image_q,'image','test.png',png,'image/png'),expected=303)
    image_answer = dict(q,text='Zdjęcie w odpowiedzi',count=4,option_0='',option_2='6',option_3='7')
    admin.call(quiz_path+'/question/save',multipart=(image_answer,'option_image_0','test.png',png,'image/png'),expected=303)
    admin.call(quiz_path+'/question/save',multipart=(q,'image','evil.php',b'<?php echo 1; ?>','image/png'),expected=422)
    admin.call(quiz_path+'/question/save',multipart=(q,'image','evil.svg',b'<svg xmlns="http://www.w3.org/2000/svg"></svg>','image/svg+xml'),expected=422)
    connection = sqlite3.connect(dbfile)
    ids = [r[0] for r in connection.execute('SELECT id FROM questions ORDER BY position')]
    assert len(ids) == 3
    admin.call(quiz_path+'/question/'+str(ids[1])+'/move',{'direction':-1},303)
    assert connection.execute('SELECT id FROM questions ORDER BY position').fetchone()[0] == ids[1]
    admin.call(quiz_path+'/question/'+str(ids[1])+'/move',{'direction':1},303)
    _, headers = admin.call(quiz_path+'/start',{},303)
    run_id = int(headers['Location'].rsplit('/',1)[1])
    host_html,_ = admin.call(headers['Location'])
    token = re.search(r'data-token="([a-f0-9]+)"',host_html).group(1)
    state = admin.state(token,True)
    assert state['state']=='lobby' and state['total']==3 and re.fullmatch(r'[1-9][0-9]{2}',state['code'])
    code = state['code']
    _,h=a.call('/ng',{'code':code,'name':'Ala <script>'},303)
    play_path=h['Location']
    b.call('/ng',{'code':code,'name':'ALA <script>'},422)
    b.call('/ng',{'code':code,'name':'Bartek'},303)
    assert a.call(play_path)[0].find('data-token')>=0
    outsider.call('/api/game/'+token,expected=403)
    outsider.call('/api/game/'+token+'?host=1',expected=401)
    outsider.call('/api/game/'+token+'/host',{'action':'next','index':-1},401)
    assert len(a.state(token)['players'])==2

    def action(action, index, expected=200):
        return admin.call('/api/game/'+token+'/host',{'action':action,'index':index},expected)
    def answer(browser, index, choice, expected=200):
        return browser.call('/api/game/'+token+'/answer',{'index':index,'choice':choice},expected)

    def set_start(start):
        connection.execute('UPDATE runs SET started_at=? WHERE id=?',(start,run_id))
        connection.commit()

    action('next',-1)
    action('next',-1,409)
    outsider.call('/ng',{'code':code,'name':'Spóźniony'},422)
    countdown=a.state(token)
    assert countdown['state']=='countdown' and countdown['index']==0
    assert 0<countdown['deadline']-countdown['serverTime']<=5
    assert countdown['question'] is None and countdown['correct'] is None
    assert countdown['answerStats'] is None and 'ranking' not in countdown
    answer(a,0,0,409)
    assert connection.execute('SELECT COUNT(*) FROM answers').fetchone()[0]==0
    # Move the server timestamp instead of sleeping through every phase.
    set_start(time.time()-.1)
    state=a.state(token)
    assert state['state']=='question' and state['correct'] is None and 'ranking' not in state
    assert state['answerStats'] is None
    answer(a,0,0)
    hidden=a.state(token)
    assert hidden['answered']==0 and hidden['points'] is None and hidden['isCorrect'] is None
    answer(a,0,1,409)
    time.sleep(.3)
    answer(b,0,1)
    set_start(time.time()-5-.1)
    answer(b,0,0,409)
    state=a.state(token)
    assert state['state']=='results' and state['correct']==0 and state['points']>=500
    assert state['ranking'][0]['name']=='Ala <script>' and state['ranking'][1]['score']==0
    first_points=state['points']
    assert state['ranking'][0]['roundPoints']==first_points
    assert state['ranking'][1]['roundPoints']==0
    assert state['answerStats']==[1,1] and state['unansweredCount']==0
    assert 14<state['deadline']-state['serverTime']<=15
    report=admin.call('/admin/run/'+str(run_id))[0]
    assert 'Ala &lt;script&gt;' in report and '<script>' not in report

    # Results remain visible until the full 15 seconds have passed.
    set_start(time.time()-5-15+2)
    assert a.state(token)['state']=='results'
    set_start(time.time()-5-15-.1)
    # Both polling clients see the same next countdown; no host action is needed.
    with concurrent.futures.ThreadPoolExecutor(2) as pool:
        states=list(pool.map(lambda browser: browser.state(token),(a,b)))
    assert all(s['state']=='countdown' and s['index']==1 and s['question'] is None for s in states)
    assert abs(states[0]['deadline']-states[1]['deadline'])<.001
    action('next',0,409)
    answer(a,0,0,409)
    answer(a,1,0,409)
    set_start(time.time()-.1)
    state=a.state(token)
    assert state['state']=='question'
    assert len(state['question']['options'])==3 and state['question']['image']
    assert state['question']['image'].startswith(a.prefix+'/uploads/')
    assert a.call(state['question']['image'])[1].get('Content-Type')=='image/png'
    # Real separate browser sessions race to write SQLite, then duplicate submissions.
    with concurrent.futures.ThreadPoolExecutor(2) as pool:
        futures=[pool.submit(answer, browser, 1, 0) for browser in (a,b)]
        for future in futures: future.result()
    answer(a,1,0,409)
    set_start(time.time()-5-.1)
    round_two=a.state(token)
    assert round_two['state']=='results'
    assert round_two['answerStats']==[2,0,0] and round_two['unansweredCount']==0
    ala=next(row for row in round_two['ranking'] if row['name']=='Ala <script>')
    bartek=next(row for row in round_two['ranking'] if row['name']=='Bartek')
    assert ala['score']==first_points+ala['roundPoints']
    assert bartek['score']==bartek['roundPoints']>0

    set_start(time.time()-5-15-.1)
    # A stale host request commits the automatic transition instead of undoing it.
    action('next',1,409)
    assert connection.execute('SELECT question_index FROM runs WHERE id=?',(run_id,)).fetchone()[0]==2
    assert a.state(token)['state']=='countdown'
    set_start(time.time()-.1)
    image_answer_state=a.state(token)
    assert len(image_answer_state['question']['options'])==4
    answer_image=image_answer_state['question']['options'][0]['image']
    assert answer_image.startswith(a.prefix+'/uploads/')
    assert a.call(answer_image)[1].get('Content-Type')=='image/png'
    set_start(time.time()-5-.1)
    last_round=a.state(token)
    assert last_round['state']=='results'
    assert last_round['answerStats']==[0,0,0,0] and last_round['unansweredCount']==2
    assert all(row['roundPoints']==0 for row in last_round['ranking'])
    set_start(time.time()-5-15+2)
    assert a.state(token)['state']=='results'
    set_start(time.time()-5-15-.1)
    state=a.state(token)
    assert state['state']=='finished' and len(state['ranking'])==2
    assert connection.execute('SELECT COUNT(*) FROM answers').fetchone()[0]==4
    admin.call('/admin/history')
    admin.call('/admin/run/'+str(run_id))
    # The host can skip the results delay, including the final wait before the podium.
    _,h=admin.call(quiz_path+'/start',{},303)
    skip_id=int(h['Location'].rsplit('/',1)[1])
    skip_html=admin.call(h['Location'])[0]
    skip_token=re.search(r'data-token="([a-f0-9]+)"',skip_html).group(1)
    skip_state=admin.state(skip_token,True)
    a.call('/ng',{'code':skip_state['code'],'name':'Test przycisku'},303)
    skip_endpoint='/api/game/'+skip_token
    admin.call(skip_endpoint+'/host',{'action':'next','index':-1})
    for index in range(3):
        assert a.state(skip_token)['state']=='countdown'
        # Neither the countdown nor the active question can be skipped by this button.
        admin.call(skip_endpoint+'/host',{'action':'next','index':index},409)
        connection.execute('UPDATE runs SET started_at=? WHERE id=?',(time.time()-.1,skip_id))
        connection.commit()
        admin.call(skip_endpoint+'/host',{'action':'next','index':index},409)
        connection.execute('UPDATE runs SET started_at=? WHERE id=?',(time.time()-5-.1,skip_id))
        connection.commit()
        results=a.state(skip_token)
        assert results['state']=='results' and 14<results['deadline']-results['serverTime']<=15
        a.call(skip_endpoint+'/host',{'action':'next','index':index},401)
        admin.call(skip_endpoint+'/host',{'action':'next','index':index})
        after=a.state(skip_token)
        if index<2:
            assert after['state']=='countdown' and after['index']==index+1
            assert after['question'] is None and 0<after['deadline']-after['serverTime']<=5
            admin.call(skip_endpoint+'/host',{'action':'next','index':index},409)
        else:
            assert after['state']=='finished' and after['ranking'][0]['score']==0

    # A client returning after several missed phases catches up to the final ranking.
    _,h=admin.call(quiz_path+'/start',{},303)
    idle_id=int(h['Location'].rsplit('/',1)[1])
    idle_html=admin.call(h['Location'])[0]
    idle_token=re.search(r'data-token="([a-f0-9]+)"',idle_html).group(1)
    idle_state=admin.state(idle_token,True)
    admin.call('/api/game/'+idle_token+'/host',{'action':'next','index':-1},422)
    a.call('/ng',{'code':idle_state['code'],'name':'Ala'},303)
    admin.call('/api/game/'+idle_token+'/host',{'action':'next','index':-1})
    connection.execute('UPDATE runs SET started_at=? WHERE id=?',(time.time()-1000,idle_id))
    connection.commit()
    caught_up=a.state(idle_token)
    assert caught_up['state']=='finished' and caught_up['index']==2
    assert caught_up['answerStats']==[0,0,0,0]

    # Editing/deleting a quiz does not mutate a run snapshot or historical images.
    before=connection.execute('SELECT snapshot FROM runs WHERE id=?',(run_id,)).fetchone()[0]
    admin.call(quiz_path+'/save',{'title':'Nowa nazwa','description':''},303)
    admin.call(quiz_path+'/delete',{},303)
    assert connection.execute('SELECT snapshot FROM runs WHERE id=?',(run_id,)).fetchone()[0]==before
    assert connection.execute('SELECT quiz_id FROM runs WHERE id=?',(run_id,)).fetchone()[0] is None
    admin.call('/admin/run/'+str(run_id))
    assert a.state(token)['title']=='Wiedza <test>'
    # A fresh run has an independent ranking; abandoned codes expire without cron.
    _,h=admin.call('/admin/quiz/create',{'title':'Druga gra'},303)
    second=h['Location']; admin.call(second+'/question/save',q,303)
    _,h=admin.call(second+'/start',{},303)
    html=admin.call(h['Location'])[0]; token2=re.search(r'data-token="([a-f0-9]+)"',html).group(1)
    state2=admin.state(token2,True)
    assert state2['ranking']==[]
    a.call('/ng',{'code':state2['code'],'name':'Ala'},303)
    b.call('/ng',{'code':state2['code'],'name':'Bartek'},303)
    admin.call('/api/game/'+token2+'/host',{'action':'next','index':-1})
    assert a.state(token2)['state']=='countdown'
    connection.execute('UPDATE runs SET started_at=? WHERE token=?',(time.time()-.1,token2))
    connection.commit()
    a.call('/api/game/'+token2+'/answer',{'index':0,'choice':0})
    time.sleep(.3)
    b.call('/api/game/'+token2+'/answer',{'index':0,'choice':0})
    scores=connection.execute('SELECT p.name,a.points FROM answers a JOIN players p ON p.id=a.player_id WHERE a.run_id=(SELECT id FROM runs WHERE token=?) ORDER BY a.points DESC',(token2,)).fetchall()
    assert scores[0][0]=='Ala' and scores[0][1]>scores[1][1]>=500
    connection.execute('UPDATE runs SET created_at=? WHERE token=?',(int(time.time())-43201,token2));connection.commit()
    assert admin.state(token2,True)['state']=='finished'
    outsider.call('/storage/quiz.sqlite',expected=404)
    outsider.call('/app/bootstrap.php',expected=404)
    outsider.call('/.git/config',expected=404)
    outsider.call('/api/nope',expected=404)
    admin.call('/admin/logout',{},303)
    admin.call('/api/game/'+token+'?host=1',expected=401)
    login=Browser(base);login.call('/admin/login')
    login.call('/admin/login',{'username':'host','password':'wrong'},401)
    login.call('/admin/login',{'username':'host','password':'test password 12345'},303)
    assert 'Twoje quizy' in login.call('/admin')[0]
    connection.close()
    print('PASS: setup/login, auth/CSRF, quiz CRUD/order, 2/3/4 answers, image uploads,')
    print('      join/names/reconnect, live timing, hidden correctness, duplicate/late answers,')
    print('      concurrent writes, scoring/ranking, snapshots/history, expiry, data isolation,')
    print('      10s default, hidden 5s countdown, 15s auto-advance + admin skip, per-round points, vote stats,')
    print('      stale host requests and automatic catch-up after missed phases.')

def main():
    php=os.environ.get('PHP_BIN','php')
    php_args=shlex.split(os.environ.get('PHP_ARGS',''))
    for prefix in ['', '/~test/integracja/public']:
        with tempfile.TemporaryDirectory(prefix='iskra-test-') as temporary:
            dbfile=str(Path(temporary)/'test.sqlite')
            with socket.socket() as sock:
                sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
            origin='http://127.0.0.1:'+str(port)
            base=origin+prefix
            env=dict(os.environ,QUIZ_DB=dbfile,PHP_CLI_SERVER_WORKERS='4',QUIZ_TEST_PREFIX=prefix)
            log=Path(temporary)/'php.log'
            with log.open('w') as stream:
                process=subprocess.Popen([php,*php_args,'-d','session.save_path='+temporary,
                    '-S','127.0.0.1:'+str(port),'-t','public','tests/subdirectory-router.php'],
                    cwd=ROOT,env=env,stdout=stream,stderr=stream,start_new_session=True)
                try:
                    for _ in range(60):
                        try:
                            urllib.request.urlopen(base+'/ng',timeout=1).close();break
                        except (urllib.error.URLError,TimeoutError):
                            if process.poll() is not None: raise RuntimeError(log.read_text())
                            time.sleep(.1)
                    else: raise RuntimeError('PHP server did not start')
                    print('Mount:',prefix or '/')
                    run_tests(base,dbfile)
                except Exception:
                    print(log.read_text()[-3000:]);raise
                finally:
                    if os.name!='nt':
                        import signal
                        os.killpg(process.pid,signal.SIGTERM)
                    else: process.terminate()
                    process.wait(timeout=10)
                    # Remove only upload paths referenced by this disposable test database.
                    with sqlite3.connect(dbfile) as cleanup:
                        blobs=[str(row) for row in cleanup.execute('SELECT image,options FROM questions')]
                        blobs += [row[0] for row in cleanup.execute('SELECT snapshot FROM runs')]
                        for filename in set(re.findall(r'/uploads/([a-f0-9]{32}\.[a-z]+)', ' '.join(blobs).replace(chr(92)+'/','/'))):
                            (ROOT/'public'/'uploads'/filename).unlink(missing_ok=True)

if __name__=='__main__': main()
