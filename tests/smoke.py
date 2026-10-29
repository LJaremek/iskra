#!/usr/bin/env python3
"""Integration tests against a real PHP server and isolated JSON store."""
import base64
import concurrent.futures
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shlex
import socket
import fcntl
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None

class DataFile:
    def __init__(self,path): self.path=Path(path)
    def read(self):
        with open(str(self.path)+'.lock','a+b') as lock:
            fcntl.flock(lock,fcntl.LOCK_SH)
            return json.loads(self.path.read_text().partition('\n')[2])
    def rows(self,table):
        rows=self.read()[table]
        return list(rows.values()) if isinstance(rows,dict) else rows
    def find(self,table,**matches):
        return next(r for r in self.rows(table) if all(r[k]==v for k,v in matches.items()))
    def patch(self,table,row_id,remove=(),**changes):
        with open(str(self.path)+'.lock','a+b') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX)
            data=json.loads(self.path.read_text().partition('\n')[2])
            data[table][str(row_id)].update(changes)
            for key in remove: data[table][str(row_id)].pop(key,None)
            with tempfile.NamedTemporaryFile(mode='w',dir=self.path.parent,delete=False) as tmp:
                tmp.write('<?php http_response_code(404); exit; __halt_compiler();\n')
                json.dump(data,tmp,ensure_ascii=False)
            os.replace(tmp.name,self.path)

class Browser:
    def __init__(self, base):
        self.base = base.rstrip('/')
        parsed=urllib.parse.urlsplit(self.base)
        self.origin=parsed.scheme+'://'+parsed.netloc
        self.prefix=parsed.path.rstrip('/')
        self.pretty=os.environ.get('QUIZ_PRETTY_URLS')=='1'
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.csrf = None

    def call(self, path, data=None, expected=200, multipart=None, csrf=True):
        headers = {}
        body = None
        if data is not None:
            data = {k: v if isinstance(v,list) else str(v) for k, v in data.items()}
            if csrf and self.csrf: data['_csrf'] = self.csrf
            body = urllib.parse.urlencode(data,doseq=True).encode()
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
        local=path[len(self.prefix):] if self.prefix and path.startswith(self.prefix+'/') else path
        parsed_path=urllib.parse.urlsplit(local)
        direct=parsed_path.path.startswith(('/assets/','/uploads/','/favicon.svg','/index.php'))
        if direct or self.pretty or parsed_path.path=='/':
            request_url=self.base+local
        elif parsed_path.path.rstrip('/')=='/ng':
            request_url=self.base+'/ng/'+('?' + parsed_path.query if parsed_path.query else '')
        else:
            request_url=self.base+'/index.php?route='+urllib.parse.quote(parsed_path.path,safe='')
            if parsed_path.query:request_url+='&'+parsed_path.query
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

    def route(self,location):
        parsed=urllib.parse.urlsplit(location)
        route=urllib.parse.parse_qs(parsed.query).get('route',[None])[0]
        if route is not None:return route
        return parsed.path[len(self.prefix):] if self.prefix else parsed.path

    def state(self, token, host=False):
        return json.loads(self.call('/api/game/'+token+('?host=1' if host else ''))[0])

