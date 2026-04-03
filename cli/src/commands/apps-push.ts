import fs from 'node:fs';
import path from 'node:path';
import type { Command } from 'commander';
import chalk from 'chalk';
import { clientFromProfile } from '../client.js';
import { fromYaml, fromJson, descriptorToApiPayload, type ApiApp } from '../descriptor.js';

export function registerAppsPush(appsCommand: Command): void {
  appsCommand
    .command('push <fichero>')
    .description('Crear o actualizar una aplicación a partir de un descriptor')
    .option('-h, --host <url>', 'URL del servidor')
    .option('--dry-run', 'Mostrar qué se enviaría sin ejecutar cambios')
    .option('--create-only', 'Fallar si la app ya existe')
    .option('--update-only', 'Fallar si la app no existe')
    .action(async (
      fichero: string,
      opts: { host?: string; dryRun?: boolean; createOnly?: boolean; updateOnly?: boolean }
    ) => {
      const absPath = path.resolve(fichero);
      if (!fs.existsSync(absPath)) {
        console.error(chalk.red(`✗ Fichero no encontrado: ${absPath}`));
        process.exit(1);
      }

      const content    = fs.readFileSync(absPath, 'utf8');
      const descriptor = absPath.endsWith('.json') ? fromJson(content) : fromYaml(content);

      if (!descriptor?.app?.name) {
        console.error(chalk.red('✗ El descriptor no es válido (falta app.name)'));
        process.exit(1);
      }

      const client  = clientFromProfile(opts.host);
      const payload = descriptorToApiPayload(descriptor);

      // Check if the app already exists
      const allRes   = await client.get<ApiApp[]>('/api/apps');
      const existing = allRes.data.find((a) => a.name === descriptor.app.name);

      if (existing && opts.createOnly) {
        console.error(chalk.red(`✗ La app "${descriptor.app.name}" ya existe (--create-only)`));
        process.exit(1);
      }
      if (!existing && opts.updateOnly) {
        console.error(chalk.red(`✗ La app "${descriptor.app.name}" no existe (--update-only)`));
        process.exit(1);
      }

      if (opts.dryRun) {
        const action = existing ? `PUT /api/apps/${existing.id}` : 'POST /api/apps';
        console.log(chalk.bold.cyan(`[dry-run] ${action}`));
        console.log(JSON.stringify(payload, null, 2));
        return;
      }

      if (existing) {
        await client.put(`/api/apps/${existing.id}`, payload);
        console.log(chalk.green(`✓ App "${descriptor.app.name}" actualizada (id: ${existing.id})`));
      } else {
        const createRes = await client.post<{ success: boolean; id?: number; message?: string }>('/api/apps', payload);
        if (!createRes.data.success) {
          console.error(chalk.red(`✗ Error al crear: ${createRes.data.message ?? 'desconocido'}`));
          process.exit(1);
        }
        console.log(chalk.green(`✓ App "${descriptor.app.name}" creada (id: ${createRes.data.id})`));
      }
    });
}
