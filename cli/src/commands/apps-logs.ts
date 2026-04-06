import type { Command } from 'commander';
import chalk from 'chalk';
import { clientFromProfile } from '../client.js';
import type { ApiApp } from '../descriptor.js';

interface DeployLog {
  deployment_date: string;
  log_content: string;
}

export function registerAppsLogs(appsCommand: Command): void {
  appsCommand
    .command('logs <nombre-o-id>')
    .description('Ver logs de despliegue de una aplicación')
    .option('-h, --host <url>', 'URL del servidor')
    .option('-n, --last <n>', 'Mostrar solo los últimos N despliegues', '1')
    .action(async (nameOrId: string, opts: { host?: string; last: string }) => {
      const client = clientFromProfile(opts.host);

      let appId: number;
      if (/^\d+$/.test(nameOrId)) {
        appId = parseInt(nameOrId, 10);
      } else {
        const allRes = await client.get<ApiApp[]>('/api/apps');
        const found  = allRes.data.find((a) => a.name === nameOrId);
        if (!found) {
          console.error(chalk.red(`✗ No se encontró ninguna app con el nombre "${nameOrId}"`));
          process.exit(1);
        }
        appId = found.id;
      }

      const res    = await client.get<{ logs: DeployLog[] }>(`/api/apps/${appId}/logs`);
      const logs   = res.data.logs ?? [];

      if (!logs.length) {
        console.log(chalk.dim('No hay logs de despliegue disponibles.'));
        return;
      }

      const limit  = parseInt(opts.last, 10);
      const shown  = logs.slice(-limit);

      for (const entry of shown) {
        const date = new Date(entry.deployment_date).toLocaleString();
        console.log(chalk.bold.cyan(`── Despliegue: ${date} `) + chalk.dim('─'.repeat(30)));
        console.log(entry.log_content);
      }
    });
}
