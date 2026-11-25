(() => {
  'use strict';
  if (document.body.dataset.page !== 'dashboard') return;
  const {api, upload, formatBytes, status: setStatus} = window.Portfolio;
  const $ = selector => document.querySelector(selector);
  const fileInput = $('#bulk-zip'), previewButton = $('#bulk-preview');
  const cancelButton = $('#bulk-cancel'), confirmButton = $('#bulk-confirm');
  const progress = $('#bulk-progress'), message = $('#bulk-status-message');
  const preview = $('#bulk-preview-results'), summary = $('#bulk-summary');
  const list = $('#bulk-preview-list'), statusSelect = $('#bulk-status');
  const skipDuplicates = $('#bulk-skip-duplicates');
  if (!fileInput || !previewButton || !confirmButton) return;

  let uploadId = '', chunkCount = 0, busy = false;
  const say = (text, kind = '') => setStatus(message, text, kind);
  function lock(value) {
    busy = value;
    previewButton.disabled = value || !fileInput.files.length;
    confirmButton.disabled = value || !uploadId || preview.hidden;
    cancelButton.disabled = value;
    fileInput.disabled = value;
    statusSelect.disabled = value;
    skipDuplicates.disabled = value;
  }
  function clearPreview() {
    uploadId = ''; chunkCount = 0;
    preview.hidden = true; list.replaceChildren();
    progress.hidden = true; progress.value = 0;
    cancelButton.hidden = true;
  }
  async function cancelRemote() {
    if (!uploadId) return;
    const fd = new FormData(); fd.set('upload_id', uploadId);
    try { await api('bulk_cancel', fd); } catch {}
    uploadId = '';
  }

  fileInput.addEventListener('change', () => {
    clearPreview();
    previewButton.disabled = !fileInput.files.length;
    say(fileInput.files.length ? 'ZIP-fil valgt. Klar til upload.' : '');
  });
  cancelButton.addEventListener('click', async () => {
    if (busy) return;
    lock(true);
    await cancelRemote();
    clearPreview(); fileInput.value = '';
    previewButton.disabled = true;
    say('Importen blev annulleret.');
    lock(false);
  });

  previewButton.addEventListener('click', async () => {
    const file = fileInput.files[0];
    if (busy || !file) return;
    if (!/\.zip$/i.test(file.name)) { say('Vælg en gyldig ZIP-fil (.zip).', 'error'); return; }
    lock(true); clearPreview(); progress.hidden = false; progress.value = 0;
    say('Starter sikker upload…');
    try {
      const started = await api('bulk_start', new FormData());
      uploadId = started.uploadId;
      const chunkSize = started.chunkSize || 6 * 1024 * 1024;
      const maxBytes = started.maxBytes || 10 * 1024 * 1024 * 1024;
      if (file.size < 1) throw new Error('ZIP-filen er tom.');
      if (file.size > maxBytes) throw new Error('ZIP-filen er større end serverens grænse på 10 GB.');
      chunkCount = Math.ceil(file.size / chunkSize);
      if (chunkCount > 2048) throw new Error('ZIP-filen kræver for mange upload-dele.');

      for (let index = 0; index < chunkCount; index++) {
        const start = index * chunkSize, end = Math.min(file.size, start + chunkSize);
        const fd = new FormData();
        fd.set('upload_id', uploadId);
        fd.set('chunk_index', String(index));
        fd.set('chunk', file.slice(start, end), file.name + '.part');
        await upload('bulk_chunk', fd);
        const percent = Math.round(end / file.size * 85);
        progress.value = percent;
        say('Uploader ZIP… ' + Math.round(end / file.size * 100) + '% (' + formatBytes(end) + ' af ' + formatBytes(file.size) + ')');
      }

      say('Samler og kontrollerer ZIP-filen…');
      const prep = new FormData();
      prep.set('upload_id', uploadId);
      prep.set('chunk_count', String(chunkCount));
      const result = await api('bulk_prepare', prep);
      const projects = result.projects || [];
      list.replaceChildren();
      for (const project of projects) {
        const item = document.createElement('p');
        item.className = 'bulk-preview-item';
        item.textContent = project.title + ' · ' +
          (project.category === 'dansk' ? 'Dansk' : 'Mediegrafiker') +
          ' · ' + project.fileCount + ' filer · ' + formatBytes(project.size || 0) +
          (project.duplicate ? ' · Findes allerede' : '');
        list.append(item);
      }
      const duplicates = projects.filter(p => p.duplicate).length;
      summary.textContent = projects.length + ' projekter fundet · ' +
        (result.fileCount || 0) + ' filer · ' + formatBytes(result.expandedBytes || 0) +
        ' udpakket. ' + duplicates + ' eksisterende projekter.';
      preview.hidden = false;
      cancelButton.hidden = false;
      progress.hidden = true;
      confirmButton.disabled = false;
      say('ZIP-filen er kontrolleret. Kontrollér oversigten, og start importen.', 'success');
    } catch (error) {
      await cancelRemote();
      clearPreview();
      say(error.message || 'ZIP-filen kunne ikke behandles.', 'error');
    } finally { lock(false); }
  });

  confirmButton.addEventListener('click', async () => {
    if (busy || !uploadId) return;
    lock(true); progress.hidden = false; progress.value = 0;
    say('Importerer projekterne…');
    try {
      const fd = new FormData();
      fd.set('upload_id', uploadId);
      fd.set('skip_duplicates', skipDuplicates.checked ? '1' : '0');
      fd.set('status', statusSelect.value);
      const result = await api('bulk_commit', fd);
      uploadId = '';
      const skipped = result.skipped || 0;
      say(result.created + ' projekter importeret' + (skipped ? ' · ' + skipped + ' dubletter sprunget over' : '') + '.', 'success');
      clearPreview(); fileInput.value = ''; previewButton.disabled = true;
      window.location.reload();
    } catch (error) {
      say(error.message || 'Importen mislykkedes.', 'error');
      progress.hidden = true;
    } finally { lock(false); }
  });

  previewButton.disabled = !fileInput.files.length;
  confirmButton.disabled = true;
  cancelButton.disabled = true;
})();