def run_tests(base, dbfile):
    admin, a, b, outsider = [Browser(base) for _ in range(4)]
    _,setup_headers=admin.call('/admin/setup')
    assert any('path='+admin.prefix+'/' in value for value in setup_headers.get_all('Set-Cookie',[]))
    for asset in ['/assets/style.css','/assets/app.js','/assets/vendor/qrcode.js','/favicon.svg']:
        assert admin.call(asset)[1].get('Content-Type')
    join_html=outsider.call('/')[0]
    assert 'Wskakuj do gry' in join_html and 'Pokaż QR strony dołączania' in join_html
    assert 'data-qr-path="'+admin.prefix+'/ng/"' in join_html
    assert join_html.index('/assets/vendor/qrcode.js')<join_html.index('/assets/app.js')
    assert re.search(r'id="code"[^>]*value=""',join_html)
    for query in ['code=12','code=000','code=1234','code%5B%5D=123','code=%22%3E%3Csvg%20onload=alert(1)%3E']:
        invalid_html=outsider.call('/ng/?'+query)[0]
        assert re.search(r'id="code"[^>]*value=""',invalid_html)
        assert '<svg onload' not in invalid_html
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
    error_html=outsider.call('/not-a-route',expected=404)[0]
    assert 'data-back' not in error_html and '← Wróć</a>' in error_html


    _, headers = admin.call('/admin/quiz/create', {'title':'Wiedza <test>','description':'HTTP test'},303)
    quiz_path = admin.route(headers['Location'])
    quiz_id = int(quiz_path.rsplit('/',1)[1])
    new_question_html=admin.call(quiz_path+'/question/new')[0]
    assert re.search(r'id="duration"[^>]*value="10"',new_question_html)
    q = {'id':0,'text':'2 + 2 = ?','count':2,'correct':0,'duration':5,'option_0':'4','option_1':'5'}
    admin.call(quiz_path+'/question/save',dict(q,count=1),422)
    admin.call(quiz_path+'/question/save',dict(q,correct=2),422)
    for invalid in [[],['2'],['-1'],['x'],['0','1','0'],{'bad':'0'}]:
        payload=dict(q)
        payload.pop('correct')
        # A missing checkbox group and nested/malformed input must also be rejected.
        payload['correct[]' if not isinstance(invalid,dict) else 'correct[bad][]']=invalid if not isinstance(invalid,dict) else ['0']
        admin.call(quiz_path+'/question/save',payload,422)
    admin.call(quiz_path+'/question/save',q,303)
    png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII=')
    image_q = dict(q,text='',count=3,option_2='6')
    admin.call(quiz_path+'/question/save',multipart=(image_q,'image','test.png',png,'image/png'),expected=303)
    image_answer = dict(q,text='Zdjęcie w odpowiedzi',count=4,option_0='',option_2='6',option_3='7')
    admin.call(quiz_path+'/question/save',multipart=(image_answer,'option_image_0','test.png',png,'image/png'),expected=303)
    admin.call(quiz_path+'/question/save',multipart=(q,'image','evil.php',b'<?php echo 1; ?>','image/png'),expected=422)
    admin.call(quiz_path+'/question/save',multipart=(q,'image','evil.svg',b'<svg xmlns="http://www.w3.org/2000/svg"></svg>','image/svg+xml'),expected=422)
    data = DataFile(dbfile)
    ids = [r['id'] for r in sorted(data.rows('questions'),key=lambda q:q['position'])]
    assert len(ids) == 3
    # Reproduce the original on-disk format, without a migration.
    data.patch('questions',ids[0],remove=('correct_choices',))
    legacy_values=data.find('questions',id=ids[0])
    legacy_quiz=data.find('quizzes',id=quiz_id)
    upgraded=dict(q,id=ids[0],**{'correct[]':['0','1']})
    upgraded.pop('correct')
    admin.call(quiz_path+'/question/save',upgraded,303)
    upgraded_values=data.find('questions',id=ids[0])
    assert upgraded_values['correct_choices']==[0,1]
    assert {k:v for k,v in upgraded_values.items() if k!='correct_choices'}==legacy_values
    assert data.find('quizzes',id=quiz_id)==legacy_quiz
    data.patch('questions',ids[0],remove=('correct_choices',))
    legacy_editor=admin.call(quiz_path+'/question/'+str(ids[0]))[0]
    assert re.search(r'type="checkbox" name="correct\[\]" value="0" checked',legacy_editor)
    assert len(re.findall(r'name="correct\[\]"[^>]*checked',legacy_editor))==1
    multi=dict(image_q,id=ids[1],**{'correct[]':['2','0','2']})
    multi.pop('correct')
    admin.call(quiz_path+'/question/save',multi,303)
    assert data.find('questions',id=ids[1])['correct_choices']==[0,2]
    multi_editor=admin.call(quiz_path+'/question/'+str(ids[1]))[0]
    assert len(re.findall(r'name="correct\[\]"[^>]*checked',multi_editor))==2
    # An invalid update must preserve the existing question.
    before_invalid=data.find('questions',id=ids[1])
    admin.call(quiz_path+'/question/save',dict(multi,**{'correct[]':['3']}),422)
    assert data.find('questions',id=ids[1])==before_invalid
    admin.call(quiz_path+'/question/'+str(ids[1])+'/move',{'direction':-1},303)
    assert min(data.rows('questions'),key=lambda q:q['position'])['id'] == ids[1]
    admin.call(quiz_path+'/question/'+str(ids[1])+'/move',{'direction':1},303)
    _, headers = admin.call(quiz_path+'/start',{},303)
    run_id = int(admin.route(headers['Location']).rsplit('/',1)[1])
    host_html,_ = admin.call(headers['Location'])
    token = re.search(r'data-token="([a-f0-9]+)"',host_html).group(1)
    state = admin.state(token,True)
    assert state['state']=='lobby' and state['total']==3 and re.fullmatch(r'[1-9][0-9]{2}',state['code'])
    original_snapshot=data.find('runs',id=run_id)['snapshot']
    assert 'correct_choices' not in original_snapshot[0]
    # Editing the quiz must not change an already created run or history.
    admin.call(quiz_path+'/question/save',dict(multi,**{'correct[]':['1']}),303)
    assert data.find('runs',id=run_id)['snapshot']==original_snapshot
    # Restore the quiz for later independent runs.
    admin.call(quiz_path+'/question/save',multi,303)
    code = state['code']
    qr_join=a.call('/ng/?code='+code)[0]
    assert re.search(r'id="code"[^>]*value="'+code+'"',qr_join)
    assert re.search(r'id="name"[^>]*autofocus',qr_join)
    assert len(data.rows('players'))==0  # Scanning opens the form; joining still needs a name.
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
        data.patch('runs',run_id,started_at=start)

    action('next',-1)
    action('next',-1,409)
    outsider.call('/ng',{'code':code,'name':'Spóźniony'},422)
    countdown=a.state(token)
    assert countdown['state']=='countdown' and countdown['index']==0
    assert 0<countdown['deadline']-countdown['serverTime']<=5
    assert countdown['question'] is None and countdown['correct'] is None and countdown['correctChoices'] is None
    assert countdown['answerStats'] is None and 'ranking' not in countdown
    answer(a,0,0,409)
    assert len(data.rows('answers'))==0
    # Move the server timestamp instead of sleeping through every phase.
    set_start(time.time()-.1)
    state=a.state(token)
    assert state['state']=='question' and state['correct'] is None and 'ranking' not in state
    assert state['answerStats'] is None and state['correctChoices'] is None
    answer(a,0,0)
    hidden=a.state(token)
    assert hidden['answered']==0 and hidden['points'] is None and hidden['isCorrect'] is None
    answer(a,0,1,409)
    time.sleep(.3)
    answer(b,0,1)
    set_start(time.time()-5-.1)
    answer(b,0,0,409)
    state=a.state(token)
    assert state['state']=='results' and state['correct']==0 and state['correctChoices']==[0] and state['points']>=500
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
    # Different correct options both earn full speed-based points; one immutable choice per player.
    with concurrent.futures.ThreadPoolExecutor(2) as pool:
        futures=[pool.submit(answer, browser, 1, choice) for browser,choice in ((a,0),(b,2))]
        for future in futures: future.result()
    answer(a,1,0,409)
    set_start(time.time()-5-.1)
    round_two=a.state(token)
    assert round_two['state']=='results' and round_two['correctChoices']==[0,2]
    assert round_two['isCorrect'] is True and round_two['points']>=500
    assert b.state(token)['isCorrect'] is True and b.state(token)['points']>=500
    multi_report=admin.call('/admin/run/'+str(run_id))[0]
    assert multi_report.count('class="report-option is-correct"')==4
    assert round_two['answerStats']==[1,0,1] and round_two['unansweredCount']==0
    ala=next(row for row in round_two['ranking'] if row['name']=='Ala <script>')
    bartek=next(row for row in round_two['ranking'] if row['name']=='Bartek')
    assert ala['score']==first_points+ala['roundPoints']
    assert bartek['score']==bartek['roundPoints']>0

    set_start(time.time()-5-15-.1)
    # A stale host request commits the automatic transition instead of undoing it.
    action('next',1,409)
    assert data.find('runs',id=run_id)['question_index']==2
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
    assert len(data.rows('answers'))==4
    admin.call('/admin/history')
    admin.call('/admin/run/'+str(run_id))
    # The host can skip the results delay, including the final wait before the podium.
    _,h=admin.call(quiz_path+'/start',{},303)
    skip_id=int(admin.route(h['Location']).rsplit('/',1)[1])
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
        data.patch('runs',skip_id,started_at=time.time()-.1)
        admin.call(skip_endpoint+'/host',{'action':'next','index':index},409)
        data.patch('runs',skip_id,started_at=time.time()-5-.1)
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
    idle_id=int(admin.route(h['Location']).rsplit('/',1)[1])
    idle_html=admin.call(h['Location'])[0]
    idle_token=re.search(r'data-token="([a-f0-9]+)"',idle_html).group(1)
    idle_state=admin.state(idle_token,True)
    admin.call('/api/game/'+idle_token+'/host',{'action':'next','index':-1},422)
    a.call('/ng',{'code':idle_state['code'],'name':'Ala'},303)
    admin.call('/api/game/'+idle_token+'/host',{'action':'next','index':-1})
    data.patch('runs',idle_id,started_at=time.time()-1000)
    caught_up=a.state(idle_token)
    assert caught_up['state']=='finished' and caught_up['index']==2
    assert caught_up['answerStats']==[0,0,0,0]

    # Editing/deleting a quiz does not mutate a run snapshot or historical images.
    before=data.find('runs',id=run_id)['snapshot']
    admin.call(quiz_path+'/save',{'title':'Nowa nazwa','description':''},303)
    admin.call(quiz_path+'/delete',{},303)
    assert data.find('runs',id=run_id)['snapshot']==before
    assert data.find('runs',id=run_id)['quiz_id'] is None
    admin.call('/admin/run/'+str(run_id))
    assert a.state(token)['title']=='Wiedza <test>'
    # A fresh run has an independent ranking; abandoned codes expire without cron.
    _,h=admin.call('/admin/quiz/create',{'title':'Druga gra'},303)
    second=admin.route(h['Location']); admin.call(second+'/question/save',q,303)
    _,h=admin.call(second+'/start',{},303)
    html=admin.call(h['Location'])[0]; token2=re.search(r'data-token="([a-f0-9]+)"',html).group(1)
    state2=admin.state(token2,True)
    assert state2['ranking']==[]
    a.call('/ng',{'code':state2['code'],'name':'Ala'},303)
    b.call('/ng',{'code':state2['code'],'name':'Bartek'},303)
    admin.call('/api/game/'+token2+'/host',{'action':'next','index':-1})
    assert a.state(token2)['state']=='countdown'
    data.patch('runs',data.find('runs',token=token2)['id'],started_at=time.time()-.1)
    a.call('/api/game/'+token2+'/answer',{'index':0,'choice':0})
    time.sleep(.3)
    b.call('/api/game/'+token2+'/answer',{'index':0,'choice':0})
    score_run=data.find('runs',token=token2)['id']
    names={p['id']:p['name'] for p in data.rows('players')}
    scores=sorted([(names[a['player_id']],a['points']) for a in data.rows('answers') if a['run_id']==score_run],key=lambda r:-r[1])
    assert scores[0][0]=='Ala' and scores[0][1]>scores[1][1]>=500
    data.patch('runs',data.find('runs',token=token2)['id'],created_at=int(time.time())-43201)
    assert admin.state(token2,True)['state']=='finished'
    outsider.call('/storage/quiz.json.php',expected=404)
    outsider.call('/app/bootstrap.php',expected=404)
    outsider.call('/.git/config',expected=404)
    outsider.call('/api/nope',expected=404)
    admin.call('/admin/logout',{},303)
    admin.call('/api/game/'+token+'?host=1',expected=401)
    login=Browser(base);login.call('/admin/login')
    login.call('/admin/login',{'username':'host','password':'wrong'},401)
    login.call('/admin/login',{'username':'host','password':'test password 12345'},303)
    assert 'Twoje quizy' in login.call('/admin')[0]
    print('PASS: setup/login, auth/CSRF, quiz CRUD/order, 2/3/4 answers, image uploads,')
    print('      join/names/reconnect, live timing, hidden correctness, duplicate/late answers,')
    print('      concurrent writes, scoring/ranking, snapshots/history, expiry, data isolation,')
    print('      10s default, hidden 5s countdown, 15s auto-advance + admin skip, per-round points, vote stats,')
    print('      stale host requests and automatic catch-up after missed phases.')
    print('      multiple correct options, checkbox validation, legacy quiz/run compatibility, immutable snapshots.')
    print('      QR vendor/assets, general QR path, physical join URL + code prefill, malformed query rejection.')

