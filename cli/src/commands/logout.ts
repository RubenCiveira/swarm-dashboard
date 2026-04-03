import type { Command } from 'commander';
import chalk from 'chalk';
import { clientFromProfile } from '../client.js';
import { resolveProfile, deleteProfile, activeProfileName } from '../config.js';

export function registerLogout(program: Command): void {
  program
    .command('logout')
    .description('Cerrar sesión y revocar el token de API')
    .option('-h, --host <url>', 'URL del servidor (sobrescribe el perfil guardado)')
    .option('--profile <name>', 'Perfil a cerrar sesión')
    .action(async (opts: { host?: string; profile?: string }) => {
      const profileName = opts.profile ?? activeProfileName();

      let profile;
      try {
        profile = resolveProfile(opts.host);
      } catch (err: unknown) {
        // No profile saved — just inform and exit cleanly
        const msg = err instanceof Error ? err.message : String(err);
        console.log(chalk.yellow(`⚠ ${msg}`));
        return;
      }

      if (profile.tokenId) {
        try {
          const client = clientFromProfile(opts.host);
          await client.delete(`/api/auth/tokens/${profile.tokenId}`);
        } catch {
          // If revocation fails (e.g. already expired) still clean up locally
        }
      }

      deleteProfile(profileName);
      console.log(chalk.green(`✓ Sesión cerrada (perfil: ${profileName})`));
    });
}
