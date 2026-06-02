<?php
/**
 * Siskin Projects — Investor PDF Backend
 * Place this file at the ROOT of your website on Bluehost/BigRock
 * alongside siskin.html (or index.html)
 *
 * Creates a folder: /pdfs/ to store uploaded files
 * All actions are password-protected.
 */

define('ADMIN_PASS', 'SISKIN@Admin2025');   // ← same password as in the HTML
define('PDF_DIR',    __DIR__ . '/pdfs/');   // folder where PDFs are stored
define('META_FILE',  PDF_DIR . '_meta.json'); // tracks upload info

// ── Security headers ──────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Pass');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json');

// ── Ensure /pdfs/ directory exists ───────────────────
if (!is_dir(PDF_DIR)) {
    mkdir(PDF_DIR, 0755, true);
    // Drop a .htaccess so PDFs can only be served via this script
    file_put_contents(PDF_DIR . '.htaccess',
        "Options -Indexes\n" .
        "<FilesMatch \"\\.php$\">\n  Deny from all\n</FilesMatch>\n"
    );
}

// ── Load / save metadata ──────────────────────────────
function loadMeta() {
    global $META_FILE;
    if (!file_exists(META_FILE)) return [];
    $raw = file_get_contents(META_FILE);
    return json_decode($raw, true) ?: [];
}
function saveMeta($meta) {
    global $META_FILE;
    file_put_contents(META_FILE, json_encode($meta, JSON_PRETTY_PRINT));
}

// ── Auth helper ───────────────────────────────────────
function requireAdmin() {
    $pass = $_SERVER['HTTP_X_ADMIN_PASS'] ?? ($_POST['adminPass'] ?? '');
    if ($pass !== ADMIN_PASS) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// ── Sanitise doc index ────────────────────────────────
function validIdx($idx) {
    $i = intval($idx);
    return ($i >= 0 && $i <= 12) ? $i : null;
}

$action = $_GET['action'] ?? '';

// ════════════════════════════════════════════════════
//  GET  ?action=list   — returns all uploaded doc info
// ════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    echo json_encode(['ok' => true, 'docs' => loadMeta()]);
    exit;
}

// ════════════════════════════════════════════════════
//  GET  ?action=download&idx=N   — serve the PDF file
// ════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'download') {
    $idx = validIdx($_GET['idx'] ?? '');
    if ($idx === null) { http_response_code(400); echo json_encode(['error'=>'Bad index']); exit; }
    $meta = loadMeta();
    if (empty($meta[$idx])) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }

    $filePath = PDF_DIR . $meta[$idx]['file'];
    if (!file_exists($filePath)) { http_response_code(404); echo json_encode(['error'=>'File missing']); exit; }

    // Stream the PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($meta[$idx]['name']) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=3600');
    readfile($filePath);
    exit;
}

// ════════════════════════════════════════════════════
//  POST ?action=upload   — admin uploads a PDF
// ════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    requireAdmin();

    $idx = validIdx($_POST['idx'] ?? '');
    if ($idx === null) { http_response_code(400); echo json_encode(['error'=>'Bad index']); exit; }

    if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid file received. Error code: ' . ($_FILES['pdf']['error'] ?? 'none')]);
        exit;
    }

    $file = $_FILES['pdf'];

    // Validate MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== 'application/pdf') {
        http_response_code(400); echo json_encode(['error'=>'Only PDF files allowed.']); exit;
    }

    // Max 50 MB
    if ($file['size'] > 50 * 1024 * 1024) {
        http_response_code(400); echo json_encode(['error'=>'File too large (max 50MB).']); exit;
    }

    // Generate safe filename
    $safeName = 'doc_' . $idx . '_' . time() . '.pdf';
    $dest     = PDF_DIR . $safeName;

    // Remove old file for this slot if exists
    $meta = loadMeta();
    if (!empty($meta[$idx]['file'])) {
        $old = PDF_DIR . $meta[$idx]['file'];
        if (file_exists($old)) @unlink($old);
    }

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500); echo json_encode(['error'=>'Failed to save file.']); exit;
    }

    $meta[$idx] = [
        'file' => $safeName,
        'name' => $file['name'],
        'size' => $file['size'],
        'date' => date('d M Y'),
        'ts'   => time(),
    ];
    saveMeta($meta);

    echo json_encode(['ok' => true, 'doc' => $meta[$idx]]);
    exit;
}

// ════════════════════════════════════════════════════
//  POST ?action=delete   — admin removes a PDF
// ════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    requireAdmin();

    $idx = validIdx($_POST['idx'] ?? '');
    if ($idx === null) { http_response_code(400); echo json_encode(['error'=>'Bad index']); exit; }

    $meta = loadMeta();
    if (!empty($meta[$idx]['file'])) {
        $old = PDF_DIR . $meta[$idx]['file'];
        if (file_exists($old)) @unlink($old);
        unset($meta[$idx]);
        saveMeta($meta);
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
