(() => {
  'use strict';
  if (document.body.dataset.page !== 'dashboard') return;
  const {api, upload, formatBytes, status: setStatus} = window.Portfolio;
  const fileInput = document.querySelector('#bulk-zip');
  const previewButton = document.querySelector('#bulk-preview');
  const cancelButton = document.querySelector('#bulk-cancel');
  const confirmButton = document.querySelector('#bulk-confirm');
  const progress = document.querySelector('#bulk-progress');
  const message = document.querySelector('#bulk-status-message');
  const preview = document.querySelector('#bulk-preview-results');
  const summary = document.querySelector('#bulk-summary');
  const list = document.querySelector('#bulk-preview-list');
  const statusSelect = document.querySelector('#bulk-status');
  const skipDuplicates = document.querySelector('#bulk-skip-duplicates');
  if (!fileInput || !previewButton || !confirmButton) return;

  let token = '';
  let projects = [];
  let busy = false;
  const say = (text, kind='') => setStatus(message, text, kind);
  function lock(value) {
    busy = value;
    previewButton.disabled = value || !fileInput.files.length;
    confirmButton.disabled = value;
    cancelButton.disabled = value;
    fileInput.disabled = value;
    statusSelect.disabled = value;
    skipDuplicates.disabled = value;
  }
  function clearPreview() {
    token = ''; projects = []; preview.hidden = true; list.replaceChildren();
    progress.hidden = true; progress.value = 0; cancelButton.hidden = true;
  }
  fileInput.addEventListener('change', () => {
    clearPreview();
    previewButton.disabled = !fileInput.files.length;
    say(fileInput.files.length ? 'ZIP-fil valgt. Klar til at scanne.' : '');
  });
  cancelButton.addEventListener('click', () => {
    clearPreview(); fileInput.value = ''; previewButton.disabled = true;
    say('Importen blev annulleret.');
  });
  previewButton.addEventListener('click', async () => {
    if (busy || !fileInput.files.length) return;
    lock(true); clearPreview(); progress.hidden = false; progress.value = 0;
    say('Uploader og scanner ZIP-filen…');
    try {
      const fd = new FormData(); fd.set('archive', fileInput.files[0]);
      const result = await upload('bulk_preview', fd, (loaded, total) => {
        progress.value = Math.round(loaded / total * 100);
        say('Uploader ZIP… ' + progress.value + '% (' + formatBytes(loaded) + ' af ' + formatBytes(total) + ')');
      });
      token = result.token; projects = result.projects || [];
      let existing = [];
      if (skipDuplicates.checked) {
        try { existing = (await api('posts')).posts || []; } catch {}
      }
      const existingKeys = new Set(existing.map(p => String(p.category).toLowerCase() + '/' + String(p.title).trim().toLowerCase()));
      const skipped = [];
      projects = projects.map(p => ({...p, key: p.category + '/' + p.title,
        duplicate: existingKeys.has(p.category + '/' + p.title.trim().toLowerCase())}));
      list.replaceChildren();
      for (const project of projects) {
        const label = document.createElement('label'); label.className = 'checkbox-label';
        const checkbox = document.createElement('input'); checkbox.type = 'checkbox';
        checkbox.value = project.key; checkbox.checked = !project.duplicate;
        checkbox.disabled = project.duplicate;
        const detail = document.createElement('span');
        const size = formatBytes(project.totalSize || 0);
        detail.textContent = project.title + ' · ' + (project.category === 'dansk' ? 'Dansk' : 'Mediegrafiker') +
          ' · ' + project.files.length + ' filer · ' + size + (project.duplicate ? ' · Findes allerede (sprunget over)' : '');
        label.append(checkbox, detail); list.append(label);
      }
      const eligible = projects.filter(p => !p.duplicate).length;
      summary.textContent = projects.length + ' projekter fundet. ' + (projects.length - eligible) +
        ' eksisterer allerede. ' + eligible + ' kan importeres.';
      preview.hidden = false; cancelButton.hidden = false; progress.hidden = true;
      say('ZIP-filen er scannet. Kontrollér projekterne nedenfor.', 'success');
    } catch (error) {
      say(error.message || 'Kunne ikke scanne ZIP-filen.', 'error');
      clearPreview();
    } finally { lock(false); }
  });
  confirmButton.addEventListener('click', async () => {
    if (busy || !token) return;
    const selected = [...list.querySelectorAll('input[type=checkbox]:checked')].map(x => x.value);
    if (!selected.length) { say('Vælg mindst ét projekt, der skal importeres.', 'error'); return; }
    lock(true); progress.hidden = false; progress.value = 0;
    say('Importerer ' + selected.length + ' projekter…');
    try {
      const fd = new FormData(); fd.set('token', token); fd.set('selected', JSON.stringify(selected));
      fd.set('status', statusSelect.value);
      const result = await upload('bulk_import', fd, (loaded, total) => {
        progress.value = Math.round(loaded / total * 100);
      });
      say(result.imported + ' projekter importeret som ' + (statusSelect.value === 'draft' ? 'kladder' : 'offentlige projekter') + '.', 'success');
      clearPreview(); fileInput.value = ''; previewButton.disabled = true;
      if (typeof window.Portfolio.api === 'function') window.location.reload();
    } catch (error) { say(error.message || 'Importen mislykkedes.', 'error'); }
    finally { lock(false); }
  });
  previewButton.disabled = !fileInput.files.length;
  confirmButton.disabled = false;
})();