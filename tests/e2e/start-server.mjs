import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const projectRoot = resolve(import.meta.dirname, '../..');
const temporaryDirectory = mkdtempSync(
    join(tmpdir(), 'engajamento-phase4-e2e-'),
);
const databasePath = join(temporaryDirectory, 'database.sqlite');

writeFileSync(databasePath, '');

const environment = {
    ...process.env,
    APP_ENV: 'testing',
    APP_DEBUG: 'false',
    APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    APP_URL: 'http://127.0.0.1:8010',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: databasePath,
    DB_URL: '',
    BCRYPT_ROUNDS: '4',
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'smtp',
    MAIL_SCHEME: '',
    MAIL_HOST: '127.0.0.1',
    MAIL_PORT: '1025',
    MAIL_USERNAME: '',
    MAIL_PASSWORD: '',
    LOG_CHANNEL: 'stderr',
    LOG_LEVEL: 'warning',
};

function runArtisan(arguments_) {
    const result = spawnSync('php', ['artisan', ...arguments_], {
        cwd: projectRoot,
        env: environment,
        stdio: 'inherit',
    });

    if (result.error) {
        throw result.error;
    }

    if (result.status !== 0) {
        throw new Error(`php artisan ${arguments_.join(' ')} failed`);
    }
}

try {
    if (
        await import('node:fs').then(({ existsSync }) =>
            existsSync(join(projectRoot, 'public/hot')),
        )
    ) {
        throw new Error(
            'A public/hot file already exists. Stop the Vite dev server before running E2E to avoid changing its target.',
        );
    }

    runArtisan(['migrate:fresh', '--force']);
    runArtisan(['db:seed', '--force', '--class=Phase4E2ESeeder']);
} catch (error) {
    rmSync(temporaryDirectory, { recursive: true, force: true });
    process.stderr.write(`${error.message}\n`);
    process.exit(1);
}

const server = spawn(
    'php',
    ['artisan', 'serve', '--host=127.0.0.1', '--port=8010'],
    {
        cwd: projectRoot,
        env: environment,
        stdio: 'inherit',
    },
);

let cleaned = false;

function cleanup() {
    if (cleaned) {
        return;
    }

    cleaned = true;
    rmSync(temporaryDirectory, { recursive: true, force: true });
}

process.on('SIGINT', () => server.kill());
process.on('SIGTERM', () => server.kill());
server.on('error', (error) => {
    process.stderr.write(`${error.message}\n`);
    cleanup();
    process.exitCode = 1;
});
server.on('exit', (code) => {
    cleanup();
    process.exitCode = code ?? 0;
});
