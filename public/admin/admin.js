document.querySelectorAll('.copy-url').forEach(button => button.addEventListener('click', async () => {
    const input = button.closest('.media-info').querySelector('input');
    try { await navigator.clipboard.writeText(button.dataset.url); button.textContent = 'Copied!'; }
    catch { input.focus(); input.select(); button.textContent = 'Select & copy'; }
    setTimeout(() => { button.textContent = 'Copy URL'; }, 2000);
}));
document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (!confirm(form.dataset.confirm)) event.preventDefault();
}));
const filter = document.getElementById('content-search');
filter?.addEventListener('input', () => {
    const search = filter.value.toLowerCase();
    document.querySelectorAll('.text-block, .rich-block').forEach(block => { block.hidden = !(block.querySelector('.rich-editor')?.textContent || block.querySelector('textarea')?.value || '').toLowerCase().includes(search); });
});
const editor = document.getElementById('page-editor');
let changed = false;
editor?.addEventListener('input', () => { changed = true; const state = document.getElementById('save-state'); if (state) state.textContent = 'Unsaved changes'; });
editor?.addEventListener('submit', () => {
    document.querySelectorAll('.rich-editor').forEach(field => {
        const hidden = document.getElementById(field.dataset.field);
        if (hidden && field.innerHTML !== hidden.dataset.original) hidden.value = field.innerHTML;
    });
    changed = false;
});
window.addEventListener('beforeunload', event => { if (changed) { event.preventDefault(); event.returnValue = ''; } });

const selectAll = document.getElementById('select-all-pages');
const selections = [...document.querySelectorAll('.page-selection')];
function selectionCount() {
    const count = selections.filter(input => input.checked).length;
    const label = document.getElementById('selected-count'); if (label) label.textContent = `${count} selected`;
    if (selectAll) { selectAll.checked = count > 0 && count === selections.length; selectAll.indeterminate = count > 0 && count < selections.length; }
}
selectAll?.addEventListener('change', () => { selections.forEach(input => { input.checked = selectAll.checked; }); selectionCount(); });
selections.forEach(input => input.addEventListener('change', selectionCount));
document.getElementById('bulk-pages')?.addEventListener('submit', event => {
    if (!selections.some(input => input.checked)) { event.preventDefault(); alert('Select at least one page.'); return; }
    if (event.currentTarget.elements.target_status.value === 'trashed' && !confirm('Move the selected pages to Trash? You can restore them later.')) event.preventDefault();
});

