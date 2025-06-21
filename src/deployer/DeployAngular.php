<?php

function deployWithAngular($app)
{
    $log = "";
    $binDir = __DIR__ . '/../../bin';
    // Asegurarse de que bin/ existe
    if (!file_exists($binDir)) {
        mkdir($binDir, 0755, true);
    }
    $binDir = realpath( $binDir );
    $nodeBin = "{$binDir}/node";
    $npmBin = "{$binDir}/npm";

    // Descargar Node.js si no existe
    if (!file_exists($nodeBin) || !file_exists($npmBin)) {
        $log .= "Descargando Node.js y npm...\n";
        [$outputname, $nodeUrl] = getNodeJsDownloadUrl('22.16.0');
        $basename = basename( $nodeUrl );
        $archive = "{$binDir}/{$basename}";

        file_put_contents($archive, file_get_contents($nodeUrl));
        $log .= "Archivo descargado...\n";

        $extractCmd = "cd {$binDir} && tar -xf {$basename} && rm {$basename}";
        shell_exec($extractCmd);

        $extractedNode = "{$binDir}/{$outputname}/bin/node";
        $extractedNpm = "{$binDir}/{$outputname}/bin/npm";

        // Crear enlaces simbólicos
        symlink("{$extractedNode}", $nodeBin);
        symlink("{$extractedNpm}", $npmBin);

        $log .= "Node y npm instalados localmente en bin/\n";
    }
    putenv("PATH=$binDir:" . getenv("PATH"));

    // Verificar que angular.json existe
    if (!file_exists($app['directory'] . '/angular.json')) {
        return "No se encontró angular.json en {$app['directory']}";
    }

    $log .= "Instalando dependencias Angular con npm...\n";
    $cmd = "cd {$app['directory']} && {$nodeBin} {$npmBin} install --no-audit --no-fund 2>&1";
    $log .= shell_exec($cmd);

    $log .= "Compilando proyecto con ng build...\n";

    // Usar npx local si existe
    $ngCmd = "{$nodeBin} {$npmBin} exec ng build --configuration=production";
    $buildOutput = shell_exec("cd {$app['directory']} && {$ngCmd} 2>&1");
    $log .= $buildOutput;

    // Obtener outputPath desde angular.json
    $angularJson = json_decode(file_get_contents($app['directory'] . '/angular.json'), true);
    $firstProject = array_key_first($angularJson['projects']);
    $outputPath = $angularJson['projects'][$firstProject]['architect']['build']['options']['outputPath'] ?? null;
    if (!$outputPath) {
        return "No se pudo determinar el directorio de salida desde angular.json";
    }

    $distDir = "{$app['directory']}/{$outputPath}";
    if( file_exists($distDir.'/browser/index.html') ) {
        $distDir = $distDir . '/browser';
    }
    $publicDir = "{$app['directory']}/public";

    // Copiar al directorio public
    if (file_exists($publicDir)) {
        shell_exec("rm -rf {$publicDir}");
    }
    mkdir($publicDir, 0755, true);
    shell_exec("cp -r {$distDir}/* {$publicDir}/");

    $log .= "Archivos copiados a directorio public/\n";

    // Limpiar node_modules para ahorrar espacio
    shell_exec("rm -rf {$app['directory']}/node_modules");
    shell_exec("rm -rf {$app['directory']}/{$outputPath}");
    $log .= "Limpieza de node_modules completada\n";

    return $log;
}

function getNodeJsDownloadUrl(string $version = '18.20.2'): array
{
    $os = PHP_OS_FAMILY;
    $arch = php_uname('m');

    // Detectar plataforma
    if ($os === 'Linux') {
        $platform = 'linux';
    } elseif ($os === 'Darwin') {
        $platform = 'darwin';
    } elseif ($os === 'Windows') {
        $platform = 'win';
    } else {
        throw new \RuntimeException("Sistema operativo no soportado: $os");
    }

    // Detectar arquitectura
    switch ($arch) {
        case 'x86_64':
        case 'amd64':
            $archName = 'x64';
            break;
        case 'aarch64':
        case 'arm64':
            $archName = 'arm64';
            break;
        case 'armv7l':
            $archName = 'armv7l';
            break;
        default:
            throw new \RuntimeException("Arquitectura no soportada: $arch");
    }

    // Construir nombre del archivo
    $ext = ($platform === 'win') ? 'zip' : 'tar.xz';
    $name = "node-v{$version}-{$platform}-{$archName}";
    $filename = "{$name}.{$ext}";
    return [$name, "https://nodejs.org/dist/v{$version}/{$filename}"];
}