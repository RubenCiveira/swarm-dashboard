import type { Command } from 'commander';
import chalk from 'chalk';
import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { clientFromProfile } from '../client.js';
import type { ApiApp } from '../descriptor.js';

export function registerAppsClean(appsCommand: Command): void {
  appsCommand
    .command('clean <nombre-o-id>')
    .description('Limpiar ficheros locales de una aplicación en el servidor')
    .option('-h, --host <url>', 'URL del servidor')
    .option('-y, --yes', 'Confirmar sin interacción')
    .action(async (nameOrId: string, opts: { host?: string; yes?: boolean }) => {
      const client = clientFromProfile(opts.host);

      let appId: number;
      let appName: string;
      if (/^\d+$/.test(nameOrId)) {
        appId = parseInt(nameOrId, 10);
        appName = nameOrId;
      } else {
        const allRes = await client.get<ApiApp[]>('/api/apps');
        const found  = allRes.data.find((a) => a.name === nameOrId);
        if (!found) {
          console.error(chalk.red(`✗ No se encontró ninguna app con el nombre "${nameOrId}"`));
          process.exit(1);
        }
        appId   = found.id;
        appName = found.name;
      }

      if (!opts.yes) {
        const rl     = readline.createInterface({ input, output });
        const answer = await rl.question(chalk.yellow(`¿Limpiar ficheros locales de "${appName}"? [s/N] `));
        rl.close();
        if (answer.toLowerCase() !== 's') {
          console.log(chalk.dim('Operación cancelada.'));
          return;
        }
      }

      process.stdout.write(chalk.dim(`Limpiando "${appName}"... `));

      const res    = await client.post<{ success: boolean; message?: string; logs?: string }>(`/api/apps/${appId}/clean`);
      const result = res.data;

      if (result.success) {
        console.log(chalk.green('✓ Completado'));
        if (result.logs) {
          console.log(chalk.dim('─'.repeat(60)));
          console.log(result.logs);
        }
      } else {
        console.log(chalk.red('✗'));
        console.error(chalk.red(`Error: ${result.message ?? 'desconocido'}`));
        process.exit(1);
      }
    });
}
