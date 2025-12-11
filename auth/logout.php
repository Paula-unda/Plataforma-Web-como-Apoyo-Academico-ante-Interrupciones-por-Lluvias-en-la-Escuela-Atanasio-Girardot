<?php
session_start();
session_destroy();
header('Location: login.php?mensaje=Ha+cerrado+sesión.+¡Hasta+pronto!');
exit();