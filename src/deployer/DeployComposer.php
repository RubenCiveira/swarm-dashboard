<?php

function deployWithComposer($app)
{
    $logContent = "";
    $phpExecutable = ConfigManager::getPhpExecutable();
    $composerPath = __DIR__ . '/../composer.phar';
    if (!file_exists($composerPath)) {
        $logContent .= "Descargando Composer...\n";
        $composerUrl = 'https://getcomposer.org/download/latest-stable/composer.phar';

        // Usar cURL si está disponible, sino file_get_contents
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $composerUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            $composerContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $composerContent !== false) {
                file_put_contents($composerPath, $composerContent);
                chmod($composerPath, 0755);
                $logContent .= "Composer descargado exitosamente con cURL\n";
            } else {
                $logContent .= "Error descargando Composer con cURL (HTTP: {$httpCode})\n";
                $composerPath = null;
            }
        } else {
            $composerContent = file_get_contents($composerUrl);
            if ($composerContent !== false) {
                file_put_contents($composerPath, $composerContent);
                chmod($composerPath, 0755);
                $logContent .= "Composer descargado exitosamente\n";
            } else {
                $logContent .= "Error descargando Composer\n";
                $composerPath = null;
            }
        }
    }

    // Instalar dependencias si existe composer.json
    // if (file_exists($app['directory'] . '/composer.json')) {
    if ($composerPath && file_exists($composerPath)) {
        // Usar Composer descargado
        $composerCmd = "{$phpExecutable} {$composerPath} install --no-dev --optimize-autoloader --no-interaction";
        $output = shell_exec("cd {$app['directory']} && {$composerCmd} 2>&1");
        $logContent .= "Instalando dependencias con Composer descargado:\n$output\n";
    } else {
        // Fallback a Composer del sistema
        $composerCmd = "composer install --no-dev --optimize-autoloader --no-interaction";
        $output = shell_exec("cd {$app['directory']} && {$composerCmd} 2>&1");
        $logContent .= "Instalando dependencias con Composer del sistema:\n$output\n";
    }
    return $logContent;
}
