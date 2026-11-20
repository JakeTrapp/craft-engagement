import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

/**
 * Builds a fresh, seeded Craft site for the browser suite:
 *  1. materialize testbench/ and start DDEV (MySQL only is used by the browser run)
 *  2. recreate the identity-marked db_test inside DDEV (refuses any other database)
 *  3. install Craft + Engagement into db_test with host PHP, then seed fixtures
 *  4. serve testbench/web with PHP's built-in server on 127.0.0.1:8098
 */
export default async function globalSetup() {
  const root = process.cwd();
  const lockPath = path.join(root, 'testbench', '.test-run.lock');
  fs.mkdirSync(path.join(root, 'testbench'), { recursive: true });
  try {
    fs.mkdirSync(lockPath);
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'EEXIST') {
      const ownerFile = path.join(lockPath, 'owner');
      const owner = fs.existsSync(ownerFile) ? fs.readFileSync(ownerFile, 'utf8').trim() : 'owner unknown';
      throw new Error(`Another Engagement test suite owns ${lockPath} (${owner}). Remove it only after confirming that process stopped.`);
    }
    throw error;
  }
  fs.writeFileSync(path.join(lockPath, 'owner'), `browser pid ${process.pid}\n`);

  let server: ChildProcess | undefined;
  const fixturePath = process.env.ENGAGEMENT_BROWSER_FIXTURE ?? path.join(os.tmpdir(), 'engagement-browser-fixture.json');
  const releaseLock = () => {
    fs.rmSync(path.join(lockPath, 'owner'), { force: true });
    fs.rmSync(lockPath, { recursive: true, force: true });
  };

  try {
    const run = (cmd: string, args: string[], env?: NodeJS.ProcessEnv) =>
      execFileSync(cmd, args, { cwd: root, env: env ?? process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] });

    run('bash', ['testbench/bin/materialize']);
    run('ddev', ['start']);
    if (!fs.existsSync(path.join(root, 'testbench/vendor/autoload.php'))) {
      run('ddev', ['composer', 'install', '--no-interaction']);
    }
    run('ddev', ['exec', '-d', '/var/www/html/testbench', 'php', 'bin/create-test-database.php']);

    const webPort = process.env.ENGAGEMENT_BROWSER_PORT ?? '8098';
    const dbPort = process.env.ENGAGEMENT_BROWSER_DB_PORT ?? resolveDdevDatabasePort();
    // Generated per run and only written to the temporary fixture file; never committed.
    const password = crypto.randomBytes(18).toString('base64url');
    const env: NodeJS.ProcessEnv = {
      ...process.env,
      CRAFT_DB_SERVER: '127.0.0.1',
      CRAFT_DB_PORT: dbPort,
      CRAFT_DB_DATABASE: 'db_test',
      CRAFT_DB_USER: 'db',
      CRAFT_DB_PASSWORD: 'db',
      CRAFT_TEST_DB_DATABASE: 'db_test',
      ENGAGEMENT_TEST_DB_IDENTITY: 'craft-engagement-testbench:db_test',
      PRIMARY_SITE_URL: `http://127.0.0.1:${webPort}`,
      CRAFT_ENVIRONMENT: 'dev',
      CRAFT_DEV_MODE: 'true',
      CRAFT_ALLOW_ADMIN_CHANGES: 'true',
      CRAFT_SECURITY_KEY: 'engagement-testbench-not-for-production',
      ENGAGEMENT_BROWSER_PASSWORD: password,
    };

    assertMarkedDatabase(root, env);
    run('php', ['testbench/craft', 'install/craft', '--interactive=0',
      '--username=admin', `--password=${password}`, '--email=admin@engagement-testbench.test',
      '--site-name=Engagement Testbench', '--site-url=$PRIMARY_SITE_URL', '--language=en-US'], env);
    run('php', ['testbench/craft', 'plugin/install', 'engagement', '--interactive=0'], env);
    const seedOutput = run('php', ['testbench/craft', 'engagement-testbench/seed/browser', '--interactive=0'], env);
    const jsonLine = seedOutput.split(/\r?\n/).map(l => l.trim()).filter(l => l.startsWith('{')).pop();
    if (!jsonLine) throw new Error(`Seed command returned no fixture JSON:\n${seedOutput}`);
    fs.writeFileSync(fixturePath, jsonLine + '\n', { mode: 0o600 });
    process.env.ENGAGEMENT_BROWSER_FIXTURE = fixturePath;

    server = spawn('php', ['-S', `127.0.0.1:${webPort}`, '-t', 'testbench/web', 'testbench/web/index.php'], {
      cwd: root, env, stdio: ['ignore', 'pipe', 'pipe'],
    });
    let serverOutput = '';
    server.stdout?.on('data', c => { serverOutput += String(c); });
    server.stderr?.on('data', c => { serverOutput += String(c); });

    let ready = false;
    for (let attempt = 0; attempt < 100 && server.exitCode === null; attempt++) {
      try {
        if ((await fetch(`http://127.0.0.1:${webPort}/admin/login`)).ok) { ready = true; break; }
      } catch { /* starting */ }
      await new Promise(r => setTimeout(r, 200));
    }
    if (!ready) throw new Error(`The Engagement test server did not start.\n${serverOutput}`);
  } catch (error) {
    if (server && server.exitCode === null) server.kill('SIGTERM');
    fs.rmSync(fixturePath, { force: true });
    releaseLock();
    throw error;
  }

  return async () => {
    if (server && server.exitCode === null) {
      server.kill('SIGTERM');
      await new Promise<void>(resolve => {
        server!.once('exit', () => resolve());
        setTimeout(resolve, 3000).unref();
      });
    }
    fs.rmSync(fixturePath, { force: true });
    releaseLock();
  };
}

/** Refuse to install into anything other than the freshly marked disposable db_test. */
function assertMarkedDatabase(root: string, env: NodeJS.ProcessEnv) {
  const php = `$p = new PDO('mysql:host=127.0.0.1;port=' . getenv('CRAFT_DB_PORT') . ';dbname=db_test', 'db', 'db');
echo $p->query('SELECT identity FROM engagement_test_database_marker LIMIT 1')->fetchColumn();`;
  const marker = execFileSync('php', ['-r', php], { cwd: root, env, encoding: 'utf8' }).trim();
  if (marker !== 'craft-engagement-testbench:db_test') {
    throw new Error('Browser setup requires the marked disposable db_test database. Refusing to continue.');
  }
}

function resolveDdevDatabasePort(): string {
  const output = execFileSync('ddev', ['describe', '--json-output'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  const description = JSON.parse(output);
  const port = description?.raw?.dbinfo?.published_port ?? description?.dbinfo?.published_port;
  if (!Number.isInteger(Number(port)) || Number(port) <= 0) {
    throw new Error('DDEV did not report a published database port. Set ENGAGEMENT_BROWSER_DB_PORT explicitly.');
  }
  return String(port);
}
