'use strict';
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const appBase = document.querySelector('meta[name="app-base"]')?.content || '';
const appUrl = path => appBase + path;
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
document.querySelectorAll('[data-toggle]').forEach(button => button.addEventListener('click', () => {
    const target = document.getElementById(button.dataset.toggle);
    target.hidden = !target.hidden;
    if (!target.hidden) target.querySelector('input:not([type=hidden])')?.focus();
}));
document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (!confirm(form.dataset.confirm)) event.preventDefault();
}));
document.querySelector('[data-back]')?.addEventListener('click', () => {
    if (history.length > 1) history.back(); else location.href = appUrl('/ng');
});
const codeInput = document.querySelector('#code');
codeInput?.addEventListener('input', () => { codeInput.value = codeInput.value.replace(/\D/g, '').slice(0, 3); });
document.querySelectorAll('[data-image-input]').forEach(input => input.addEventListener('change', () => {
    const image = input.parentElement.querySelector('.image-preview');
    const file = input.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
        alert('Zdjęcie może mieć maksymalnie 5 MB.'); input.value = ''; return;
    }
    if (image.dataset.blob) URL.revokeObjectURL(image.dataset.blob);
    image.src = image.dataset.blob = URL.createObjectURL(file); image.hidden = false;
}));
const countSelect = document.querySelector('#count');
function updateOptions() {
    if (!countSelect) return;
    const count = Number(countSelect.value);
    document.querySelectorAll('[data-option]').forEach(field => {
        const hide = Number(field.dataset.option) >= count;
        field.hidden = hide; field.disabled = hide;
        if (hide) field.querySelector('[type=radio]').checked = false;
    });
    if (!document.querySelector('[name=correct]:checked')) document.querySelector('[name=correct]').checked = true;
}
countSelect?.addEventListener('change', updateOptions);
updateOptions();

