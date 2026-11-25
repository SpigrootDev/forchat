<?php
declare(strict_types=1);

/* Secure ZIP importer. Expected layout: dansk/Project name/files or mediegrafiker/Project name/files. */
function bulk_remove_tree(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) if ($item !== '.' && $item !== '..') bulk_remove_tree($path . DIRECTORY_SEPARATOR . $item);
    @rmdir($path);
}
function bulk_dir(string $id): string {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) reply(['error' => 'Ugyldigt upload-id.'], 422);
    $dir = DATA_DIR . '/imports/' . $id;
    if (!is_dir($dir) || empty($_SESSION['bulk_uploads'][$id])) reply(['error' => 'Uploaden findes ikke eller er udløbet. Start igen.'], 404);
    return $dir;
}
function bulk_mime(string $path): string {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    $images = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, array_merge($images, ['application/pdf']), true) ||
        (in_array($mime, $images, true) && !@getimagesize($path))) return 'application/octet-stream';
    return $mime;
}
function bulk_handle(string $action, PDO $db): void {
    $actions = ['bulk_start', 'bulk_chunk', 'bulk_prepare', 'bulk_commit', 'bulk_cancel'];
    if (!in_array($action, $actions, true)) return;
    if (empty($_SESSION['admin'])) reply(['error' => 'Log ind for at fortsætte.'], 401);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(['error' => 'Brug POST.'], 405);
    if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) reply(['error' => 'Genindlæs siden og prøv igen.'], 403);

    if ($action === 'bulk_start') {
        if (!class_exists('ZipArchive')) reply(['error' => 'PHP ZipArchive mangler. Installer php-zip og genstart Apache.'], 503);
        $root = DATA_DIR . '/imports';
        if (!is_dir($root) && !mkdir($root, 0700, true)) reply(['error' => 'Kunne ikke oprette importmappe.'], 500);
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $old) if (filemtime($old) < time() - 86400) bulk_remove_tree($old);
        $id = bin2hex(random_bytes(16)); $dir = $root . '/' . $id;
        if (!mkdir($dir, 0700)) reply(['error' => 'Kunne ikke starte importen.'], 500);
        $_SESSION['bulk_uploads'][$id] = time();
        reply(['ok' => true, 'uploadId' => $id, 'chunkSize' => 6 * 1024 * 1024, 'maxBytes' => 10 * 1024 * 1024 * 1024]);
    }

    $id = trim((string)($_POST['upload_id'] ?? ''));
    $dir = bulk_dir($id);
    if ($action === 'bulk_cancel') {
        bulk_remove_tree($dir); unset($_SESSION['bulk_uploads'][$id]); reply(['ok' => true]);
    }
    if ($action === 'bulk_chunk') {
        $index = filter_var($_POST['chunk_index'] ?? null, FILTER_VALIDATE_INT);
        if ($index === false || $index < 0 || $index > 2047) reply(['error' => 'Ugyldigt chunk-nummer.'], 422);
        if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['chunk']['tmp_name'])) reply(['error' => 'Upload-del mislykkedes. Kontrollér PHP upload-grænsen.'], 413);
        if ($_FILES['chunk']['size'] < 1 || $_FILES['chunk']['size'] > 7 * 1024 * 1024) reply(['error' => 'Upload-delens størrelse er ugyldig.'], 422);
        $target = $dir . '/' . $index . '.part';
        if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $target)) reply(['error' => 'Kunne ikke gemme upload-del. Kontrollér diskplads og rettigheder.'], 500);
        chmod($target, 0600); reply(['ok' => true]);
    }
    if ($action === 'bulk_prepare') {
        @set_time_limit(0);
        $count = filter_var($_POST['chunk_count'] ?? null, FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > 2048) reply(['error' => 'Ugyldigt antal upload-dele.'], 422);
        $archivePath = $dir . '/archive.zip'; $out = fopen($archivePath, 'wb');
        if (!$out) reply(['error' => 'Kunne ikke samle ZIP-filen.'], 500);
        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $part = $dir . '/' . $i . '.part';
            if (!is_file($part)) { fclose($out); reply(['error' => 'En upload-del mangler.'], 422); }
            $total += filesize($part);
            if ($total > 10 * 1024 * 1024 * 1024) { fclose($out); reply(['error' => 'ZIP-filen er større end 10 GB.'], 413); }
            $in = fopen($part, 'rb'); stream_copy_to_stream($in, $out); fclose($in); unlink($part);
        }
        fclose($out); chmod($archivePath, 0600);
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) reply(['error' => 'Filen er ikke en gyldig ZIP-fil.'], 422);
        $extractRoot = $dir . '/extracted'; mkdir($extractRoot, 0700);
        $projects = []; $expanded = 0; $fileCount = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat) continue;
                $raw = (string)$stat['name'];
                if ($raw === '' || str_ends_with($raw, '/')) continue;
                $normalized = str_replace('\\', '/', $raw);
                if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized)) continue;
                $parts = explode('/', $normalized);
                if (count($parts) < 3 || in_array('..', $parts, true) || in_array('.', $parts, true)) continue;
                $category = strtolower(trim($parts[0]));
                // Accept both folder names for the Mediegrafiker category.
                if ($category === 'mediegrafik') $category = 'mediegrafiker';
                if (!in_array($category, ['dansk', 'mediegrafiker'], true)) continue;
                $projectFolder = trim($parts[1]);
                $relative = implode('/', array_slice($parts, 2));
                if ($projectFolder === '' || $relative === '' || in_array('__MACOSX', $parts, true) || basename($relative) === '.DS_Store' || str_starts_with(basename($relative), '._')) continue;
                $opsys = 0; $attr = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) throw new RuntimeException('ZIP-filen indeholder et symbolsk link, som ikke kan importeres.');
                $size = (int)$stat['size']; $expanded += $size; $fileCount++;
                if ($fileCount > 10000 || $expanded > 25 * 1024 * 1024 * 1024) throw new RuntimeException('ZIP-filen indeholder over 10.000 filer eller mere end 25 GB udpakket data.');
                $key = $category . "\n" . $projectFolder;
                $folder = $extractRoot . '/' . $category . '/' . hash('sha256', $projectFolder);
                if (!is_dir($folder) && !mkdir($folder, 0700, true)) throw new RuntimeException('Kunne ikke oprette midlertidig projektmappe.');
                $target = $folder . '/' . bin2hex(random_bytes(12)) . '.bin';
                $stream = $zip->getStream($raw);
                if (!$stream) throw new RuntimeException('En fil i ZIP-arkivet kunne ikke læses.');
                $dest = fopen($target, 'wb');
                if (!$dest) { fclose($stream); throw new RuntimeException('Kunne ikke udpakke en fil.'); }
                $copied = stream_copy_to_stream($stream, $dest); fclose($stream); fclose($dest);
                if ($copied !== $size) throw new RuntimeException('En fil blev udpakket ufuldstændigt.');
                chmod($target, 0600);
                $mtime = (int)($stat['mtime'] ?? 0);
                if (!isset($projects[$key])) $projects[$key] = ['category' => $category, 'title' => substr($projectFolder, 0, 120), 'date' => $mtime > 946684800 ? gmdate('Y-m-d', $mtime) : date('Y-m-d'), 'files' => []];
                if ($mtime > 946684800 && gmdate('Y-m-d', $mtime) > $projects[$key]['date']) $projects[$key]['date'] = gmdate('Y-m-d', $mtime);
                $projects[$key]['files'][] = ['path' => $target, 'name' => basename($relative), 'size' => $size];
            }
            if (!$projects) throw new RuntimeException('Ingen projekter fundet. Brug mapperne dansk/Projektnavn/ og mediegrafiker/Projektnavn/.');
            $summary = [];
            foreach ($projects as $key => &$project) {
                $q = $db->prepare('SELECT COUNT(*) FROM posts WHERE title=? AND category=?');
                $q->execute([$project['title'], $project['category']]);
                $project['duplicate'] = (int)$q->fetchColumn() > 0;
                $summary[] = ['title' => $project['title'], 'category' => $project['category'], 'date' => $project['date'], 'fileCount' => count($project['files']), 'size' => array_sum(array_column($project['files'], 'size')), 'duplicate' => $project['duplicate']];
            }
            unset($project);
            if (file_put_contents($dir . '/manifest.dat', serialize($projects), LOCK_EX) === false) throw new RuntimeException('Kunne ikke gemme importoversigten.');
            $zip->close();
            reply(['ok' => true, 'projects' => $summary, 'projectCount' => count($summary), 'fileCount' => $fileCount, 'expandedBytes' => $expanded]);
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
        $skipDuplicates = ($_POST['skip_duplicates'] ?? '1') !== '0';
        $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
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
                    $base = realpath($dir . '/extracted'); $actual = realpath($file['path']);
                    if (!$actual || !$base || !str_starts_with($actual, $base . DIRECTORY_SEPARATOR) || !is_file($actual)) throw new RuntimeException('En midlertidig importfil mangler eller er ugyldig.');
                    $key = bin2hex(random_bytes(24)); $target = DATA_DIR . '/' . $key;
                    if (!copy($actual, $target)) throw new RuntimeException('Kunne ikke gemme en projektfil. Kontrollér diskplads.');
                    chmod($target, 0600); $createdKeys[] = $key;
                    $stored[] = ['key' => $key, 'name' => substr((string)$file['name'], 0, 200), 'type' => bulk_mime($target), 'size' => (int)$file['size']];
                }
                $id = bin2hex(random_bytes(16)); $first = $stored[0];
                $q = $db->prepare('INSERT INTO posts (id,title,category,description,date,file_key,file_name,file_type,file_size,thumb_key,thumb_type,created_at,status,publish_at) VALUES (?,?,?,?,?,?,?,?,?,NULL,NULL,?,?,NULL)');
                $q->execute([$id, $project['title'], $project['category'], '', $project['date'], $first['key'], $first['name'], $first['type'], $first['size'], gmdate('Y-m-d\TH:i:s\Z'), $status]);
                $ins = $db->prepare('INSERT INTO project_files (post_id,file_key,file_name,file_type,file_size) VALUES (?,?,?,?,?)');
                foreach (array_slice($stored, 1) as $file) $ins->execute([$id, $file['key'], $file['name'], $file['type'], $file['size']]);
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
