import type { Command } from 'commander';
import chalk from 'chalk';
import Table from 'cli-table3';
import { clientFromProfile } from '../client.js';
import type { ApiApp } from '../descriptor.js';

export function registerAppsList(program: Command): void {
  const apps = program.command('apps').description('Gestionar aplicaciones');

  apps
    .command('list')
    .description('Listar todas las aplicaciones')
    .option('-h, --host <url>', 'URL del servidor')
    .option('--json', 'Salida en formato JSON')
    .action(async (opts: { host?: string; json?: boolean }) => {
      const client = clientFromProfile(opts.host);
      const res    = await client.get<ApiApp[]>('/api/apps');
      const list   = res.data;

      if (opts.json) {
        console.log(JSON.stringify(list, null, 2));
        return;
      }

      if (!list.length) {
        console.log(chalk.dim('No hay aplicaciones registradas.'));
        return;
      }

      const table = new Table({
        head: ['ID', 'Nombre', 'Hostname', 'Estado', 'Último deploy'].map((h) => chalk.bold(h)),
        style: { head: [] },
      });

      for (const app of list) {
        const status = app.status === 'active'
          ? chalk.green(app.status)
          : chalk.dim(app.status ?? '—');
        table.push([
          String(app.id),
          app.name,
          app.hostname,
          status,
          app.last_deployment ?? chalk.dim('—'),
        ]);
      }

      console.log(table.toString());
    });
}
