import type { Command } from 'commander';
import chalk from 'chalk';
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

      const cols = [
        { header: 'ID',            width: 5  },
        { header: 'Nombre',        width: 28 },
        { header: 'Hostname',      width: 30 },
        { header: 'Estado',        width: 12 },
        { header: 'Último deploy', width: 20 },
      ];

      const pad = (s: string, n: number) => s.slice(0, n).padEnd(n);
      const header = cols.map((c) => chalk.bold(pad(c.header, c.width))).join('  ');
      const sep    = cols.map((c) => '─'.repeat(c.width)).join('  ');

      console.log(header);
      console.log(chalk.dim(sep));

      for (const app of list) {
        const statusRaw = app.status ?? '—';
        const statusCol = app.status === 'active' ? chalk.green(statusRaw) : chalk.dim(statusRaw);

        console.log([
          pad(String(app.id ?? ''), cols[0].width),
          pad(app.name ?? '', cols[1].width),
          pad(app.hostname ?? '', cols[2].width),
          statusCol + ' '.repeat(Math.max(0, cols[3].width - statusRaw.length)),
          pad(app.last_deployment ?? '—', cols[4].width),
        ].join('  '));
      }
    });
}
