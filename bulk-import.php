<?php
declare(strict_types=1);

/*
 * Bulk ZIP importer for the portfolio.
 * ZIP layout: dansk/Project name/files... or mediegrafiker/Project name/files...
 * Uploads are chunked; temporary data stays outside the web root.
 */
function bulk_remove_tree(string $path): void {
    if (!is_dir($path) || is_link($path)) { if (is_file($path) || is_link($path)) @unlink($path); return; }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        bulk_remove_tree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
function bulk_dir(string $id): string {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) reply(['error' => 'Ugyldigt upload-id.'], 422);
    $dir = DATA_DIR . '/imports/' . $id;
    if (!is_dir($dir) || empty($_SESSION['bulk_uploads'][$id])) reply(['error' => 'Uploaden findes ikke eller er udløbet. Start igen.'], 404);
    return $dir;
}
function bulk_require_id(): string {
    $id = input('upload_id');
    bulk_dir($id);
    return $id;
}
function bulk_safe_name(string $name): string {
    $name = trim(str_replace(["\0", '\\'], ['', '/'], $name));
    $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
    return trim($name, " /");
}
function bulk_mime(string $path): string {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    $images = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, array_merge($images, ['application/pdf']), true) ||
        (in_array($mime, $images, true) && !@getimagesize($path))) return 'application/octet-stream';
    return $mime;
}
function bulk_handle(string $action, PDO $db): void {
    if (!in_array($action, ['bulk_start', 'bulk_chunk', 'bulk_prepare', 'bulk_commit', 'bulk_cancel'], true)) return;
    if (empty($_SESSION['admin'])) reply(['error' => 'Log ind for at fortsætte.'], 401);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(['error' => 'Brug POST.'], 405);
    if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) reply(['error' => 'Genindlæs siden og prøv igen.'], 403);

    if ($action === 'bulk_start') {
        if (!class_exists('ZipArchive')) reply(['error' => 'PHP ZipArchive mangler på serveren. Installer php-zip og genstart Apache.'], 503);
        $root = DATA_DIR . '/imports';
        if (!is_dir($root) && !mkdir($root, 0700, true)) reply(['error' => 'Kunne ikke oprette midlertidig importmappe.'], 500);
        // Remove abandoned imports older than 24 hours.
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $old) if (filemtime($old) < time() - 86400) bulk_remove_tree($old);
        $id = bin2hex(random_bytes(16)); $dir = $root . '/' . $id;
        if (!mkdir($dir, 0700)) reply(['error' => 'Kunne ikke starte importen.'], 500);
        $_SESSION['bulk_uploads'][$id] = time();
        reply(['ok' => true, 'uploadId' => $id, 'chunkSize' => 8 * 1024 * 1024, 'maxBytes' => 10 * 1024 * 1024 * 1024]);
    }

    $id = bulk_require_id(); $dir = bulk_dir($id);
    if ($action === 'bulk_chunk') {
        $index = filter_var($_POST['chunk_index'] ?? null, FILTER_VALIDATE_INT);
        if ($index === false || $index < 0 || $index > 2047) reply(['error' => 'Ugyldigt chunk-nummer.'], 422);
        if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['chunk']['tmp_name'])) {
            reply(['error' => 'Chunk-upload mislykkedes. Kontrollér PHP upload-grænsen.'], 413);
        }
        if ($_FILES['chunk']['size'] < 1 || $_FILES['chunk']['size'] > 9 * 1024 * 1024) reply(['error' => 'Chunk-størrelsen er ugyldig.'], 422);
        $target = $dir . '/' . $index . '.part';
        if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $target)) reply(['error' => 'Kunne ikke gemme upload-chunk. Kontrollér diskplads og skriverettigheder.'], 500);
        chmod($target, 0600);
        reply(['ok' => true, 'chunk' => $index]);
    }

    if ($action === 'bulk_cancel') {
        bulk_remove_tree($dir); unset($_SESSION['bulk_uploads'][$id]); reply(['ok' => true]);
    }

    if ($action === 'bulk_prepare') {
        @set_time_limit(0);
        if (!class_exists('ZipArchive')) reply(['error' => 'PHP ZipArchive mangler på serveren.'], 503);
        $count = filter_var($_POST['chunk_count'] ?? null, FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > 2048) reply(['error' => 'Ugyldigt antal upload-dele.'], 422);
        $archivePath = $dir . '/archive.zip';
        $out = fopen($archivePath, 'wb');
        if (!$out) reply(['error' => 'Kunne ikke samle ZIP-filen.'], 500);
        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $part = $dir . '/' . $i . '.part';
            if (!is_file($part)) { fclose($out); reply(['error' => 'En upload-del mangler. Uploaden kan ikke fortsætte.'], 422); }
            $size = filesize($part); $total += $size;
            if ($total > 10 * 1024 * 1024 * 1024) { fclose($out); reply(['error' => 'ZIP-filen er større end 10 GB.'], 413); }
            $in = fopen($part, 'rb'); stream_copy_to_stream($in, $out); fclose($in); unlink($part);
        }
        fclose($out); chmod($archivePath, 0600);
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) reply(['error' => 'Filen er ikke en gyldig ZIP-fil.'], 422);

        $projects = []; $totalExpanded = 0; $fileCount = 0; $extractRoot = $dir . '/extracted';
        mkdir($extractRoot, 0700);
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat) continue;
                $raw = (string)$stat['name'];
                if (str_ends_with($raw, '/')) continue;
                $path = bulk_safe_name($raw);
                $parts = explode('/', $path);
                if (count($parts) < 3 || in_array('..', $parts, true) || preg_match('/^[A-Za-z]:/', $path)) continue;
                $category = strtolower($parts[0]);
                if (!in_array($category, ['dansk', 'mediegrafiker'], true)) continue;
                $projectName = trim($parts[1]);
                $relative = implode('/', array_slice($parts, 2));
                if ($projectName === '' || $relative === '' || str_starts_with($relative, '.')) continue;
                if (in_array('__MACOSX', $parts, true) || basename($relative) === '.DS_Store') continue;

                $opsys = 0; $attr = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                    $mode = ($attr >> 16) & 0170000;
                    if ($mode === 0120000) throw new RuntimeException('ZIP-filen indeholder et symbolsk link, som ikke må importeres.');
                }
                $size = (int)$stat['size']; $totalExpanded += $size; $fileCount++;
                if ($fileCount > 10000 || $totalExpanded > 25 * 1024 * 1024 * 1024) throw new RuntimeException('ZIP-filen indeholder for mange filer eller mere end 25 GB udpakket data.');
                $projectKey = $category . "\n" . $projectName;
                $targetDir = $extractRoot . '/' . $category . '/' . hash('sha256', $projectName);
                $target = $targetDir . '/' . bin2hex(random_bytes(12)) . '.bin';
                if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true)) throw new RuntimeException('Kunne ikke oprette importmappe.');
                $stream = $zip->getStream($raw);
                if (!$stream) throw new RuntimeException('En fil i ZIP-arkivet kunne ikke læses.');
                $dest = fopen($target, 'wb');
                if (!$dest) { fclose($stream); throw new RuntimeException('Kunne ikke udpakke en fil.'); }
                $copied = stream_copy_to_stream($stream, $dest); fclose($stream); fclose($dest);
                if ($copied !== $size) throw new RuntimeException('En fil blev udpakket ufuldstændigt.');
                chmod($target, 0600);
                $mtime = (int)($stat['mtime'] ?? 0);
                if (!isset($projects[$projectKey])) $projects[$projectKey] = ['category' => $category, 'title' => mb_substr($projectName, 0, 120), 'date' => $mtime > 946684800 ? gmdate('Y-m-d', $mtime) : date('Y-m-d'), 'files' => [], 'size' => 0];
                if ($mtime > 946684800 && gmdate('Y-m-d', $mtime) > $projects[$projectKey]['date']) $projects[$projectKey]['date'] = gmdate('Y-m-d', $mtime);
                $projects[$projectKey]['files'][] = ['path' => $target, 'name' => basename($relative), 'size' => $size];
                $projects[$projectKey]['size'] += $size;
            }
            if (!$projects) throw new RuntimeException('Ingen projekter fundet. Brug mapperne dansk/Projektnavn/ og mediegrafiker/Projektnavn/.');
            foreach ($projects as &$project) {
                $q = $db->prepare('SELECT COUNT(*) FROM posts WHERE title=? AND category=?');
                $q->execute([$project['title'], $project['category']]);
                $project['duplicate'] = (int)$q->fetchColumn() > 0;
                $project['fileCount'] = count($project['files']);
                unset($project['files']);
            }
            unset($project);
            file_put_contents($dir . '/manifest.json', json_encode(array_keys($projects), JSON_THROW_ON_ERROR));
            // Keep actual project data in a PHP-serialized staging manifest outside the web root.
            $internal = [];
            foreach ($projects as $key => $project) $internal[$key] = $project;
            // Rebuild the file mapping from the extraction directory's deterministic scan order.
            $full = [];
            foreach ($projects as $key => $project) $full[$key] = $project;
            // Store full paths separately; never return paths to the browser.
            $paths = [];
            foreach ($projects as $key => $project) {
                $parts = explode("\n", $key, 2);
                $folder = $extractRoot . '/' . $parts[0] . '/' . hash('sha256', $parts[1]);
                $paths[$key] = ['category' => $project['category'], 'title' => $project['title'], 'date' => $project['date'], 'duplicate' => $project['duplicate'], 'files' => []];
                foreach (glob($folder . '/*.bin') ?: [] as $file) {
                    $paths[$key]['files'][] = ['path' => $file, 'name' => basename($file), 'size' => filesize($file)];
                }
            }
            // Retain original display filenames and sizes in a sidecar in ZIP order.
            $sidecar = [];
            foreach ($projects as $key => $project) {
                $parts = explode("\n", $key, 2);
                $folder = $extractRoot . '/' . $parts[0] . '/' . hash('sha256', $parts[1]);
                $sidecar[$key] = ['category' => $project['category'], 'title' => $project['title'], 'date' => $project['date'], 'duplicate' => $project['duplicate'], 'files' => []];
                foreach ($project['files'] ?? [] as $f) $sidecar[$key]['files'][] = $f;
                if (!$sidecar[$key]['files']) {
                    foreach (glob($folder . '/*.bin') ?: [] as $file) $sidecar[$key]['files'][] = ['path' => $file, 'name' => 'imported-file', 'size' => filesize($file)];
                }
            }
            file_put_contents($dir . '/manifest.dat', serialize($sidecar), LOCK_EX);
            $zip->close();
            $summary = [];
            foreach ($sidecar as $p) $summary[] = ['title' => $p['title'], 'category' => $p['category'], 'date' => $p['date'], 'fileCount' => count($p['files']), 'size' => array_sum(array_column($p['files'], 'size')), 'duplicate' => $p['duplicate']];
            reply(['ok' => true, 'projects' => $summary, 'projectCount' => count($summary), 'fileCount' => $fileCount, 'expandedBytes' => $totalExpanded]);
        } catch (Throwable $e) {
            $zip->close(); bulk_remove_tree($extractRoot);
            reply(['error' => $e instanceof RuntimeException ? $e->getMessage() : 'ZIP-filen kunne ikke behandles.'], 422);
        }
    }

    if ($action === 'bulk_commit') {
        @set_time_limit(0);
        $manifestPath = $dir . '/manifest.dat';
        if (!is_file($manifestPath)) reply(['error' => 'Lav først en forhåndsvisning af ZIP-filen.'], 422);
        $manifest = @unserialize((string)file_get_contents($manifestPath), ['allowed_classes' => false]);
        if (!is_array($manifest)) reply(['error' => 'Importoversigten er ugyldig. Upload ZIP-filen igen.'], 422);
        $skipDuplicates = input('skip_duplicates') !== '0';
        $status = input('status') === 'published' ? 'published' : 'draft';
        $createdKeys = []; $created = 0; $skipped = 0;
        $db->beginTransaction();
        try {
            foreach ($manifest as $project) {
                $q = $db->prepare('SELECT COUNT(*) FROM posts WHERE title=? AND category=?');
                $q->execute([$project['title'], $project['category']]);
                if ($skipDuplicates && (int)$q->fetchColumn() > 0) { $skipped++; continue; }
                if (empty($project['files'])) continue;
                $stored = [];
                foreach ($project['files'] as $file) {
                    if (!is_file($file['path']) || !str_starts_with(realpath($file['path']) ?: '', realpath($dir . '/extracted') . DIRECTORY_SEPARATOR)) throw new RuntimeException('En midlertidig importfil mangler eller er ugyldig.');
                    $key = bin2hex(random_bytes(24)); $target = DATA_DIR . '/' . $key;
                    if (!copy($file['path'], $target)) throw new RuntimeException('Kunne ikke gemme en projektfil. Kontrollér diskplads.');
                    chmod($target, 0600); $createdKeys[] = $key;
                    $stored[] = ['key' => $key, 'name' => substr($file['name'], 0, 200), 'type' => bulk_mime($target), 'size' => (int)$file['size']];
                }
                $id = bin2hex(random_bytes(16)); $first = $stored[0];
                $q = $db->prepare("INSERT INTO posts (id,title,category,description,date,file_key,file_name,file_type,file_size,thumb_key,thumb_type,created_at,status,publish_at) VALUES (?,?,?,?,?,?,?,?,?,NULL,NULL,?,?,NULL)");
                $q->execute([$id, $project['title'], $project['category'], '', $project['date'], $first['key'], $first['name'], $first['type'], $first['size'], gmdate('Y-m-d\TH:i:s\Z'), $status]);
                $ins = $db->prepare('INSERT INTO project_files (post_id,file_key,file_name,file_type,file_size) VALUES (?,?,?,?,?)');
                foreach (array_slice($stored, 1) as $f) $ins->execute([$id, $f['key'], $f['name'], $f['type'], $f['size']]);
                $created++;
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            foreach ($createdKeys as $key) remove_file($key);
            reply(['error' => $e instanceof RuntimeException ? $e->getMessage() : 'Importen mislykkedes. Ingen projekter blev gemt.'], 500);
        }
        bulk_remove_tree($dir); unset($_SESSION['bulk_uploads'][$id]);
        reply(['ok' => true, 'created' => $created, 'skipped' => $skipped]);
    }
}
