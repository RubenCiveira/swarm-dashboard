import { Command } from 'commander';
import { registerLogin }    from './commands/login.js';
import { registerLogout }   from './commands/logout.js';
import { registerAppsList } from './commands/apps-list.js';
import { registerAppsPull } from './commands/apps-pull.js';
import { registerAppsPush } from './commands/apps-push.js';

const program = new Command();

program
  .name('swarm')
  .description('CLI para gestionar aplicaciones en Swarm Dashboard')
  .version('1.0.0');

registerLogin(program);
registerLogout(program);

// apps sub-command tree
// registerAppsList creates the `apps` sub-command and returns it so pull/push can attach to it
registerAppsList(program);

// pull & push attach to the same `apps` sub-command already created by registerAppsList
const appsCmd = program.commands.find((c) => c.name() === 'apps')!;
registerAppsPull(appsCmd);
registerAppsPush(appsCmd);

program.parse();