document.querySelectorAll('[data-format]').forEach(button => {
    button.addEventListener('mousedown', event => event.preventDefault());
    button.addEventListener('click', () => {
        const selection = getSelection(); if (!selection || selection.rangeCount === 0 || selection.isCollapsed) return;
        const range = selection.getRangeAt(0); const parent = range.commonAncestorContainer.nodeType === Node.ELEMENT_NODE ? range.commonAncestorContainer : range.commonAncestorContainer.parentElement;
        const field = parent.closest('.rich-editor'); if (!field || !field.contains(range.startContainer) || !field.contains(range.endContainer)) return;
        const format = button.dataset.format;
        if (format === 'clear') { const text = document.createTextNode(range.toString()); range.deleteContents(); range.insertNode(text); range.selectNodeContents(text); }
        else {
            const wrapper = document.createElement(format === 'link' ? 'a' : format);
            if (format === 'link') {
                const url = prompt('Link URL (https://example.com or /page-path/)');
                if (!url) return; if (!/^(https?:\/\/|mailto:|tel:|\/(?!\/)|#)/i.test(url)) { alert('Enter an HTTPS, HTTP, email, telephone, or local page URL.'); return; }
                wrapper.setAttribute('href', url);
            }
            wrapper.appendChild(range.extractContents()); range.insertNode(wrapper); range.selectNodeContents(wrapper);
        }
        selection.removeAllRanges(); selection.addRange(range); field.dispatchEvent(new Event('input', {bubbles:true}));
    });
});
document.querySelectorAll('.rich-editor').forEach(field => field.addEventListener('paste', event => {
    event.preventDefault(); const selection = getSelection(); if (!selection?.rangeCount) return;
    const range = selection.getRangeAt(0); range.deleteContents(); const text = document.createTextNode(event.clipboardData.getData('text/plain')); range.insertNode(text); range.setStartAfter(text); range.collapse(true); selection.removeAllRanges(); selection.addRange(range);
    field.dispatchEvent(new Event('input', {bubbles:true}));
}));
let newBlockCount = 0;
document.querySelectorAll('.add-content-block').forEach(button => button.addEventListener('click', () => {
    const container = document.getElementById('new-content-blocks'); const block = document.createElement('label'); block.className = 'new-content-block';
    const name = document.createElement('span'); name.textContent = button.dataset.type === 'h2' ? 'Heading' : button.dataset.type === 'blockquote' ? 'Quote' : 'Paragraph';
    const type = document.createElement('input'); type.type = 'hidden'; type.name = `new_blocks[${newBlockCount}][type]`; type.value = button.dataset.type;
    const input = document.createElement('textarea'); input.name = `new_blocks[${newBlockCount++}][text]`; input.rows = button.dataset.type === 'h2' ? 1 : 3; input.placeholder = 'Write your content…';
    const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'text-button danger-text'; remove.textContent = 'Remove block'; remove.addEventListener('click', () => {block.remove(); editor.dispatchEvent(new Event('input', {bubbles:true}));});
    block.append(name, type, input, remove); container.append(block); input.focus(); editor.dispatchEvent(new Event('input', {bubbles:true}));
}));
const titleInput = document.getElementById('new-page-title'); const routeInput = document.getElementById('new-page-route'); let routeTouched = false;
routeInput?.addEventListener('input', () => {routeTouched = true;});
titleInput?.addEventListener('input', () => { if (!routeTouched) { const slug = titleInput.value.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,''); routeInput.value = slug ? `${routeInput.dataset.prefix || '/'}${slug}/` : ''; } });

const picker = document.getElementById('media-picker'); let pickerTarget; let pickerPage = 1; let pickerLoading = false; let pickerCallback; let pickerKind = 'image';
window.cmsOpenMediaPicker = (callback, kind = 'image') => {
    if (!picker) return; pickerTarget = null; pickerCallback = callback; pickerKind = kind; pickerPage = 1;
    picker.querySelector('h2').textContent = kind === 'image' ? 'Choose an image' : 'Choose a file'; picker.showModal(); loadImages();
};
async function loadImages(append = false) {
    if (pickerLoading) return; pickerLoading = true;
    const results = document.getElementById('picker-results'); const status = document.getElementById('picker-status'); status.textContent = 'Loading images…';
    try {
        const query = document.getElementById('picker-query').value;
        const response = await fetch(`/admin/?view=picker&q=${encodeURIComponent(query)}&page=${pickerPage}&kind=${pickerKind}`);
        if (!response.ok) throw Error('Images could not be loaded.'); const data = await response.json();
        if (!append) results.replaceChildren();
        data.items.forEach(item => {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'picker-image';
            const image = document.createElement(item.mime?.startsWith('image/') ? 'img' : 'div');
            if (image.tagName === 'IMG') { image.src = item.path; image.alt = ''; image.loading = 'lazy'; } else {image.className='picker-file-icon';image.textContent=item.mime==='application/pdf'?'PDF':'MEDIA';}
            const label = document.createElement('span'); label.textContent = item.name;
            button.append(image,label); button.addEventListener('click', () => {
                if (pickerCallback) {const callback = pickerCallback; pickerCallback = null; callback(item);}
                else if (pickerTarget) {pickerTarget.value = item.path; const thumbnail = pickerTarget.closest('.image-edit')?.querySelector('img'); if (thumbnail) thumbnail.src = item.path; pickerTarget.dispatchEvent(new Event('input', {bubbles:true}));}
                picker.close();
            }); results.append(button);
        });
        status.textContent = results.children.length ? 'Choose an image to use it on this page.' : 'No matching images.'; document.getElementById('picker-more').hidden = !data.has_more;
    } catch { status.textContent = 'Images could not be loaded. Sign in again or retry.'; } finally { pickerLoading = false; }
}
document.querySelectorAll('.choose-image').forEach(button => button.addEventListener('click', () => {pickerCallback = null;pickerKind = 'image';pickerTarget = document.getElementById(button.dataset.target);picker.querySelector('h2').textContent='Choose an image'; pickerPage = 1; picker.showModal(); loadImages();}));
document.getElementById('close-media-picker')?.addEventListener('click', () => picker.close());
document.getElementById('search-media-picker')?.addEventListener('click', () => {pickerPage = 1;loadImages();});
document.getElementById('picker-query')?.addEventListener('keydown', event => {if (event.key === 'Enter') {event.preventDefault();pickerPage = 1;loadImages();}});
document.getElementById('picker-more')?.addEventListener('click', () => {pickerPage++;loadImages(true);});

document.querySelectorAll('[data-banner-image]').forEach(input => {
    input.addEventListener('input', () => {
        const preview = input.closest('.image-edit').querySelector('.banner-image-preview');
        const image = preview.querySelector('img'); const empty = preview.querySelector('span');
        const url = input.value.trim();
        image.hidden = !url; empty.hidden = !!url;
        if (url && (/^\/(?:assets|uploads)\//.test(url) || /^https:\/\//i.test(url))) image.src = url;
        else image.removeAttribute('src');
    });
});
document.querySelectorAll('[data-clear-image]').forEach(button => button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.clearImage);
    input.value = ''; input.dispatchEvent(new Event('input', {bubbles:true}));
}));
