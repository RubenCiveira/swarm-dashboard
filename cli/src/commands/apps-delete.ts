import type { Command } from 'commander';
import chalk from 'chalk';
import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { clientFromProfile } from '../client.js';
import type { ApiApp } from '../descriptor.js';

export function registerAppsDelete(appsCommand: Command): void {
  appsCommand
    .command('delete <nombre-o-id>')
    .description('Eliminar una aplicación')
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
        const answer = await rl.question(chalk.yellow(`¿Eliminar la app "${appName}"? [s/N] `));
        rl.close();
        if (answer.toLowerCase() !== 's') {
          console.log(chalk.dim('Operación cancelada.'));
          return;
        }
      }

      const res    = await client.delete<{ success: boolean; message?: string }>(`/api/apps/${appId}`);
      const result = res.data;

      if (result.success) {
        console.log(chalk.green(`✓ App "${appName}" eliminada.`));
      } else {
        console.error(chalk.red(`✗ Error: ${result.message ?? 'desconocido'}`));
        process.exit(1);
      }
    });
}
