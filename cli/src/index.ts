import { Command } from 'commander';
import { registerLogin }      from './commands/login.js';
import { registerLogout }     from './commands/logout.js';
import { registerAppsList }   from './commands/apps-list.js';
import { registerAppsPull }   from './commands/apps-pull.js';
import { registerAppsPush }   from './commands/apps-push.js';
import { registerAppsDeploy } from './commands/apps-deploy.js';
import { registerAppsLogs }   from './commands/apps-logs.js';
import { registerAppsDelete } from './commands/apps-delete.js';
import { registerAppsClean }  from './commands/apps-clean.js';

const program = new Command();

program
  .name('swarm')
  .description('CLI para gestionar aplicaciones en Swarm Dashboard')
  .version('1.0.0');

registerLogin(program);
registerLogout(program);

// apps sub-command tree
registerAppsList(program);

const appsCmd = program.commands.find((c) => c.name() === 'apps')!;
registerAppsPull(appsCmd);
registerAppsPush(appsCmd);
registerAppsDeploy(appsCmd);
registerAppsLogs(appsCmd);
registerAppsDelete(appsCmd);
registerAppsClean(appsCmd);

program.parse();
