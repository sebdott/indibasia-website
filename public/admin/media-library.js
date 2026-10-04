(() => {
    const library = document.getElementById('media-library');
    if (!library) return;
    const records = JSON.parse(document.getElementById('media-records').value);
    const upload = document.getElementById('media-upload');
    const add = document.getElementById('media-add');
    add.addEventListener('click', () => {
        upload.hidden = !upload.hidden;
        add.setAttribute('aria-expanded', String(!upload.hidden));
        if (!upload.hidden) upload.querySelector('[type=file]').focus();
    });

    const bulk = document.getElementById('media-bulk');
    const toggle = document.getElementById('media-bulk-toggle');
    const selections = [...library.querySelectorAll('.media-selection')];
    const selectAll = document.getElementById('media-select-all');
    let selecting = false;
    const selectionCount = () => {
        const count = selections.filter(input => input.checked).length;
        document.getElementById('media-selected-count').textContent = `${count} selected`;
        bulk.querySelector('button[type=submit], button:not([type])').disabled = count === 0;
        selectAll.checked = count > 0 && count === selections.length;
        selectAll.indeterminate = count > 0 && count < selections.length;
        selections.forEach(input => input.closest('[data-media-id]').classList.toggle('selected', input.checked));
    };
    const setSelecting = active => {
        selecting = active;
        library.classList.toggle('media-select-mode', active); bulk.hidden = !active;
        toggle.setAttribute('aria-pressed', String(active)); toggle.textContent = active ? 'Cancel selection' : 'Bulk select';
        if (!active) selections.forEach(input => input.checked = false);
        selectionCount();
    };
    toggle.addEventListener('click', () => setSelecting(!selecting));
    document.getElementById('media-bulk-cancel').addEventListener('click', () => setSelecting(false));
    selections.forEach(input => input.addEventListener('change', selectionCount));
    selectAll.addEventListener('change', () => { selections.forEach(input => input.checked = selectAll.checked); selectionCount(); });
    bulk.addEventListener('submit', event => {
        if (!selections.some(input => input.checked)) event.preventDefault();
        else if (bulk.elements.target_status.value === 'trash' && !confirm('Move the selected files to Trash? You can restore them later.')) event.preventDefault();
    });

    const dialog = document.getElementById('attachment-details');
    const form = document.getElementById('attachment-form');
    const fields = document.getElementById('attachment-fields');
    const preview = document.getElementById('attachment-preview');
    const status = document.getElementById('attachment-status');
    const save = document.getElementById('attachment-save');
    const trash = document.getElementById('attachment-trash');
    const previous = document.getElementById('attachment-prev');
    const next = document.getElementById('attachment-next');
    let index = 0; let dirty = false; let busy = false; let request;
    const message = (text, error = false) => { status.textContent = text; status.classList.toggle('attachment-error', error); };
    const mayLeave = () => !busy && (!dirty || confirm('Discard unsaved attachment changes?'));
    const fileSize = bytes => bytes === null ? 'Unavailable' : bytes < 1024 ? `${bytes} B` : bytes < 1024 * 1024 ? `${(bytes / 1024).toFixed(1)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    const render = (file, ready) => {
        document.getElementById('attachment-position').textContent = `${index + 1} of ${records.length}`;
        previous.disabled = index === 0; next.disabled = index === records.length - 1;
        fields.disabled = !ready || !!file.trashed_at; save.disabled = !ready || !!file.trashed_at;
        save.hidden = !!file.trashed_at;
        trash.disabled = !ready; trash.textContent = file.trashed_at ? 'Restore file' : 'Move to Trash';
        trash.classList.toggle('danger-text', !file.trashed_at);
        form.elements.id.value = file.id;
        for (const key of ['title', 'alt_text', 'caption', 'description']) form.elements[key].value = file[key] || '';
        document.getElementById('attachment-alt-label').hidden = !file.mime.startsWith('image/');
        const url = new URL(file.path, location.origin).href;
        document.getElementById('attachment-url').value = url;
        document.getElementById('attachment-copy').textContent = 'Copy URL';
        for (const id of ['attachment-open', 'attachment-download']) document.getElementById(id).href = url;
        document.getElementById('attachment-download').setAttribute('download', file.name);
        const facts = document.getElementById('attachment-facts'); facts.replaceChildren();
        const details = {'Uploaded on':file.created_at, 'Uploaded by':file.author, 'File name':file.name, 'File type':file.mime};
        if (ready) {
            details['File size'] = fileSize(file.size);
            if (file.width) details.Dimensions = `${file.width} × ${file.height} pixels`;
        }
        if (file.trashed_at) details.Status = 'In Trash';
        for (const [label, value] of Object.entries(details)) {
            const term = document.createElement('dt'); term.textContent = label;
            const definition = document.createElement('dd'); definition.textContent = value;
            facts.append(term, definition);
        }
        preview.replaceChildren();
        if (file.available === false) {
            const missing = document.createElement('p'); missing.textContent = 'This file is unavailable. Its details are still saved in the library.'; preview.append(missing);
        } else if (file.mime.startsWith('image/')) {
            const image = document.createElement('img'); image.src = file.path; image.alt = file.alt_text || file.title;
            image.addEventListener('error', () => { preview.textContent = 'Image could not be loaded.'; }); preview.append(image);
        } else if (file.mime === 'application/pdf') {
            const frame = document.createElement('iframe'); frame.src = file.path; frame.title = `Preview of ${file.name}`; preview.append(frame);
        } else if (file.mime.startsWith('video/') || file.mime.startsWith('audio/')) {
            const player = document.createElement(file.mime.startsWith('video/') ? 'video' : 'audio'); player.src = file.path; player.controls = true; player.preload = 'metadata'; preview.append(player);
        } else {
            const icon = document.createElement('div'); icon.className = 'attachment-file-icon'; icon.textContent = file.name.split('.').pop().toUpperCase(); preview.append(icon);
        }
    };
    const open = async position => {
        request?.abort(); request = new AbortController(); const signal = request.signal;
        index = position; dirty = false;
        render(records[index], false); message('Loading attachment details…');
        if (!dialog.open) dialog.showModal();
        try {
            const response = await fetch(`/admin/?view=media_details&id=${records[index].id}`, {signal});
            const data = await response.json(); if (!response.ok) throw Error(data.error || 'Attachment details could not be loaded.');
            if (signal.aborted) return;
            records[index] = data.file; render(data.file, true); message(data.file.trashed_at ? 'Restore this file to edit its details.' : '');
        } catch (error) { if (!signal.aborted) message(error.message, true); }
    };
    library.querySelectorAll('[data-media-open]').forEach(button => button.addEventListener('click', () => {
        if (selecting) {
            const input = button.closest('[data-media-id]').querySelector('.media-selection'); input.checked = !input.checked; selectionCount(); return;
        }
        const position = records.findIndex(file => String(file.id) === button.dataset.mediaOpen);
        if (position >= 0) open(position);
    }));
    const close = () => { if (mayLeave()) { request?.abort(); dirty = false; preview.replaceChildren(); dialog.close(); } };
    document.getElementById('attachment-close').addEventListener('click', close);
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    previous.addEventListener('click', () => { if (index > 0 && mayLeave()) open(index - 1); });
    next.addEventListener('click', () => { if (index < records.length - 1 && mayLeave()) open(index + 1); });
    form.addEventListener('input', () => { dirty = true; message('Unsaved changes'); });
    const setBusy = value => {
        busy = value; save.disabled = value || !!records[index].trashed_at; trash.disabled = value;
        previous.disabled = value || index === 0; next.disabled = value || index === records.length - 1;
        document.getElementById('attachment-close').disabled = value;
        fields.disabled = value || !!records[index].trashed_at;
    };
    const post = async data => {
        const response = await fetch('/admin/?view=media', {method:'POST', body:data});
        const result = await response.json(); if (!response.ok) throw Error(result.error || 'The change could not be saved.'); return result;
    };
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (busy) return;
        const data = new FormData(form); setBusy(true); message('Saving…');
        try {
            const result = await post(data); records[index] = result.file; dirty = false;
            render(result.file, true); message(result.message);
            const item = library.querySelector(`[data-media-id="${result.file.id}"]`);
            item.querySelectorAll('.media-item-title').forEach(title => { title.textContent = result.file.title; title.title = result.file.title; });
            item.querySelectorAll('[data-media-open]').forEach(button => button.setAttribute('aria-label', `View attachment: ${result.file.title}`));
            item.querySelectorAll('img').forEach(image => image.alt = result.file.alt_text);
        } catch (error) { message(error.message, true); } finally { setBusy(false); }
    });
    document.getElementById('attachment-copy').addEventListener('click', async event => {
        const button = event.currentTarget;
        const input = document.getElementById('attachment-url');
        try { await navigator.clipboard.writeText(input.value); button.textContent = 'Copied!'; }
        catch { input.focus(); input.select(); message('Select and copy the file URL.'); }
    });
    trash.addEventListener('click', async () => {
        if (busy || !mayLeave()) return;
        const file = records[index]; const target = file.trashed_at ? 'restore' : 'trash';
        if (target === 'trash' && !confirm('Move this file to Trash? You can restore it later.')) return;
        const data = new FormData(); data.set('action', 'media_status'); data.set('id', file.id); data.set('target_status', target); data.set('csrf', form.elements.csrf.value);
        setBusy(true);
        try { await post(data); dirty = false; location.reload(); }
        catch (error) { message(error.message, true); setBusy(false); }
    });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
})();
