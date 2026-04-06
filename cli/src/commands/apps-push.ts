import fs from 'node:fs';
import path from 'node:path';
import type { Command } from 'commander';
import chalk from 'chalk';
import { clientFromProfile } from '../client.js';
import { fromYaml, fromJson, descriptorToApiPayload, type ApiApp, type ApiWorkspace } from '../descriptor.js';
import type { AxiosInstance } from 'axios';

/**
 * Resolves a workspace path (e.g. "personal" or "personal/apps") to its ID,
 * creating any missing levels along the way. Returns null if path is empty.
 */
async function resolveWorkspacePath(
  client: AxiosInstance,
  workspacePath: string | undefined,
): Promise<number | null> {
  if (!workspacePath?.trim()) return null;

  const parts = workspacePath.trim().split('/').map((p) => p.trim()).filter(Boolean);

  // Fetch current workspace tree
  const treeRes = await client.get<ApiWorkspace[]>('/api/workspaces');
  let nodes: ApiWorkspace[] = treeRes.data;

  let parentId: number | null = null;

  for (const part of parts) {
    // Look for a node with this name at the current level
    const found = nodes.find((n) => n.name.toLowerCase() === part.toLowerCase());

    if (found) {
      parentId = found.id;
      nodes = found.children ?? [];
    } else {
      // Create the missing workspace
      const createRes = await client.post<{ success: boolean; id?: number; message?: string }>(
        '/api/workspaces',
        { name: part, parent_id: parentId ?? null, icon: 'folder', color: '#38bdf8' },
      );
      if (!createRes.data.success || createRes.data.id == null) {
        throw new Error(`No se pudo crear el workspace "${part}": ${createRes.data.message ?? 'desconocido'}`);
      }
      console.log(chalk.dim(`  ↳ Workspace "${part}" creado (id: ${createRes.data.id})`));
      parentId = createRes.data.id;
      nodes = [];  // new workspace has no children yet
    }
  }

  return parentId;
}

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

      const client = clientFromProfile(opts.host);

      // Resolve workspace path → ID (create levels as needed)
      let workspaceId: number | null = null;
      if (descriptor.app.workspace) {
        if (opts.dryRun) {
          console.log(chalk.dim(`[dry-run] workspace: "${descriptor.app.workspace}" → se resolvería/crearía`));
        } else {
          workspaceId = await resolveWorkspacePath(client, descriptor.app.workspace);
          if (workspaceId !== null) {
            console.log(chalk.dim(`  workspace "${descriptor.app.workspace}" → id: ${workspaceId}`));
          }
        }
      }

      const payload = descriptorToApiPayload(descriptor, workspaceId);

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
        const createRes = await client.post<{ success: boolean; app_id?: number; message?: string }>('/api/apps', payload);
        if (!createRes.data.success) {
          console.error(chalk.red(`✗ Error al crear: ${createRes.data.message ?? 'desconocido'}`));
          process.exit(1);
        }
        console.log(chalk.green(`✓ App "${descriptor.app.name}" creada (id: ${createRes.data.app_id})`));
      }
    });
}
