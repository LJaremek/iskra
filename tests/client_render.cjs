'use strict';
// Browser-client regression with the real app.js; Node is needed only for this test.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

(async () => {
    const root = process.env.ISKRA_ROOT || path.resolve(__dirname, '..');
    const source = fs.readFileSync(process.env.ISKRA_CLIENT_SOURCE || path.join(root, 'public/assets/app.js'), 'utf8');
    let poll, html = '', writes = 0, counter = null, answerNodes;
    let response = {
        state: 'question', index: 0, total: 2, title: 'Test', code: '123',
        me: {id: 1, name: 'Observer'}, players: [1, 2, 3, 4].map(id => ({id, name: 'Player ' + id})),
        question: {text: 'First question', image: null, duration: 180,
            options: ['A', 'B', 'C', 'D'].map(text => ({text, image: null}))},
        answered: null, answeredCount: 0, correct: null, correctChoices: null,
        points: null, isCorrect: null, answerStats: null, unansweredCount: null,
        serverTime: 0, deadline: 180
    };
    const game = {
        dataset: {host: '0', id: '1', token: 'test'},
        get innerHTML() { return html; },
        set innerHTML(value) {
            html = value; writes++;
            const match = value.match(/class="game-counter">([^<]*)</);
            counter = match ? {textContent: match[1]} : null;
            answerNodes = {}; // A full replacement creates new answer elements.
        },
        querySelector: selector => selector === '.game-counter' ? counter : null,
        querySelectorAll: () => [],
        setAttribute() {}, addEventListener() {}
    };
    const connection = {textContent: ''};
    const document = {
        querySelector: selector => ({
            'meta[name="csrf-token"]': {content: 'test'},
            'meta[name="app-base"]': {content: '/~test/iskra/public'},
            'meta[name="route-mode"]': {content: 'query'},
            '#game': game, '#connection': connection
        })[selector] || null,
        querySelectorAll: () => [],
        addEventListener() {}
    };
    const context = {
        document, location: {origin: 'http://localhost', host: 'localhost'},
        performance: {now: () => 0}, URL, URLSearchParams, AbortController,
        setTimeout(fn, delay) { if (delay !== 8000) poll = fn; return 1; },
        clearTimeout() {}, setInterval() {},
        fetch: async () => ({ok: true, json: async () => JSON.parse(JSON.stringify(response))})
    };
    vm.runInNewContext(source, context);
    await new Promise(setImmediate);
    assert.equal(writes, 1);
    const originalAnswers = answerNodes;
    const originalCounter = counter;

    // Independent peer votes update the count while preserving existing buttons.
    for (const count of [1, 2]) {
        response = {...response, answeredCount: count};
        await poll();
        assert.equal(writes, 1, 'A peer vote must not rebuild the question');
        assert.equal(answerNodes, originalAnswers);
        assert.equal(counter, originalCounter);
        assert.equal(counter.textContent, 'Odpowiedzi: ' + count + ' / 4');
    }
    // Our own accepted selection still appears and stays selected on later peer votes.
    response = {...response, answered: 2, answeredCount: 3};
    await poll();
    assert.equal(writes, 2);
    assert.ok(html.includes('answer-color-2 selected'));
    const selectedAnswers = answerNodes;
    response = {...response, answeredCount: 4};
    await poll();
    assert.equal(writes, 2);
    assert.equal(answerNodes, selectedAnswers);
    assert.equal(counter.textContent, 'Odpowiedzi: 4 / 4');

    // Changing the question clears the previous selection; results still reveal answers.
    response = {...response, index: 1, answered: null, answeredCount: 0,
        question: {...response.question, text: 'Second question'}};
    await poll();
    assert.equal(writes, 3);
    assert.ok(!html.includes(' selected'));
    response = {...response, state: 'results', answeredCount: 4, answerStats: [2, 0, 2, 0],
        unansweredCount: 0, correct: 0, correctChoices: [0, 2],
        ranking: [{id: 1, name: 'Observer', rank: 1, score: 900, roundPoints: 0}]};
    await poll();
    assert.equal(writes, 4);
    assert.equal((html.match(/ revealed-correct/g) || []).length, 2);
    assert.ok(html.includes('Ranking po rundzie'));
    response = {...response, state: 'finished', deadline: null};
    await poll();
    assert.equal(writes, 5);
    assert.ok(html.includes('Podium'));
    assert.equal(connection.textContent, '');
    console.log('PASS: peer votes preserve answer elements; count updates, own selection, next question, results and podium work.');
})().catch(error => { console.error(error); process.exitCode = 1; });
