(() => {
    const field = document.getElementById('classic-content');
    if (!field || !window.tinymce) return;
    const form = document.getElementById('page-editor');
    const config = JSON.parse(document.getElementById('classic-config').value);
    const status = document.getElementById('classic-status');
    const marker = document.getElementById('classic-changed');
    const code = document.getElementById('classic-code');
    const visualButton = document.getElementById('classic-visual-button');
    const codeButton = document.getElementById('classic-code-button');
    let codeMode = false;
    let saving = false; let busy = false;
    const markChanged = () => {marker.value='1';form.dispatchEvent(new Event('input',{bubbles:true}));};
    const syncCode = editor => {
        if (codeMode && code.value !== editor.getContent()) {
            editor.undoManager.transact(()=>editor.setContent(code.value));
            editor.setDirty(true);markChanged();
        }
    };
    const switchMode = nextCodeMode => {
        const editor=tinymce.get('classic-content');
        if (!editor?.initialized || busy || nextCodeMode===codeMode) return;
        if (nextCodeMode) code.value=editor.getContent();
        else syncCode(editor);
        codeMode=nextCodeMode;
        editor.getContainer().hidden=codeMode;code.hidden=!codeMode;
        visualButton.setAttribute('aria-pressed',String(!codeMode));
        codeButton.setAttribute('aria-pressed',String(codeMode));
        if(codeMode)code.focus();else editor.focus();
    };
    visualButton.addEventListener('click',()=>switchMode(false));
    codeButton.addEventListener('click',()=>switchMode(true));
    code.addEventListener('input',markChanged);
    code.addEventListener('keydown',event=>{
        if(event.key==='Tab' && !event.shiftKey){event.preventDefault();code.setRangeText('    ',code.selectionStart,code.selectionEnd,'end');markChanged();}
    });
    const upload = async (blobInfo, progress) => {
        const data = new FormData(); data.append('action','editor_upload'); data.append('csrf',form.elements.csrf.value); data.append('file',blobInfo.blob(),blobInfo.filename());
        progress(10); const response = await fetch('/admin/',{method:'POST',body:data});
        if (!response.ok) {let message='The upload failed. Sign in again or choose a supported file up to 20 MB.';try {message=(await response.json()).error || message;} catch {} throw Error(message);}
        const result = await response.json(); progress(100); return result.location;
    };
    const escape = value => {const span=document.createElement('span');span.textContent=value;return span.innerHTML.replace(/"/g,'&quot;').replace(/'/g,'&#39;');};
    tinymce.init({
        selector:'#classic-content', base_url:'/vendor/tinymce', suffix:'.min', license_key:'gpl',
        promotion:false, branding:false, language:'en', height:690, resize:true,
        plugins:'advlist autolink lists link image media table charmap anchor searchreplace visualblocks visualchars code fullscreen preview wordcount accordion nonbreaking insertdatetime',
        menubar:'edit view insert format tools table',
        toolbar:[
            'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | removeformat',
            'alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media table | cmslibrary cmscolumns cmscallout cmsbutton | accordion hr | searchreplace code preview fullscreen'
        ],
        toolbar_mode:'wrap', toolbar_sticky:false, contextmenu:'link image table',
        image_caption:true, image_advtab:true, image_title:true, images_file_types:'jpg,jpeg,png,gif,webp', paste_data_images:true,
        automatic_uploads:true, images_upload_handler:upload,
        files_upload_handler:async (blob,progress)=>({url:await upload(blob,progress),fileName:blob.filename()}),
        documents_file_types:[{mimeType:'application/pdf',extensions:['pdf']}],
        file_picker_types:'file image media', file_picker_callback:(callback,value,meta)=>window.cmsOpenMediaPicker(item=>callback(item.path,{alt:item.alt_text || '',text:item.name,title:item.name}),meta.filetype),
        convert_urls:false, relative_urls:false, remove_script_host:false,
        noneditable_class:'mceNonEditable',
        extended_valid_elements:'div[*],span[*],section[*],article[*],picture[*],source[*],details[*],summary[*],iframe[src|width|height|title|allow|allowfullscreen|sandbox|loading|referrerpolicy]',
        invalid_elements:'script,style,svg,form,input,button,select,textarea,object,embed',
        content_css:[...config.css,'/cms-content.css'], body_class:config.body_class,
        content_style:config.styles + '\nbody{padding:24px;min-height:500px;overflow:auto!important}img{max-width:100%;height:auto}.elementor-invisible{visibility:visible!important}.cms-protected{user-select:none;color:#637f87;font:12px system-ui;background:#edf3f5;border:1px dashed #c7d6dc;padding:8px}.cms-protected[data-cms-hidden]{display:none!important}table{max-width:100%}',
        style_formats:[{title:'Headings',items:[{title:'Heading 1',format:'h1'},{title:'Heading 2',format:'h2'},{title:'Heading 3',format:'h3'}]},{title:'Paragraph',format:'p'},{title:'Quote',format:'blockquote'},{title:'Callout',block:'div',classes:'cms-content-callout',wrapper:true},{title:'Button link',selector:'a',classes:'cms-content-button'}],
        style_formats_merge:true,
        setup(editor) {
            editor.ui.registry.addButton('cmslibrary',{text:'Media library',tooltip:'Insert an image or file from the media library',onAction:()=>window.cmsOpenMediaPicker(item=>{
                if(item.mime?.startsWith('image/'))editor.insertContent('<p><img src="'+escape(item.path)+'" alt="'+escape(item.alt_text || '')+'"></p>');
                else editor.insertContent('<p><a href="'+escape(item.path)+'">'+escape(item.name)+'</a></p>');
            },'file')});
            editor.ui.registry.addMenuButton('cmscolumns',{text:'Columns',fetch:callback=>callback([2,3].map(count=>({type:'menuitem',text:count+' columns',onAction:()=>editor.insertContent('<div class="cms-content-columns'+(count===3?' cms-three-columns':'')+'">'+Array.from({length:count},(_,i)=>'<div><h3>Column '+(i+1)+'</h3><p>Add your content here.</p></div>').join('')+'</div><p></p>')})))});
            editor.ui.registry.addButton('cmscallout',{text:'Callout',onAction:()=>editor.insertContent('<div class="cms-content-callout"><h3>Highlight your message</h3><p>Add your content here.</p></div><p></p>')});
            editor.ui.registry.addButton('cmsbutton',{text:'Button',onAction:()=>editor.windowManager.open({title:'Insert button',body:{type:'panel',items:[{type:'input',name:'text',label:'Button text'},{type:'input',name:'url',label:'Link URL'}]},initialData:{text:'Learn more',url:'/'},buttons:[{type:'cancel',text:'Cancel'},{type:'submit',text:'Insert',primary:true}],onSubmit:api=>{const data=api.getData();if(!/^(https?:\/\/|mailto:|tel:|\/(?!\/)|#)/i.test(data.url)){editor.windowManager.alert('Enter a web URL or local page path.');return;}editor.insertContent('<p><a class="cms-content-button" href="'+escape(data.url)+'">'+escape(data.text)+'</a></p>');api.close();}})});
            editor.on('change input undo redo',markChanged);
            editor.on('init',()=>{marker.value='0';status.textContent='';visualButton.disabled=false;codeButton.disabled=false;});
        }
    }).catch(()=>{status.textContent='The classic editor could not load. Reload the page or use another editor tab.';});
    document.getElementById('classic-add-media')?.addEventListener('click',()=>{const editor=tinymce.get('classic-content');window.cmsOpenMediaPicker(item=>{
        if(!editor?.initialized || busy)return;
        const html=item.mime?.startsWith('image/')?'<p><img src="'+escape(item.path)+'" alt="'+escape(item.alt_text || '')+'"></p>':'<p><a href="'+escape(item.path)+'">'+escape(item.name)+'</a></p>';
        if(codeMode){code.setRangeText(html,code.selectionStart,code.selectionEnd,'end');markChanged();code.focus();}
        else editor.insertContent(html);
    },'file');});
    form.addEventListener('submit',async event=>{
        const editor=tinymce.get('classic-content');if(!editor || saving)return;
        event.preventDefault();event.stopImmediatePropagation();
        if(busy)return;busy=true;
        const buttons=[...form.querySelectorAll('button:not([type]),button[type="submit"]')];buttons.forEach(button=>{button.disabled=true;});
        code.readOnly=true;visualButton.disabled=true;codeButton.disabled=true;
        try {syncCode(editor);status.textContent='Saving content and uploads…';await editor.uploadImages();field.value=editor.getContent();if(editor.isDirty())marker.value='1';saving=true;buttons.forEach(button=>{button.disabled=false;});const submitter=event.submitter;setTimeout(()=>form.requestSubmit(submitter || undefined),0);}
        catch(error){busy=false;code.readOnly=false;visualButton.disabled=false;codeButton.disabled=false;buttons.forEach(button=>{button.disabled=false;});status.textContent=error.message || 'Uploads could not be saved. Please try again.';form.dispatchEvent(new Event('input',{bubbles:true}));}
    },true);
})();
