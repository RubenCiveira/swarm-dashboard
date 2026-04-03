import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const CONFIG_DIR  = path.join(os.homedir(), '.swarm');
const CONFIG_FILE = path.join(CONFIG_DIR, 'config.json');

export interface Profile {
  server: string;
  token: string;
  tokenId?: number;
  email?: string;
  name?: string;
}

interface ConfigFile {
  profiles: Record<string, Profile>;
  active_profile: string;
}

function readFile(): ConfigFile {
  if (!fs.existsSync(CONFIG_FILE)) {
    return { profiles: {}, active_profile: 'default' };
  }
  return JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf8')) as ConfigFile;
}

function writeFile(data: ConfigFile): void {
  fs.mkdirSync(CONFIG_DIR, { recursive: true });
  fs.writeFileSync(CONFIG_FILE, JSON.stringify(data, null, 2), 'utf8');
}

/** Returns the active profile, giving priority to env vars and -h override. */
export function resolveProfile(serverOverride?: string): Profile {
  const envServer = process.env['SWARM_SERVER'];
  const envToken  = process.env['SWARM_TOKEN'];

  // Full env override — no config file needed
  if (envToken && (envServer || serverOverride)) {
    return { server: (serverOverride ?? envServer)!, token: envToken };
  }

  const cfg         = readFile();
  const profileName = process.env['SWARM_PROFILE'] ?? cfg.active_profile ?? 'default';
  const profile     = cfg.profiles[profileName];

  if (!profile) {
    throw new Error(
      `No hay perfil "${profileName}" configurado. Ejecuta: swarm login -h <url>`
    );
  }

  return {
    ...profile,
    server: serverOverride ?? envServer ?? profile.server,
    token:  envToken ?? profile.token,
  };
}

export function saveProfile(profileName: string, profile: Profile): void {
  const cfg = readFile();
  cfg.profiles[profileName] = profile;
  cfg.active_profile = profileName;
  writeFile(cfg);
}

export function deleteProfile(profileName: string): void {
  const cfg = readFile();
  delete cfg.profiles[profileName];
  if (cfg.active_profile === profileName) {
    cfg.active_profile = Object.keys(cfg.profiles)[0] ?? 'default';
  }
  writeFile(cfg);
}

export function listProfiles(): Record<string, Profile> {
  return readFile().profiles;
}

export function activeProfileName(): string {
  return process.env['SWARM_PROFILE'] ?? readFile().active_profile ?? 'default';
}
