<?php
// ===================================================================
// 1. INICIO DE SESIÓN Y CONEXIÓN A BASE DE DATOS
// ===================================================================
session_start();
require_once 'conexion.php';

// ===================================================================
// 2. RECEPCIÓN Y VALIDACIÓN DE CREDENCIALES
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        echo "<script>alert('Por favor, ingresa usuario y contraseña.'); window.location.href='index.html';</script>";
        exit();
    }

    try {
        // Buscamos al usuario activo en la base de datos
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = :usr AND activo = TRUE");
        $stmt->execute(['usr' => $username]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verificamos la contraseña (soportando hashes seguros y texto plano antiguo)
        $password_valida = false;
        if ($usuario) {
            if (password_verify($password, $usuario['password_hash'])) {
                $password_valida = true;
            } elseif ($password === $usuario['password_hash']) {
                $password_valida = true; // Respaldo para texto plano
            }
        }

        if ($usuario && $password_valida) {
            
            // ===================================================================
            // 3. PROCESAMIENTO ROBUSTO DE PERMISOS
            // ===================================================================
            $permisos_db = $usuario['permisos'] ?? '';
            $lista_permisos = [];

            if (is_string($permisos_db)) {
                // Intentar decodificar como JSON
                $decodificado = json_decode($permisos_db, true);
                if (is_array($decodificado)) {
                    // Si el JSON es asociativo (ej. {"ingresos": 1}), extraemos las llaves donde el valor sea verdadero
                    if (array_keys($decodificado) !== range(0, count($decodificado) - 1)) {
                        foreach ($decodificado as $k => $v) {
                            if ($v) $lista_permisos[] = $k;
                        }
                    } else {
                        $lista_permisos = $decodificado;
                    }
                } else {
                    // Si está guardado como texto plano separado por comas
                    $lista_permisos = explode(',', $permisos_db);
                }
            } elseif (is_array($permisos_db)) {
                $lista_permisos = $permisos_db;
            }

            // ===================================================================
            // 4. CREACIÓN DE SESIÓN CON VARIABLES DE CONTROL
            // ===================================================================
            $_SESSION['usuario_id']     = $usuario['id_usuario'];
            $_SESSION['nombre_usuario'] = $usuario['nombre_completo'];
            $_SESSION['rol_id']         = $usuario['id_rol'];
            $_SESSION['clave_clinica']  = $usuario['clave_clinica'] ?? '';
            $_SESSION['permisos']       = array_map('trim', $lista_permisos);

            // Redirección al panel principal
            header("Location: dashboard.php");
            exit();
        } else {
            echo "<script>alert('Usuario o contraseña incorrectos, o la cuenta está inactiva.'); window.location.href='index.html';</script>";
            exit();
        }
    } catch (PDOException $e) {
        echo "<script>alert('Error de conexión al validar el usuario.'); window.location.href='index.html';</script>";
        exit();
    }
} else {
    header("Location: index.html");
    exit();
}
?>