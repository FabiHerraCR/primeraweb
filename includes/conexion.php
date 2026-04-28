<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function conectarBaseDatos(): mysqli
{
    $servidor = 'localhost';
    $usuario = 'root';
    $contrasena = 'FabianHerra1007.';
    $baseDatos = 'restaurante_argentina';

    $conexion = new mysqli($servidor, $usuario, $contrasena, $baseDatos);
    $conexion->set_charset('utf8mb4');

    return $conexion;
}
