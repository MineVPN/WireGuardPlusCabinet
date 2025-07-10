<?php
session_start(); // Не забудьте начать сессию

if ($_SESSION["authenticated"] == true){
	include "cabinet.php"; // Исправлено incude на include
}
else{
	include "login.php"; // Исправлено incude на include
}
?>
