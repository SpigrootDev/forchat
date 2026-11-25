(() => {
  'use strict';
  if (document.body.dataset.page !== 'dashboard') return;
  const form = document.querySelector('#bulk-form');
  if (!form) return;
  const status = document.querySelector('#bulk-status');
  const preview = document.querySelector('#bulk-preview');
  const list = document.querySelector('#bulk-project-list');
  const scanButton = document.querySelector('#bulk-preview-button');
  const importButton = document.querySelector('#bulk-import-button');
  let token = '';
  function message(text, kind='') { status.textContent=text; status.className=('status '+kind).trim(); }
  function api(action, data) {
    return new Promise((resolve,reject) => {
      const xhr=new XMLHttpRequest(); xhr.open('POST','api.php?action='+encodeURIComponent(action));
      xhr.setRequestHeader('X-CSRF-Token', window.__portfolioCsrf || '');
      xhr.upload.onprogress=e=>{if(e.lengthComputable) message('Uploader ZIP… '+Math.round(e.loaded/e.total*100)+'%');};
      xhr.onload=()=>{let r;try{r=JSON.parse(xhr.responseText)}catch{reject(new Error('Serveren returnerede ikke gyldig JSON. Kontrollér upload-grænserne.'));return}
        if(xhr.status<200||xhr.status>=300){reject(new Error(r.error||'Importen mislykkedes.'));return} resolve(r)};
      xhr.onerror=()=>reject(new Error('Ingen forbindelse til serveren.')); xhr.send(data);
    });
  }
  form.addEventListener('submit', async e=>{
    e.preventDefault(); scanButton.disabled=true; preview.hidden=true; list.replaceChildren(); message('Scanner ZIP…');
    try {
      const fd=new FormData(form); const r=await api('bulk_preview',fd); token=r.token;
      r.projects.forEach((p,i)=>{
        const label=document.createElement('label'); label.className='checkbox-label';
        const input=document.createElement('input'); input.type='checkbox'; input.checked=true; input.value=p.category+'/'+p.title;
        input.dataset.projectKey=input.value;
        const text=document.createElement('span'); text.textContent=p.title+' · '+(p.category==='dansk'?'Dansk':'Mediegrafiker')+' · '+p.files.length+' filer · '+(p.totalSize/1048576).toFixed(1)+' MB';
        label.append(input,text); list.append(label);
      });
      preview.hidden=false; message(r.projects.length+' projekter fundet. Alle er valgt som standard.','success');
    } catch(err){message(err.message,'error')} finally{scanButton.disabled=false}
  });
  importButton.addEventListener('click',async()=>{
    const selected=[...list.querySelectorAll('input:checked')].map(x=>x.dataset.projectKey);
    if(!selected.length){message('Vælg mindst ét projekt.','error');return}
    importButton.disabled=true; message('Importerer projekter som kladder…');
    const fd=new FormData(); fd.set('token',token); fd.set('selected',JSON.stringify(selected));
    try{const r=await api('bulk_import',fd);message(r.imported+' projekter importeret som kladder.','success');preview.hidden=true;form.reset();token='';}
    catch(err){message(err.message,'error')}finally{importButton.disabled=false}
  });
})();