// Cała synchronizacja gry to jedno małe zapytanie co ok. sekundę.
const game = document.querySelector('#game');
if (game) {
    const host = game.dataset.host === '1';
    const endpoint = appUrl('/api/game/') + game.dataset.token;
    const connection = document.querySelector('#connection');
    let state = null, signature = '', pending = false, localChoice = null;
    let baseServer = 0, baseClock = performance.now(), polling = false, stopped = false;
    let refreshTimer = null;
    const esc = escapeHtml;
    const image = (src, alt, cls = '') => src ? '<img class="' + cls + '" src="' + esc(src) + '" alt="' + esc(alt) + '">' : '';

    async function request(url, values = null) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch(url, {
                method: values ? 'POST' : 'GET', signal: controller.signal,
                headers: values ? {'X-CSRF-Token': csrf, 'Content-Type':'application/x-www-form-urlencoded'} : {},
                body: values ? new URLSearchParams(values) : null,
                credentials: 'same-origin', cache:'no-store'
            });
            const data = await response.json();
            if (!response.ok) {
                const error = new Error(data.error || 'Nie udało się pobrać danych.');
                error.status = response.status;
                throw error;
            }
            return data;
        } finally { clearTimeout(timeout); }
    }

    const header = data => '<div class="game-top"><div><span class="eyebrow">' +
        (data.state === 'lobby' ? 'ZA CHWILĘ ZACZYNAMY' : data.state === 'finished' ? 'TO BYŁA DOBRA GRA' : 'PYTANIE ' + (data.index + 1) + ' / ' + data.total) +
        '</span><h1>' + esc(data.title) + '</h1></div><div class="game-tools"><span class="tag">' +
        (host ? 'PROWADZĄCY · ' + esc(data.code) : esc(data.me.name)) + '</span>' +
        (host && data.state !== 'finished' ? '<button class="text-button danger" data-action="finish">Zakończ grę</button>' : '') + '</div></div>';

    function ranking(data) {
        const rows = data.ranking || [];
        const round = data.state === 'results';
        return rows.length ? '<div class="table-wrap"><table class="ranking"><thead><tr><th>Miejsce</th><th>Gracz</th><th>' +
            (round ? 'Za rundę (łącznie)' : 'Punkty') + '</th></tr></thead><tbody>' +
            rows.map(row => '<tr class="' + (data.me && Number(row.id) === data.me.id ? 'mine' : '') + '"><td><span class="rank-number ' + (row.rank === 1 ? 'first' : '') + '">' + row.rank +
                '</span></td><td><strong>' + esc(row.name) + '</strong></td><td>' +
                (round ? '<strong class="round-gain">+' + row.roundPoints + '</strong> <span class="score-total">(' + row.score + ')</span>' : '<strong>' + row.score + '</strong>') +
                '</td></tr>').join('') + '</tbody></table></div>' : '<p class="muted small">Czekamy na pierwszych graczy.</p>';
    }

    function lobby(data) {
        return '<div class="lobby"><div class="panel lobby-code"><span class="eyebrow">DOŁĄCZ NA ' + esc(location.host + appUrl('/ng')) + '</span><h2>Kod do dobrej zabawy</h2><span class="big-code">' +
            esc(data.code) + '</span><p>Wpisz kod gry i swoją nazwę.</p>' +
            (host ? '<button class="text-button" data-copy>Skopiuj adres dołączania ↗</button><br><button class="button primary" data-action="next" ' + (!data.players.length ? 'disabled' : '') + '>Zaczynamy →</button>' :
                '<p class="waiting-note"><span class="live-dot"></span>Jesteś w grze. Czekamy na prowadzącego.</p>') +
            '</div><div class="panel lobby-players"><h2>Ekipa w komplecie?<span class="count">' + data.players.length + ' / 200</span></h2>' +
            (data.players.length ? '<div class="player-cloud">' + data.players.map(p => '<span class="player-chip ' + (data.me && Number(p.id) === data.me.id ? 'mine' : '') + '">' + esc(p.name) + '</span>').join('') + '</div>' :
                '<p class="muted">Pierwsi gracze zaraz tu wskoczą. Udostępnij im kod i adres strony.</p>') + '</div></div>';
    }

    function countdown(data) {
        return '<section class="panel countdown-stage"><span class="eyebrow">PYTANIE ' + (data.index + 1) + ' Z ' + data.total +
            '</span><h2>Gotowi?</h2><div class="countdown-clock" id="timer" role="timer" aria-label="Sekundy do pytania">5</div>' +
            '<p>Za chwilę pojawi się pytanie.<br>Przygotuj się do odpowiedzi.</p></section>';
    }

    function answers(data, reveal) {
        const choice = data.answered ?? localChoice;
        return '<div class="answer-grid">' + data.question.options.map((option, i) => {
            const interactive = !host && !reveal;
            const tag = interactive ? 'button' : 'div';
            const selected = choice === i;
            const correct = reveal && data.correct === i;
            const votes = reveal ? data.answerStats[i] : 0;
            const percent = data.players.length ? Math.round(votes * 100 / data.players.length) : 0;
            return '<' + tag + ' class="answer answer-color-' + i + (selected ? ' selected' : '') +
                (correct ? ' revealed-correct' : reveal ? ' revealed-wrong' : '') + '"' +
                (interactive ? ' data-choice="' + i + '" ' + (choice !== null || pending ? 'disabled' : '') +
                    ' aria-label="Odpowiedź ' + String.fromCharCode(65+i) + ': ' + esc(option.text || 'zdjęcie') + '"' : '') +
                '><span class="answer-letter">' + String.fromCharCode(65+i) + '</span><div class="answer-content">' +
                (option.text ? '<span>' + esc(option.text) + '</span>' : '') + image(option.image, 'Odpowiedź ' + String.fromCharCode(65+i)) +
                (reveal ? '<div class="vote-stat"><div class="vote-label"><strong>Głosy: ' + votes + '</strong><span>' + percent + '%</span></div>' +
                    '<progress value="' + votes + '" max="' + Math.max(1, data.players.length) + '" aria-label="Liczba głosów na odpowiedź ' + String.fromCharCode(65+i) + '"></progress>' +
                    (correct ? '<span class="vote-correct">✓ Poprawna odpowiedź</span>' : '') + '</div>' : '') +
                '</div></' + tag + '>';
        }).join('') + '</div>';
    }

    function question(data) {
        const reveal = data.state === 'results';
        const choice = data.answered ?? localChoice;
        return '<div class="question-stage">' +
            (!reveal ? '<div class="timer-line"><progress id="time-progress" max="' + data.question.duration + '" value="' + data.question.duration +
                '" aria-label="Pozostały czas"></progress><span class="timer-label" id="timer" role="timer"></span></div>' :
                '<span class="tag">CZAS MINĄŁ · SPRAWDŹMY WYNIKI</span>') +
            '<h2>' + esc(data.question.text || 'Spójrz na zdjęcie. Która odpowiedź jest poprawna?') + '</h2>' +
            image(data.question.image, data.question.text || 'Zdjęcie pytania', 'question-image') +
            (reveal ? '<div class="results-caption"><h3>Jak głosowaliście?</h3><p>Głosy: ' + data.answeredCount + ' / ' + data.players.length +
                ' · Bez odpowiedzi: ' + data.unansweredCount + '</p></div>' :
                '<p class="game-counter">Odpowiedzi: ' + data.answeredCount + ' / ' + data.players.length + '</p>') +
            answers(data, reveal) +
            (!host ? '<div class="answer-receipt" role="status">' + (reveal ?
                (data.answered === null ? 'Tym razem bez odpowiedzi.' : data.isCorrect ? 'Dobra odpowiedź! <strong>+' + data.points + ' pkt</strong>' : 'Tym razem się nie udało.') :
                choice !== null ? '✓ Odpowiedź zapisana. Poczekaj do końca czasu.' : 'Wybierz jedną odpowiedź. Szybciej = więcej punktów.') + '</div>' : '') +
            '</div>' + (reveal ? '<p class="next-round-note"><span class="live-dot"></span>' +
                (data.index + 1 >= data.total ? 'Ranking końcowy za ' : 'Kolejne pytanie za ') +
                '<strong id="timer" role="timer"></strong></p>' +
                (host ? '<div class="game-footer"><button class="button primary" data-action="next"' + (pending ? ' disabled' : '') + '>' +
                    (data.index + 1 >= data.total ? 'Pokaż podium teraz' : 'Następne pytanie') + ' →</button></div>' : '') +
                '<div class="panel round-ranking"><h2>Ranking po rundzie</h2>' + ranking(data) + '</div>' : '');
    }

    function podium(data) {
        const top = data.ranking.slice(0, 3);
        if (!top.length) return '';
        return '<section class="podium-section" aria-label="Podium — pierwsze trzy miejsca"><ol class="podium">' + top.map(row =>
            '<li class="podium-place podium-place-' + row.rank + '"><div class="podium-person"><span class="podium-medal" aria-hidden="true">' +
            row.rank + '</span><h3>' + esc(row.name) + '</h3><p>' + row.score + ' pkt</p></div><div class="podium-step"><span>MIEJSCE ' +
            row.rank + '</span></div></li>').join('') + '</ol></section>';
    }

    function finished(data) {
        const me = data.me && data.ranking.find(p => Number(p.id) === data.me.id);
        return '<div class="end-title"><span class="eyebrow">KONIEC ROZGRYWKI</span><h2>To była dobra runda<span class="accent">.</span></h2><p>Dzięki za wspólną grę. Oto Wasze wyniki.</p></div>' +
            podium(data) +
            (me ? '<p class="my-result">Twoje miejsce: <strong>' + me.rank + '</strong> · Punkty: <strong>' + me.score + '</strong> · Poprawne: ' + me.correct + ' / ' + data.total + '</p>' : '') +
            '<div class="panel round-ranking"><h2>Ranking końcowy — wszyscy gracze</h2>' + ranking(data) + '</div><div class="finish-link">' +
            (host ? '<a class="button secondary" href="' + esc(appUrl('/admin/run/')) + game.dataset.id + '">Szczegóły rozgrywki</a><a class="button primary" href="' + esc(appUrl('/admin')) + '">Twoje quizy →</a>' :
                '<a class="button primary" href="' + esc(appUrl('/ng')) + '">Dołącz do kolejnej gry →</a>') + '</div>';
    }

    function render(data, force = false) {
        const key = JSON.stringify([data.state, data.index, data.answered, data.correct, data.points, data.players,
            data.ranking, data.answerStats, data.unansweredCount, data.answeredCount, localChoice, pending]);
        if (key === signature && !force) return;
        signature = key;
        let content;
        if (data.state === 'lobby') content = lobby(data);
        else if (data.state === 'countdown') content = countdown(data);
        else if (data.state === 'finished') content = finished(data);
        else content = question(data);
        game.innerHTML = header(data) + content;
        game.setAttribute('aria-busy', 'false');
        updateTimer();
    }

    function secondsLeft() {
        return Math.max(0, state.deadline - (baseServer + (performance.now() - baseClock) / 1000));
    }

    function updateTimer() {
        if (!state || state.deadline === null) return;
        const left = secondsLeft();
        const label = document.querySelector('#timer'), progress = document.querySelector('#time-progress');
        if (label) label.textContent = Math.ceil(left) + (state.state === 'countdown' ? '' : ' s');
        if (progress) progress.value = left;
        if (state.state === 'question' && left <= 0)
            game.querySelectorAll('[data-choice]').forEach(button => { button.disabled = true; });
    }

    async function refresh() {
        if (polling || stopped) return;
        clearTimeout(refreshTimer);
        polling = true;
        const start = performance.now();
        let loaded = false;
        try {
            const data = await request(endpoint + (host ? '?host=1' : ''));
            baseServer = data.serverTime + (performance.now() - start) / 2000;
            baseClock = performance.now();
            if (!state || data.index !== state.index) localChoice = null;
            state = data;
            connection.textContent = '';
            render(data);
            loaded = true;
            if (data.state === 'finished') stopped = true;
        } catch (error) {
            connection.textContent = error.name === 'AbortError' ? 'Połączenie trwa dłużej. Ponawiamy…' : error.message;
            if ([401,403,404].includes(error.status)) {
                stopped = true;
                game.innerHTML = '<div class="panel loading"><h2>Wróćmy do gry.</h2><p>' + esc(error.message) + '</p><a class="button primary" href="' +
                    esc(appUrl(host ? '/admin/login' : '/ng')) + '">Przejdź →</a></div>';
            }
        } finally {
            polling = false;
            // Przy granicy fazy odświeżamy dokładniej, bez dokładania sekundy przerwy.
            const delay = loaded && state.deadline !== null ? Math.min(1000, Math.max(80, secondsLeft() * 1000 + 30)) : 1000;
            if (!stopped) refreshTimer = setTimeout(refresh, delay);
        }
    }

    game.addEventListener('click', async event => {
        const copy = event.target.closest('[data-copy]');
        if (copy) {
            try { await navigator.clipboard.writeText(location.origin + appUrl('/ng')); copy.textContent = 'Skopiowano ✓'; }
            catch { connection.textContent = 'Adres dołączania: ' + location.origin + appUrl('/ng'); }
            return;
        }
        const button = event.target.closest('[data-action], [data-choice]');
        if (!button || button.disabled || pending || !state) return;
        if (button.dataset.action === 'finish' && !confirm('Zakończyć tę rozgrywkę i pokazać ranking?')) return;
        const isAnswer = button.hasAttribute('data-choice');
        const index = state.index;
        pending = true;
        if (isAnswer) localChoice = Number(button.dataset.choice);
        render(state, true);
        try {
            await request(endpoint + (isAnswer ? '/answer' : '/host'), isAnswer ?
                {index, choice: button.dataset.choice} : {index, action: button.dataset.action});
            connection.textContent = '';
        } catch (error) {
            if (isAnswer) localChoice = null;
            connection.textContent = error.name === 'AbortError' ? 'Nie otrzymaliśmy potwierdzenia. Sprawdzamy zapis odpowiedzi…' : error.message;
        } finally {
            pending = false;
            signature = '';
            render(state, true);
            await refresh();
        }
    });
    refresh();
    setInterval(updateTimer, 100);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}
