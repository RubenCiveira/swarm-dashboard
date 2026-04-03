import axios, { type AxiosInstance, type AxiosError } from 'axios';
import chalk from 'chalk';
import { resolveProfile, type Profile } from './config.js';

export function makeClient(profile: Profile): AxiosInstance {
  const base = profile.server.replace(/\/$/, '');

  const client = axios.create({
    baseURL: base,
    headers: {
      Authorization: `Bearer ${profile.token}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    // Allow self-signed certs in local dev
    httpsAgent: undefined,
  });

  client.interceptors.response.use(
    (r) => r,
    (err: AxiosError) => {
      if (err.response?.status === 401) {
        console.error(chalk.red('✗ No autenticado o token expirado. Ejecuta: swarm login -h <url>'));
        process.exit(1);
      }
      return Promise.reject(err);
    }
  );

  return client;
}

/** Builds a client from the active profile, applying an optional server override. */
export function clientFromProfile(serverOverride?: string): AxiosInstance {
  const profile = resolveProfile(serverOverride);
  return makeClient(profile);
}

/** Unauthenticated client — only for login-request flow. */
export function anonClient(server: string): AxiosInstance {
  return axios.create({
    baseURL: server.replace(/\/$/, ''),
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  });
}
