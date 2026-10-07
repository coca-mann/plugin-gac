'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const picker = require('../../public/js/sso-ou-picker.js');

const payload = {
    workspaces: [
        { key: 'principal', name: 'Principal', error: '', ous: [
            { path: '/Sistemas', label: 'Principal - /Sistemas', repeated: true },
            { path: '/fimca.com.br', label: 'Principal - /fimca.com.br', repeated: false },
        ] },
        { key: 'metropolitana', name: 'Metropolitana', error: '', ous: [
            { path: '/sistemas', label: 'Metropolitana - /sistemas', repeated: true },
        ] },
        { key: 'quebrado', name: 'Quebrado', error: 'HTTP 401', ous: [] },
    ],
};

test('optionLabel puts the workspace name before the path', () => {
    assert.equal(picker.optionLabel('Principal', '/a/b', false), 'Principal - /a/b');
});

test('optionLabel warns when the path exists in more than one workspace', () => {
    assert.equal(picker.optionLabel('Principal', '/a', true), 'Principal - /a (repetida)');
});

test('buildOptions stores only the path as the value', () => {
    const options = picker.buildOptions(payload, '');

    assert.deepEqual(options[0], { id: '/Sistemas', text: 'Principal - /Sistemas (repetida)', workspace: 'principal' });
    assert.equal(options[1].id, '/fimca.com.br');
    assert.ok(options.every((o) => o.id.startsWith('/') && !o.id.includes(' - ')));
});

test('buildOptions skips workspaces that failed', () => {
    const options = picker.buildOptions(payload, '');

    assert.equal(options.length, 3);
    assert.ok(!options.some((o) => o.workspace === 'quebrado'));
});

test('buildOptions narrows to one workspace when asked', () => {
    const options = picker.buildOptions(payload, 'metropolitana');

    assert.deepEqual(options.map((o) => o.id), ['/sistemas']);
});

test('buildOptions copes with an empty or broken payload', () => {
    assert.deepEqual(picker.buildOptions(null, ''), []);
    assert.deepEqual(picker.buildOptions({}, ''), []);
    assert.deepEqual(picker.buildOptions({ workspaces: [{ key: 'a', name: 'A' }] }, ''), []);
});

test('failedWorkspaces lists the workspaces whose list could not be read', () => {
    assert.deepEqual(picker.failedWorkspaces(payload), [{ name: 'Quebrado', error: 'HTTP 401' }]);
    assert.deepEqual(picker.failedWorkspaces({ workspaces: [{ key: 'a', name: 'A', error: '', ous: [] }] }), []);
    assert.deepEqual(picker.failedWorkspaces(null), []);
});

test('failureNote names the workspaces without a list', () => {
    assert.equal(picker.failureNote([]), '');
    assert.equal(
        picker.failureNote([{ name: 'Metropolitana', error: 'x' }, { name: 'Outro', error: 'y' }]),
        'Sem lista de OUs para: Metropolitana, Outro. Digite o caminho da OU.'
    );
});

test('appendPathLine adds the path as a new line', () => {
    assert.equal(picker.appendPathLine('', '/a'), '/a');
    assert.equal(picker.appendPathLine('/a\n/b', '/c'), '/a\n/b\n/c');
});

test('appendPathLine does not leave a blank line when the text ends with a newline', () => {
    assert.equal(picker.appendPathLine('/a\n/b\n', '/c'), '/a\n/b\n/c');
    assert.equal(picker.appendPathLine('/a\r\n/b\r\n', '/c'), '/a\r\n/b\n/c');
});

test('appendPathLine does not duplicate a line, ignoring case and spaces', () => {
    assert.equal(picker.appendPathLine('/Fimca/Docentes', '/fimca/docentes'), '/Fimca/Docentes');
    assert.equal(picker.appendPathLine('  /a  \n/b', '/a'), '  /a  \n/b');
});

test('appendPathLine does not treat a comment line as an existing entry', () => {
    assert.equal(picker.appendPathLine('# /a', '/a'), '# /a\n/a');
});

test('appendPathLine keeps what was typed by hand and ignores an empty path', () => {
    assert.equal(picker.appendPathLine('# docentes\n/x', '/y'), '# docentes\n/x\n/y');
    assert.equal(picker.appendPathLine('/x', '   '), '/x');
});

test('escapeHtml neutralises quotes, angle brackets and ampersands', () => {
    assert.equal(picker.escapeHtml('<b a="1" b=\'2\'>&'), '&lt;b a=&quot;1&quot; b=&#39;2&#39;&gt;&amp;');
    assert.equal(picker.escapeHtml('/Ana & "Bia"'), '/Ana &amp; &quot;Bia&quot;');
});
