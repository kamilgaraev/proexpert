import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/filament/blog-inline-block-editor.js', import.meta.url), 'utf8');

function editor(wire) {
    let create;
    runInNewContext(source, { window: { Alpine: { data: (_, factory) => { create = factory; } } } });
    return create({
        state: [{ type: 'materials', data: { items: [{ url: '', label: 'График работ', description: '' }] } }],
        statePath: 'data.editor_document',
        wire,
        labels: { uploadFailed: 'Не удалось загрузить документ' },
    });
}

test('upload attaches server asset, preserves editorial label and unlocks editor', async () => {
    let completed;
    let saved;
    const state = editor({
        upload: (property, file, callback) => { assert.equal(property, 'inline_document_upload'); completed = callback; },
        uploadInlineDocument: async () => ({ url: 'https://storage.test/schedule.xlsx', label: 'schedule.xlsx' }),
        $set: (_, value) => { saved = value; },
    });
    const event = { target: { files: [{}], value: 'schedule.xlsx' } };
    state.uploadDocument(event, state.state[0].data.items[0]);
    assert.equal(state.uploadingDocument, true);
    await completed();
    assert.equal(state.uploadingDocument, false);
    assert.equal(saved[0].data.items[0].url, 'https://storage.test/schedule.xlsx');
    assert.equal(saved[0].data.items[0].label, 'График работ');
    assert.equal(event.target.value, '');
    assert.equal(state.documentOptions['https://storage.test/schedule.xlsx'], 'schedule.xlsx');
});

test('server validation failure preserves material and allows retry', async () => {
    let completed;
    const state = editor({
        upload: (_, file, callback) => { completed = callback; },
        uploadInlineDocument: async () => { throw { errors: { upload_file: ['Недопустимый формат'] } }; },
    });
    state.uploadDocument({ target: { files: [{}], value: '' } }, state.state[0].data.items[0]);
    await completed();
    assert.equal(state.documentUploadError, 'Недопустимый формат');
    assert.equal(state.uploadingDocument, false);
    assert.equal(state.state[0].data.items[0].url, '');
});

test('network failure clears busy state and selecting an existing asset needs no upload', () => {
    let failed;
    const state = editor({
        upload: (_, file, success, error) => { failed = error; },
        $set: () => {},
    });
    state.uploadDocument({ target: { files: [{}], value: '' } }, state.state[0].data.items[0]);
    failed();
    assert.equal(state.uploadingDocument, false);
    assert.equal(state.documentUploadError, 'Не удалось загрузить документ');
    state.documentOptions['https://storage.test/a.pdf'] = 'Памятка.pdf';
    const item = state.state[0].data.items[0];
    item.url = 'https://storage.test/a.pdf';
    item.label = '';
    state.selectMaterial(item);
    assert.equal(state.state[0].data.items[0].label, 'Памятка.pdf');
});
