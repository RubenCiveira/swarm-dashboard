import type { Command } from 'commander';
import chalk from 'chalk';
import { clientFromProfile } from '../client.js';
import type { ApiApp } from '../descriptor.js';

export function registerAppsDeploy(appsCommand: Command): void {
  appsCommand
    .command('deploy <nombre-o-id>')
    .description('Desplegar una aplicación')
    .option('-h, --host <url>', 'URL del servidor')
    .action(async (nameOrId: string, opts: { host?: string }) => {
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

      process.stdout.write(chalk.dim(`Desplegando "${appName}"... `));

      const res    = await client.post<{ success: boolean; message?: string; logs?: string }>(`/api/apps/${appId}/deploy`);
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
        if (result.logs) {
          console.log(chalk.dim('─'.repeat(60)));
          console.log(result.logs);
        }
        process.exit(1);
      }
    });
}
