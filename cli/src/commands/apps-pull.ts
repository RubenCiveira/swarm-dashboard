import fs from 'node:fs';
import type { Command } from 'commander';
import chalk from 'chalk';
import { clientFromProfile } from '../client.js';
import { apiAppToDescriptor, toYaml, type ApiApp } from '../descriptor.js';

export function registerAppsPull(appsCommand: Command): void {
  appsCommand
    .command('pull <nombre-o-id>')
    .description('Descargar el descriptor de una aplicación')
    .option('-h, --host <url>', 'URL del servidor')
    .option('-o, --output <fichero>', 'Fichero de salida (usa "-" para stdout)')
    .option('--format <fmt>', 'Formato de salida: yaml | json', 'yaml')
    .action(async (nameOrId: string, opts: { host?: string; output?: string; format: string }) => {
      const client = clientFromProfile(opts.host);

      // Resolve name → id if not numeric
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

      const res = await client.get<ApiApp>(`/api/apps/${appId}`);
      const app = res.data;

      const descriptor = apiAppToDescriptor(app);

      let content: string;
      if (opts.format === 'json') {
        content = JSON.stringify(descriptor, null, 2);
      } else {
        content = toYaml(descriptor);
      }

      const outputPath = opts.output ?? `${app.name}.swarm.yaml`;

      if (outputPath === '-') {
        process.stdout.write(content);
      } else {
        fs.writeFileSync(outputPath, content, 'utf8');
        console.log(chalk.green(`✓ Descriptor guardado en ${outputPath}`));
      }
    });
}
