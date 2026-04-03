import yaml from 'js-yaml';

// Shape of the API response for an app
export interface ApiApp {
  id: number;
  name: string;
  repository: string;
  hostname: string;
  directory: string;
  git_credential_id: number | null;
  database_id: number | null;
  config_maps: string | null;
  log_type: string | null;
  log_path: string | null;
  trace_type: string | null;
  trace_path: string | null;
  cron_path: string | null;
  cron_period: string | null;
  status: string;
  last_deployment: string | null;
}

// Shape of the YAML descriptor file
export interface AppDescriptor {
  version: string;
  app: {
    name: string;
    repository: string;
    hostname: string;
    directory: string;
    git_credential_id?: number | null;
    database_id?: number | null;
    log?: { type: string; path: string };
    traces?: { type: string; path: string };
    cron?: { path: string; period: string };
    config_maps?: Record<string, Record<string, string>>;
  };
}

export function apiAppToDescriptor(app: ApiApp): AppDescriptor {
  const descriptor: AppDescriptor = {
    version: '1',
    app: {
      name:       app.name,
      repository: app.repository,
      hostname:   app.hostname,
      directory:  app.directory,
    },
  };

  if (app.git_credential_id != null) descriptor.app.git_credential_id = app.git_credential_id;
  if (app.database_id != null)       descriptor.app.database_id        = app.database_id;

  if (app.log_type && app.log_path) {
    descriptor.app.log = { type: app.log_type, path: app.log_path };
  }
  if (app.trace_type && app.trace_path) {
    descriptor.app.traces = { type: app.trace_type, path: app.trace_path };
  }
  if (app.cron_path && app.cron_period) {
    descriptor.app.cron = { path: app.cron_path, period: app.cron_period };
  }
  if (app.config_maps) {
    try {
      descriptor.app.config_maps = JSON.parse(app.config_maps) as Record<string, Record<string, string>>;
    } catch {
      // leave undefined if unparseable
    }
  }

  return descriptor;
}

export function descriptorToApiPayload(d: AppDescriptor): Record<string, unknown> {
  const app = d.app;
  return {
    name:              app.name,
    repository:        app.repository,
    hostname:          app.hostname,
    directory:         app.directory,
    git_credential_id: app.git_credential_id ?? null,
    database_id:       app.database_id ?? null,
    log_type:          app.log?.type  ?? '',
    log_path:          app.log?.path  ?? '',
    trace_type:        app.traces?.type ?? '',
    trace_path:        app.traces?.path ?? '',
    cron_path:         app.cron?.path   ?? null,
    cron_period:       app.cron?.period ?? null,
    config_maps:       app.config_maps ? JSON.stringify(app.config_maps) : '',
  };
}

export function toYaml(descriptor: AppDescriptor): string {
  return yaml.dump(descriptor, { lineWidth: 120, quotingType: '"', forceQuotes: false });
}

export function fromYaml(content: string): AppDescriptor {
  return yaml.load(content) as AppDescriptor;
}

export function fromJson(content: string): AppDescriptor {
  return JSON.parse(content) as AppDescriptor;
}
