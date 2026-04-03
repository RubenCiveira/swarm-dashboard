import type { Command } from 'commander';
import chalk from 'chalk';
import open from 'open';
import { anonClient } from '../client.js';
import { saveProfile, activeProfileName } from '../config.js';

const POLL_INTERVAL_MS = 3000;
const POLL_TIMEOUT_MS  = 5 * 60 * 1000;

export function registerLogin(program: Command): void {
  program
    .command('login')
    .description('Autenticarse contra Swarm Dashboard via Google OAuth')
    .requiredOption('-h, --host <url>', 'URL del servidor Swarm Dashboard (ej: http://localhost/dev/swarm-dashboard/public)')
    .option('--profile <name>', 'Nombre del perfil a guardar', 'default')
    .action(async (opts: { host: string; profile: string }) => {
      const server = opts.host.replace(/\/$/, '');
      const client = anonClient(server);

      console.log(chalk.bold('Iniciando flujo de login...'));

      let loginRequest: { code: string; url: string; expires_in: number };
      try {
        const res = await client.get<typeof loginRequest>('/api/auth/login-request');
        loginRequest = res.data;
      } catch (err: unknown) {
        const msg = err instanceof Error ? err.message : String(err);
        console.error(chalk.red(`✗ No se pudo conectar con el servidor: ${msg}`));
        process.exit(1);
      }

      console.log('');
      console.log(`  Código de verificación: ${chalk.bold.cyan(loginRequest.code)}`);
      console.log('');
      console.log(`  Abre esta URL en tu navegador para aprobar el acceso:`);
      console.log(`  ${chalk.underline.blue(loginRequest.url)}`);
      console.log('');

      try {
        await open(loginRequest.url);
        console.log(chalk.dim('  (Se intentó abrir el navegador automáticamente)'));
      } catch {
        // ignore — the user can open it manually
      }

      console.log(chalk.dim('  Esperando aprobación...'));

      const deadline = Date.now() + POLL_TIMEOUT_MS;

      while (Date.now() < deadline) {
        await sleep(POLL_INTERVAL_MS);

        try {
          const pollRes = await client.post<{
            status: 'pending' | 'approved';
            token?: string;
            email?: string;
            name?: string;
          }>(`/api/auth/login-request/${loginRequest.code}/poll`);

          const data = pollRes.data;

          if (data.status === 'approved' && data.token) {
            saveProfile(opts.profile, {
              server,
              token:   data.token,
              email:   data.email,
              name:    data.name,
            });
            console.log('');
            console.log(chalk.green(`✓ Autenticado como ${data.name} <${data.email}>`));
            console.log(chalk.dim(`  Perfil guardado: ${opts.profile}`));
            return;
          }
        } catch (err: unknown) {
          // 410 = expirado
          if (isAxiosStatus(err, 410)) {
            console.error(chalk.red('\n✗ El código ha expirado. Vuelve a ejecutar swarm login.'));
            process.exit(1);
          }
          // otros errores de red — reintentar
        }
      }

      console.error(chalk.red('\n✗ Timeout: no se recibió aprobación en 5 minutos.'));
      process.exit(1);
    });
}

function sleep(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}

function isAxiosStatus(err: unknown, status: number): boolean {
  return (
    typeof err === 'object' &&
    err !== null &&
    'response' in err &&
    (err as { response?: { status?: number } }).response?.status === status
  );
}
