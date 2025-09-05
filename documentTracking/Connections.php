<?php
    $doc_log_db=mysqli_connect("localhost:3306", "root", "", "doc_log_db");
    if(mysqli_connect_errno()){
        echo"Failed to connect in Mysql". mysqli_connect_errno();
    }else{
        echo"";
    }
?>
    