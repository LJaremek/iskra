<?php
declare(strict_types=1);
function hidden_csrf(): void { echo '<input type="hidden" name="_csrf" value="'.e($_SESSION['csrf']).'">'; }
function badge(string $state): string {
    return ['lobby'=>'Poczekalnia','question'=>'W trakcie','results'=>'W trakcie','finished'=>'Zakończona'][$state] ?? $state;
}
function empty_state(string $title,string $description): void { ?>
<div class="empty"><span class="empty-mark" aria-hidden="true">✳</span><h2><?=e($title)?></h2><p><?=e($description)?></p></div>
<?php }
function page(string $title,string $view,array $data=[]): void {
    extract($data,EXTR_SKIP); $isAdmin=!empty($_SESSION['admin']); $isLive=$view==='live';
    $notice=$_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="app-base" content="<?=e(base_path())?>">
<meta name="route-mode" content="<?=pretty_urls()?'pretty':'query'?>">
<meta name="csrf-token" content="<?=e($_SESSION['csrf'])?>">
<meta name="description" content="Iskra — proste quizy na żywo. Dołącz kodem, graj i sprawdź swój wynik.">
<title><?=e($title)?> · Iskra</title>
<link rel="icon" href="<?=e(base_path())?>/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?=e(base_path())?>/assets/style.css?v=<?=filemtime(dirname(__DIR__).'/public/assets/style.css')?>">
<script src="<?=e(base_path())?>/assets/app.js?v=<?=filemtime(dirname(__DIR__).'/public/assets/app.js')?>" defer></script>
</head>
<body class="<?=e($view)?>">
<header class="site-header"><a href="<?=e(url(($isAdmin?'/admin':'/ng')))?>" class="brand" aria-label="Iskra — strona główna"><span class="brand-icon" aria-hidden="true">✳</span> iskra<span class="brand-dot">.</span></a>
<nav aria-label="Nawigacja">
<?php if ($isAdmin): ?><a href="<?=e(url('/admin'))?>" class="<?=$view==='dashboard'||$view==='editor'||$view==='question'?'active':''?>">Quizy</a><a href="<?=e(url('/admin/history'))?>" class="<?=$view==='history'||$view==='report'?'active':''?>">Historia</a><a href="<?=e(url('/ng'))?>">Dołącz do gry ↗</a><form action="<?=e(url('/admin/logout'))?>" method="post"><?php hidden_csrf(); ?><button class="nav-button">Wyloguj</button></form>
<?php else: ?><a href="<?=e(url('/ng'))?>" class="active">Dołącz do gry</a><a href="<?=e(url('/admin'))?>" class="nav-admin">Panel prowadzącego <span aria-hidden="true">↗</span></a><?php endif; ?>
</nav></header>
<main class="<?=$view==='join'?'join-main':($isLive?'live-main':'container')?>">
<?php if ($notice): ?><div class="notice" role="status"><?=e($notice)?></div><?php endif; ?>
<?php if ($view==='join'): ?>
<div class="join-layout"><section class="join-copy"><div class="eyebrow"><span class="live-dot"></span> MAŁY KOD. WIELKA GRA.</div>
<h1>Odrobina wiedzy.<br>Mnóstwo <span>emocji.</span></h1>
<p>Jeden quiz, wspólna zabawa. Złap swój telefon,<br class="desktop-only"> wpisz kod i pokaż, co wiesz.</p>
<div class="join-note"><span class="note-icon" aria-hidden="true">↗</span><div><strong>Wchodzisz i grasz.</strong><span>Bez konta. Bez instalowania. Po prostu.</span></div></div>
<div class="decor" aria-hidden="true"><div class="decor-card decor-a"><span>A</span> Mam to!</div><div class="decor-star">✳</div><div class="decor-card decor-b"><span>+1000</span> punktów</div><div class="decor-line"></div></div>
</section>
<section class="join-card"><div class="card-top"><span class="tag">TWOJA NASTĘPNA RUNDA</span><span aria-hidden="true">↗</span></div>
<h2>Wskakuj do gry</h2><p>Kod dostaniesz od prowadzącego.</p>
<form method="post" action="<?=e(url('/ng'))?>" class="stack"><?php hidden_csrf(); ?>
<label for="code">Kod gry <span>3 cyfry</span></label>
<input class="code-input" id="code" name="code" inputmode="numeric" pattern="[1-9][0-9]{2}" maxlength="3" placeholder="000" required autocomplete="off">
<label for="name">Jak się nazywasz?</label><input id="name" name="name" maxlength="24" placeholder="Twoja nazwa w rankingu" required autocomplete="nickname">
<button class="button primary join-button">Dołącz do gry <span aria-hidden="true">→</span></button>
</form><div class="card-bottom"><span class="live-dot"></span> Gotowi? Niech wygra najlepszy.</div></section></div>
<div class="join-footer"><span>Trochę rywalizacji dobrze robi.</span><span>100% w przeglądarce <span aria-hidden="true">✳</span></span></div>
<?php elseif ($view==='auth'): ?>
<div class="auth-card panel"><a class="back" href="<?=e(url('/ng'))?>">← Dołącz do gry</a><span class="eyebrow">DLA PROWADZĄCEGO</span>
<h1><?=$setup?'Zaczynamy?':'Dobrze Cię widzieć.'?></h1><p class="muted"><?=$setup?'Utwórz konto administratora. Ten ekran jest dostępny tylko przed pierwszą konfiguracją.':'Zaloguj się i rozkręć kolejną rozgrywkę.'?></p>
<form method="post" class="stack"><?php hidden_csrf(); ?><label for="username">Login</label><input id="username" name="username" maxlength="40" autocomplete="username" required autofocus>
<label for="password">Hasło<?=$setup?' (minimum 12 znaków)':''?></label><input id="password" type="password" name="password" minlength="<?=$setup?12:1?>" maxlength="72" autocomplete="<?=$setup?'new-password':'current-password'?>" required>
<button class="button primary"><?=$setup?'Utwórz konto':'Zaloguj się'?> <span>→</span></button></form>
<?php if ($setup): ?><p class="small muted">Przeprowadź konfigurację przed publicznym udostępnieniem serwera.</p><?php endif; ?></div>
<?php elseif ($view==='dashboard'): ?>
<div class="page-heading"><div><span class="eyebrow">PANEL PROWADZĄCEGO</span><h1>Twoje quizy<span class="accent">.</span></h1><p class="muted">Dobre pytania to początek dobrej zabawy.</p></div><button class="button primary" data-toggle="new-quiz">+ Nowy quiz</button></div>
<section id="new-quiz" class="panel new-quiz" hidden><h2>Nowy quiz</h2><form method="post" action="<?=e(url('/admin/quiz/create'))?>" class="stack"><?php hidden_csrf(); ?><label for="title">Nazwa quizu</label><input id="title" name="title" maxlength="100" placeholder="Np. Piątkowy quiz zespołowy" required><label for="description">Krótki opis <span>opcjonalnie</span></label><input id="description" name="description" maxlength="300"><button class="button primary">Utwórz quiz →</button></form></section>
<?php if ($active): ?><section class="section-block"><h2 class="section-label"><span class="live-dot"></span> Aktywne rozgrywki <span class="count"><?=count($active)?></span></h2>
<?php foreach ($active as $r): ?><a class="active-run" href="<?=e(url('/admin/host/'.($r['id'])))?>"><div><strong><?=e($r['title'])?></strong><span class="muted"><?=e(badge($r['state']))?> · <?=$r['player_count']?> graczy</span></div><span class="mini-code"><?=e($r['code'])?></span><span aria-hidden="true">→</span></a><?php endforeach; ?></section><?php endif; ?>
<div class="section-heading"><h2 class="section-label">Biblioteka quizów <span class="count"><?=count($quizzes)?></span></h2></div>
<?php if (!$quizzes) empty_state('Pierwszy quiz czeka na Ciebie','Kliknij „Nowy quiz”, dodaj pytania i zaproś graczy.'); ?>
<div class="quiz-grid"><?php foreach ($quizzes as $i=>$q): ?><article class="quiz-card"><div class="quiz-card-top"><span class="quiz-number"><?=str_pad((string)($i+1),2,'0',STR_PAD_LEFT)?></span><span class="tag">Pytania: <?=$q['question_count']?></span></div><h2><a href="<?=e(url('/admin/quiz/'.($q['id'])))?>"><?=e($q['title'])?></a></h2><p class="muted"><?=e($q['description'] ?: 'Gotowy na odrobinę rywalizacji?')?></p><div class="quiz-meta"><a href="<?=e(url('/admin/history?quiz='.($q['id'])))?>">Rozgrywki: <?=$q['run_count']?> ↗</a><span><?=date('d.m.Y',$q['created_at'])?></span></div><div class="quiz-actions"><a class="button secondary" href="<?=e(url('/admin/quiz/'.($q['id'])))?>">Edytuj</a><form method="post" action="<?=e(url('/admin/quiz/'.($q['id']).'/start'))?>"><?php hidden_csrf(); ?><button class="button primary" <?=$q['question_count']?'':'disabled'?>>Graj →</button></form></div></article><?php endforeach; ?></div>
<?php elseif ($view==='editor'): ?>
<a class="back" href="<?=e(url('/admin'))?>">← Twoje quizy</a><div class="page-heading"><div><span class="eyebrow">EDYTOR QUIZU</span><h1><?=e($quiz['title'])?></h1><p class="muted">Pytania: <?=count($questions)?> · <?=array_sum(array_column($questions,'duration'))?> sekund odpowiedzi</p></div><form action="<?=e(url('/admin/quiz/'.($quiz['id']).'/start'))?>" method="post"><?php hidden_csrf(); ?><button class="button primary" <?=$questions?'':'disabled'?>>Rozpocznij grę →</button></form></div>
<details class="panel settings"><summary>Nazwa i opis quizu <span>Edytuj ↗</span></summary><form class="stack" action="<?=e(url('/admin/quiz/'.($quiz['id']).'/save'))?>" method="post"><?php hidden_csrf(); ?><label for="title">Nazwa</label><input id="title" name="title" maxlength="100" value="<?=e($quiz['title'])?>" required><label for="description">Opis</label><textarea id="description" name="description" maxlength="300"><?=e($quiz['description'])?></textarea><button class="button secondary">Zapisz zmiany</button></form></details>
<div class="section-heading"><h2>Pytania <span class="count"><?=count($questions)?></span></h2><a class="button secondary" href="<?=e(url('/admin/quiz/'.($quiz['id']).'/question/new'))?>">+ Dodaj pytanie</a></div>
<?php if (!$questions) empty_state('Wszystko zaczyna się od pytania','Dodaj 2, 3 lub 4 odpowiedzi i zaznacz jedną poprawną.'); ?>
<div class="question-list"><?php foreach ($questions as $i=>$q): ?><article class="question-row"><span class="row-number"><?=str_pad((string)($i+1),2,'0',STR_PAD_LEFT)?></span><?php if ($q['image']): ?><img class="thumb" src="<?=e(asset_url($q['image']))?>" alt="Zdjęcie pytania"><?php endif; ?><div class="question-info"><h3><a href="<?=e(url('/admin/quiz/'.($quiz['id']).'/question/'.($q['id'])))?>"><?=e($q['text'] ?: 'Pytanie ze zdjęciem')?></a></h3><p class="muted"><?=count($q['options'])?> odpowiedzi · <?=$q['duration']?> s</p></div><div class="row-actions"><form method="post" action="<?=e(url('/admin/quiz/'.($quiz['id']).'/question/'.($q['id']).'/move'))?>"><?php hidden_csrf(); ?><button class="icon-button" name="direction" value="-1" aria-label="Przesuń pytanie w górę" <?=$i===0?'disabled':''?>>↑</button><button class="icon-button" name="direction" value="1" aria-label="Przesuń pytanie w dół" <?=$i===count($questions)-1?'disabled':''?>>↓</button></form><a class="button secondary small-button" href="<?=e(url('/admin/quiz/'.($quiz['id']).'/question/'.($q['id'])))?>">Edytuj</a><form method="post" data-confirm="Usunąć to pytanie?" action="<?=e(url('/admin/quiz/'.($quiz['id']).'/question/'.($q['id']).'/delete'))?>"><?php hidden_csrf(); ?><button class="icon-button danger" aria-label="Usuń pytanie">×</button></form></div></article><?php endforeach; ?></div>
<div class="editor-footer"><a href="<?=e(url('/admin/history?quiz='.($quiz['id'])))?>" class="text-link">Historia tego quizu ↗</a><form method="post" action="<?=e(url('/admin/quiz/'.($quiz['id']).'/delete'))?>" data-confirm="Usunąć quiz wraz z pytaniami? Historia rozegranych gier zostanie zachowana."><?php hidden_csrf(); ?><button class="text-button danger">Usuń quiz</button></form></div>
<?php elseif ($view==='question'):
$q=$question ?? ['id'=>0,'text'=>'','image'=>null,'duration'=>10,'correct'=>0,'options'=>[['text'=>'','image'=>null],['text'=>'','image'=>null]]]; ?>
<a class="back" href="<?=e(url('/admin/quiz/'.($quiz['id'])))?>">← <?=e($quiz['title'])?></a><div class="page-heading"><div><span class="eyebrow">EDYTOR PYTANIA</span><h1><?=$question?'Dopracuj pytanie.':'O co zapytasz?'?></h1><p class="muted">Krótko, jasno i z jedną poprawną odpowiedzią.</p></div></div>
<form method="post" enctype="multipart/form-data" action="<?=e(url('/admin/quiz/'.($quiz['id']).'/question/save'))?>" class="question-form"><?php hidden_csrf(); ?><input type="hidden" name="id" value="<?=$q['id']?>">
<div class="panel stack"><label for="text">Treść pytania <span>lub samo zdjęcie</span></label><textarea id="text" name="text" maxlength="500" rows="3" placeholder="Np. Która planeta jest najbliżej Słońca?"><?=e($q['text'])?></textarea>
<?php image_field('image',$q['image'],'Zdjęcie do pytania','remove_image'); ?>
<div class="form-columns"><div class="stack"><label for="duration">Czas na odpowiedź</label><div class="input-suffix"><input id="duration" type="number" name="duration" min="5" max="180" value="<?=$q['duration']?>" required><span>sekund</span></div></div><div class="stack"><label for="count">Liczba odpowiedzi</label><select id="count" name="count"><?php for ($i=2;$i<=4;$i++): ?><option value="<?=$i?>" <?=count($q['options'])===$i?'selected':''?>><?=$i?> odpowiedzi</option><?php endfor; ?></select></div></div></div>
<div class="section-heading"><div><h2>Odpowiedzi</h2><p class="muted small">Zaznacz kółkiem jedną poprawną odpowiedź. Możesz użyć tekstu, zdjęcia lub obu.</p></div></div>
<div class="answer-editor-grid"><?php for ($i=0;$i<4;$i++): $opt=$q['options'][$i] ?? ['text'=>'','image'=>null]; ?><fieldset class="answer-editor answer-color-<?=$i?>" data-option="<?=$i?>" <?=$i>=count($q['options'])?'hidden disabled':''?>><legend>Odpowiedź <?=chr(65+$i)?></legend><label class="correct-label"><input type="radio" name="correct" value="<?=$i?>" <?=$q['correct']===$i?'checked':''?> required> Poprawna odpowiedź</label><label class="sr-only" for="option_<?=$i?>">Treść odpowiedzi <?=chr(65+$i)?></label><input id="option_<?=$i?>" name="option_<?=$i?>" maxlength="200" value="<?=e($opt['text'])?>" placeholder="Wpisz odpowiedź"><?php image_field('option_image_'.$i,$opt['image'],'Zdjęcie odpowiedzi '.chr(65+$i),'remove_option_'.$i); ?></fieldset><?php endfor; ?></div>
<div class="form-footer"><a class="button secondary" href="<?=e(url('/admin/quiz/'.($quiz['id'])))?>">Anuluj</a><button class="button primary">Zapisz pytanie →</button></div></form>
<?php elseif ($view==='live'): ?>
<section id="game" data-id="<?=$run['id']?>" data-token="<?=e($run['token'])?>" data-host="<?=$host?'1':'0'?>" aria-busy="true"><div class="loading panel"><span class="brand-icon">✳</span><p>Łączymy się z grą…</p></div></section><p id="connection" class="connection" role="status"></p><noscript><div class="notice">Włącz JavaScript, aby brać udział w rozgrywce.</div></noscript>
<?php elseif ($view==='history'): ?>
<div class="page-heading"><div><span class="eyebrow">KAŻDA RUNDA SIĘ LICZY</span><h1>Historia rozgrywek<span class="accent">.</span></h1><p class="muted">Wyniki, do których możesz wrócić. Ostatnie 200 rozgrywek.</p></div><a class="button secondary" href="<?=e(url('/admin'))?>">← Twoje quizy</a></div>
<?php if (!$runs): empty_state('Tutaj pojawią się wyniki','Uruchom quiz i zaproś graczy do pierwszej rozgrywki.'); else: ?><div class="panel table-wrap"><table><thead><tr><th>Quiz</th><th>Data</th><th>Gracze</th><th>Status</th><th><span class="sr-only">Wyniki</span></th></tr></thead><tbody><?php foreach ($runs as $r): ?><tr><td><strong><?=e($r['title'])?></strong><span class="table-sub">Rozgrywka #<?=$r['id']?> · kod <?=e($r['code'])?></span></td><td><?=date('d.m.Y',$r['created_at'])?><span class="table-sub"><?=date('H:i',$r['created_at'])?></span></td><td><?=$r['player_count']?></td><td><span class="status <?=$r['state']==='finished'?'':'is-live'?>"><?=e(badge($r['state']))?></span></td><td><a class="text-link" href="<?=e(url('/admin/run/'.($r['id'])))?>">Wyniki →</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php elseif ($view==='report'): ?>
<a class="back" href="<?=e(url('/admin/history'))?>">← Historia rozgrywek</a><div class="page-heading"><div><span class="eyebrow">ROZGRYWKA #<?=$run['id']?> · <?=date('d.m.Y, H:i',$run['created_at'])?></span><h1><?=e($run['title'])?></h1><p class="muted"><?=count($ranking)?> graczy · Pytania: <?=count(snapshot($run))?> · <?=e(badge($run['state']))?></p></div><?php if ($run['state']!=='finished'): ?><a class="button primary" href="<?=e(url('/admin/host/'.($run['id'])))?>">Wróć do prowadzenia →</a><?php endif; ?></div>
<div class="panel"><h2>Ranking graczy</h2><?php ranking_table($ranking); ?></div>
<div class="section-heading"><h2>Pytanie po pytaniu</h2></div><div class="question-list"><?php foreach (snapshot($run) as $i=>$q):
$roundAnswers=run_answers($run['id'],$i); $stats=['total'=>count($roundAnswers),'correct'=>array_sum(array_column($roundAnswers,'correct'))]; ?>
<details class="panel report-question"><summary><span class="row-number"><?=$i+1?></span><strong><?=e($q['text'] ?: 'Pytanie ze zdjęciem')?></strong><span class="muted"><?=$stats['correct']?> / <?=$stats['total']?> poprawnych</span></summary>
<?php if ($q['image']): ?><img class="report-image" src="<?=e(asset_url($q['image']))?>" alt="Zdjęcie pytania"><?php endif; ?><div class="report-options"><?php foreach ($q['options'] as $j=>$option): ?><div class="report-option <?=$j===$q['correct']?'is-correct':''?>"><strong><?=chr(65+$j)?> <?=$j===$q['correct']?'✓':''?></strong> <?=e($option['text'])?><?php if ($option['image']): ?><img class="thumb" src="<?=e(asset_url($option['image']))?>" alt="Odpowiedź <?=chr(65+$j)?>"><?php endif; ?></div><?php endforeach; ?></div>
<?php $answers=question_answers($run['id'],$i); ?>
<?php if ($answers): ?><div class="table-wrap"><table><thead><tr><th>Gracz</th><th>Odpowiedź</th><th>Czas</th><th>Punkty</th></tr></thead><tbody><?php foreach ($answers as $a): ?><tr><td><?=e($a['name'])?></td><td><?=chr(65+$a['choice'])?> <?=$a['correct']?'✓':'×'?></td><td><?=number_format($a['elapsed'],2,',','')?> s</td><td><?=$a['points']?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="muted">Brak zapisanych odpowiedzi.</p><?php endif; ?></details><?php endforeach; ?></div>
<?php elseif ($view==='error'): ?>
<div class="auth-card panel"><span class="eyebrow">UPS · <?=e($status)?></span><h1>Mała przerwa.</h1><p><?=e($message)?></p><div class="form-footer"><a class="button primary" href="<?=e(url(back_path()))?>">← Wróć</a><a class="button secondary" href="<?=e(url(($isAdmin?'/admin':'/ng')))?>">Strona główna</a></div></div>
<?php endif; ?>
</main>
<?php if ($view!=='join'): ?><footer class="site-footer"><span>iskra. <span class="muted">Daj się wciągnąć.</span></span><span>Prosty quiz. Dobra zabawa.</span></footer><?php endif; ?>
</body></html>
<?php }
function image_field(string $name,?string $image,string $label,string $remove): void { ?>
<div class="upload-field"><label for="<?=e($name)?>"><?=e($label)?> <span>opcjonalnie · do 5 MB</span></label><input id="<?=e($name)?>" type="file" name="<?=e($name)?>" accept="image/jpeg,image/png,image/webp,image/gif" data-image-input>
<img class="image-preview" alt="<?=e($label)?>" <?=$image?'src="'.e(asset_url($image)).'"':'hidden'?>>
<?php if ($image): ?><label class="check-label"><input type="checkbox" name="<?=e($remove)?>" value="1"> Usuń obecne zdjęcie</label><?php endif; ?></div>
<?php }
function ranking_table(array $rows): void {
    if (!$rows) { echo '<p class="muted">Jeszcze nikt nie dołączył do gry.</p>'; return; } ?>
<div class="table-wrap"><table class="ranking"><thead><tr><th>Miejsce</th><th>Gracz</th><th>Poprawne</th><th>Punkty</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><span class="rank-number <?=$r['rank']===1?'first':''?>"><?=$r['rank']?></span></td><td><strong><?=e($r['name'])?></strong></td><td><?=$r['correct']?></td><td><strong><?=$r['score']?></strong></td></tr><?php endforeach; ?></tbody></table></div>
<?php }
