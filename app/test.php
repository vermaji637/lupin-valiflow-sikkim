<?php
try {
    $conn = new PDO("sqlsrv:Server=localhost;Database=lupin", "sa", "Cwlpass098@@");
    echo "Connected successfully";
} catch (Exception $e) {
    echo $e->getMessage();
}