def main():
    php=os.environ.get('PHP_BIN','php')
    php_args=shlex.split(os.environ.get('PHP_ARGS',''))
    for prefix,pretty in [('',True),('/~test/iskra/public',True),('',False),('/~test/iskra/public',False)]:
        os.environ['QUIZ_PRETTY_URLS']='1' if pretty else '0'
        with tempfile.TemporaryDirectory(prefix='iskra-test-') as temporary:
            dbfile=str(Path(temporary)/'quiz.json.php')
            with socket.socket() as sock:
                sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
            origin='http://127.0.0.1:'+str(port)
            base=origin+prefix
            env=dict(os.environ,QUIZ_DATA=dbfile,PHP_CLI_SERVER_WORKERS='4',QUIZ_TEST_PREFIX=prefix)
            log=Path(temporary)/'php.log'
            with log.open('w') as stream:
                process=subprocess.Popen([php,*php_args,'-d','session.save_path='+temporary,
                    '-S','127.0.0.1:'+str(port),'-t','public','tests/subdirectory-router.php'],
                    cwd=ROOT,env=env,stdout=stream,stderr=stream,start_new_session=True)
                try:
                    for _ in range(60):
                        try:
                            urllib.request.urlopen(base+'/index.php',timeout=1).close();break
                        except (urllib.error.URLError,TimeoutError):
                            if process.poll() is not None: raise RuntimeError(log.read_text())
                            time.sleep(.1)
                    else: raise RuntimeError('PHP server did not start')
                    print('Mount:',prefix or '/','pretty' if pretty else 'without rewrite')
                    run_tests(base,dbfile)
                except Exception:
                    print(log.read_text()[-3000:]);raise
                finally:
                    if os.name!='nt':
                        import signal
                        os.killpg(process.pid,signal.SIGTERM)
                    else: process.terminate()
                    process.wait(timeout=10)
                    if Path(dbfile).exists():
                        blobs=Path(dbfile).read_text()
                        for filename in set(re.findall(r'/uploads/([a-f0-9]{32}\.[a-z]+)',blobs.replace(chr(92)+'/','/'))):
                            (ROOT/'public'/'uploads'/filename).unlink(missing_ok=True)

if __name__=='__main__': main()
