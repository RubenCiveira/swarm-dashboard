# Apache CI/CD Manager

Sistema de gestión CI/CD sencillo para servidores Apache con múltiples dominios.

## Características

- ✅ Gestión visual de aplicaciones PHP
- ✅ Integración con repositorios Git
- ✅ Editor de archivos .env integrado (Monaco Editor)
- ✅ Despliegue automático con un clic
- ✅ Logs de despliegue detallados
- ✅ Gestión de bases de datos opcionales
- ✅ Interfaz web responsive

## Instalación

### Requisitos

- PHP 7.4 o superior
- Apache con mod_rewrite habilitado
- Git
- Composer
- SQLite (incluido en PHP)

### Pasos de instalación

1. **Clonar el repositorio:**
   \`\`\`bash
   git clone <tu-repositorio> apache-cicd-manager
   cd apache-cicd-manager
   \`\`\`

2. **Ejecutar el script de instalación:**
   \`\`\`bash
   chmod +x scripts/install.sh
   ./scripts/install.sh
   \`\`\`

3. **Configurar Apache:**
   
   Crear un VirtualHost para la aplicación:
   \`\`\`apache
   <VirtualHost *:80>
       ServerName cicd.tudominio.com
       DocumentRoot /ruta/a/apache-cicd-manager/public
       DirectoryIndex index.php
       
       <Directory /ruta/a/apache-cicd-manager/public>
           AllowOverride All
           Require all granted
       </Directory>
       
       ErrorLog ${APACHE_LOG_DIR}/cicd_error.log
       CustomLog ${APACHE_LOG_DIR}/cicd_access.log combined
   </VirtualHost>
   \`\`\`

4. **Reiniciar Apache:**
   \`\`\`bash
   sudo systemctl restart apache2
   \`\`\`

## Configuración

### Variables de entorno

Crea un archivo `.env` en la raíz del proyecto con la siguiente configuración:

\`\`\`env
# Ruta base donde se desplegarán las aplicaciones
PUBLIC_PATH=/var/www/html

# Otras configuraciones opcionales...
\`\`\`

### Estructura de directorios

Con la configuración `PUBLIC_PATH=/var/www/html`, las aplicaciones se desplegarán automáticamente en:

- `ejemplo.com` → `/var/www/html/ejemplo.com`
- `mi-app.local` → `/var/www/html/mi-app.local`
- `tienda.com` → `/var/www/html/tienda.com`

## Uso

### Crear una nueva aplicación

1. Accede a la interfaz web
2. Haz clic en "Nueva Aplicación"
3. Completa los campos:
   - **Nombre**: Nombre descriptivo de tu aplicación
   - **Repositorio Git**: URL del repositorio (HTTPS)
   - **Hostname**: Dominio donde se servirá la aplicación (el directorio se calculará automáticamente)
   - **Base de datos**: Nombre de la BD si es necesaria (opcional)
   - **Archivo .env**: Configuración de entorno

El sistema calculará automáticamente el directorio de instalación como `PUBLIC_PATH/hostname`.

### Desplegar una aplicación

1. Haz clic en el botón "Desplegar" de la aplicación
2. El sistema automáticamente:
   - Clona o actualiza el repositorio
   - Instala dependencias (composer install)
   - Crea el archivo .env
   - Configura permisos
   - Actualiza el estado

### Ver logs de despliegue

- Haz clic en "Logs" para ver el historial de despliegues
- Los logs incluyen detalles de cada paso del proceso

## Estructura del proyecto

\`\`\`
apache-cicd-manager/
├── public/
│   ├── index.php          # Punto de entrada
│   └── dashboard.html     # Interfaz web
├── src/
│   ├── Database.php       # Gestión de base de datos
│   └── AppManager.php     # Lógica de aplicaciones
├── data/
│   └── apps.db           # Base de datos SQLite
├── scripts/
│   ├── setup-database.sql # Script de BD
│   └── install.sh        # Script de instalación
├── composer.json
└── README.md
\`\`\`

## API Endpoints

- `GET /api/apps` - Listar aplicaciones
- `POST /api/apps` - Crear aplicación
- `GET /api/apps/{id}` - Obtener aplicación
- `PUT /api/apps/{id}` - Actualizar aplicación
- `DELETE /api/apps/{id}` - Eliminar aplicación
- `POST /api/apps/{id}/deploy` - Desplegar aplicación
- `GET /api/apps/{id}/logs` - Obtener logs

## Configuración avanzada

### Personalizar rutas de instalación

Edita la variable `$appsPath` en `src/AppManager.php`:

\`\`\`php
private $appsPath = '/var/www/html'; // Cambia esta ruta
\`\`\`

### Configurar permisos personalizados

Modifica los comandos de permisos en el método `deployApp()`:

\`\`\`php
shell_exec("chown -R www-data:www-data {$app['directory']}");
shell_exec("chmod -R 755 {$app['directory']}");
\`\`\`

## Seguridad

- ⚠️ **Importante**: Este sistema ejecuta comandos del sistema
- Asegúrate de que solo usuarios autorizados tengan acceso
- Considera implementar autenticación adicional
- Revisa los permisos de archivos y directorios

## Desarrollo local

Para desarrollo, puedes usar el servidor integrado de PHP:

\`\`\`bash
composer start
\`\`\`

Luego accede a `http://localhost:8080`

## Contribuir

1. Fork el proyecto
2. Crea una rama para tu feature
3. Commit tus cambios
4. Push a la rama
5. Abre un Pull Request

## Licencia

MIT License - ver archivo LICENSE para detalles.
