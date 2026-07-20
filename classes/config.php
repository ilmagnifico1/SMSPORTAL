<?php

// Compatibilità temporanea con codice legacy. Le credenziali sono gestite da Connection.
$pdo = Connection::connect()->getConn();
$link = null;
