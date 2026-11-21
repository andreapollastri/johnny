// Capture the screenshots used by README.md and the docs site from the running demo.
//
//   bash demo/up.sh
//   npm --prefix demo install
//   node demo/screenshots.mjs [output-dir]      (default: docs/img)
//
// Uses the locally installed Google Chrome through playwright-core (no browser download).
// Set CHROME_PATH to use another Chromium build. Env overrides: JOHNNY_DEMO_PANEL_URL,
// JOHNNY_DEMO_EMAIL, JOHNNY_DEMO_PASSWORD.

import { chromium } from 'playwright-core';
import { mkdir, readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const outDir = resolve(process.argv[2] ?? resolve(here, '../docs/img'));
const baseUrl = process.env.JOHNNY_DEMO_PANEL_URL ?? 'http://127.0.0.1:8099';
const email = process.env.JOHNNY_DEMO_EMAIL ?? 'demo@johnny.test';
const password = process.env.JOHNNY_DEMO_PASSWORD ?? 'johnny-demo';

const viewport = { width: 1280, height: 800 };

await mkdir(outDir, { recursive: true });

const browser = await chromium.launch(
  process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : { channel: 'chrome' },
);

async function newContext(theme = 'light') {
  const context = await browser.newContext({ viewport, deviceScaleFactor: 2, colorScheme: theme });
  await context.addInitScript((t) => {
    try { localStorage.setItem('theme', t); } catch (e) {}
  }, theme);
  return context;
}

async function login(page) {
  await page.goto(`${baseUrl}/login`);
  await page.fill('#email', email);
  await page.fill('#password', password);
  await Promise.all([page.waitForURL(/\/dashboard/), page.click('form[action$="/login"] button[type=submit]')]);
}

async function shot(page, name, { fullPage = true } = {}) {
  await page.evaluate(() => document.fonts.ready);
  const path = resolve(outDir, `${name}.jpg`);
  await page.screenshot({ path, fullPage, type: 'jpeg', quality: 85 });
  console.log(`  ✓ ${name}.jpg`);
}

// Destructive forms ask first in the panel's own dialog: submit, then confirm there.
async function confirmSubmit(page, button) {
  await button.click();
  await page.waitForSelector('#confirm-dialog[open]');
  await Promise.all([page.waitForLoadState('load'), page.click('#confirm-dialog [data-confirm-ok]')]);
}

// The S3 endpoint and key of the demo, from the env file demo/up.sh writes for the panel.
async function demoS3() {
  const env = {};
  const text = await readFile(resolve(here, '../panel/.env.demo'), 'utf8');
  for (const line of text.split('\n')) {
    const match = line.match(/^(GARAGE_[A-Z_]+)=(.*)$/);
    if (match) env[match[1]] = match[2].trim();
  }
  return env;
}

async function transfers(page) {
  const s3 = await demoS3();

  await page.goto(`${baseUrl}/transfers`);
  await page.click('[data-dialog-open="#connection-dialog"]');
  await page.waitForSelector('#connection-dialog[open]');
  await page.fill('#conn-name', 'Office NAS');
  await page.selectOption('#conn-provider', 'sftp');
  await page.fill('#conn-host', 'nas.example.com');
  await page.fill('#conn-user', 'backup');
  await shot(page, 'sftp', { fullPage: false });

  // Not saved: the demo has no SFTP server. Save a connection to the demo's own S3 instead.
  await page.fill('#conn-name', 'Johnny demo');
  await page.selectOption('#conn-provider', 'johnny');
  await page.fill('#conn-endpoint', s3.GARAGE_ENDPOINT);
  await page.fill('#conn-key', s3.GARAGE_ACCESS_KEY_ID);
  await page.fill('#conn-secret', s3.GARAGE_SECRET_ACCESS_KEY);
  await page.fill('#conn-region', s3.GARAGE_DEFAULT_REGION ?? 'johnny');
  await page.check('input[name=path_style]');
  await Promise.all([page.waitForURL(/\/connections\/\d+/), page.click('#connection-dialog button[type=submit]')]);
  await shot(page, 'connection');
  const connectionUrl = page.url();

  await page.goto(`${baseUrl}/transfers/new?schedule=repeat&direction=import`);
  await page.fill('#remote_bucket', 'website-assets');
  await page.fill('#local_bucket', 'website-assets-copy');
  await page.selectOption('#interval', '5');
  await shot(page, 'transfer-new');
  await Promise.all([page.waitForURL(/\/syncs\/\d+/), page.click('[data-transfer-form] button[type=submit]')]);
  await page.waitForTimeout(4000);
  await page.reload();
  await shot(page, 'sync');
  const syncUrl = page.url();

  await page.locator('tr[data-transfer] a', { hasText: 'Details' }).first().click();
  await page.waitForLoadState('load');
  await shot(page, 'transfer');

  await page.goto(`${baseUrl}/transfers`);
  await shot(page, 'transfers');

  // Leave the demo as it was: no sync, no connection (the copied bucket stays).
  await page.goto(syncUrl);
  await page.waitForTimeout(3000);
  await confirmSubmit(page, page.locator('form[action*="/syncs/"] button.btn-danger'));
  await page.goto(connectionUrl);
  const remove = page.locator('form[data-confirm] button.btn-danger').last();
  if (await remove.count()) await confirmSubmit(page, remove);
}

console.log(`Capturing ${baseUrl} → ${outDir}`);

// --- Sign-in page ---------------------------------------------------------
{
  const context = await newContext('light');
  const page = await context.newPage();
  await page.goto(`${baseUrl}/login`);
  await page.fill('#email', email);
  await shot(page, 'login', { fullPage: false });
  await context.close();
}

// --- Light theme pages ----------------------------------------------------
{
  const context = await newContext('light');
  const page = await context.newPage();
  await login(page);

  await page.goto(`${baseUrl}/dashboard?refresh=1`);
  await shot(page, 'dashboard');

  await page.goto(`${baseUrl}/buckets`);
  await shot(page, 'buckets');

  await page.goto(`${baseUrl}/buckets/website-assets`);
  await shot(page, 'objects');

  await page.goto(`${baseUrl}/buckets/website-assets?tab=keys`);
  await shot(page, 'permissions');

  await page.goto(`${baseUrl}/buckets/website-assets?tab=settings`);
  await shot(page, 'bucket-settings');

  // Create a read-only key for all buckets so the one-time credentials card is visible, then
  // remove it again.
  await page.goto(`${baseUrl}/keys`);
  await page.fill('#key_name', 'mobile-app');
  await page.check('input[name=access][value=all]');
  await page.selectOption('#key_scope', 'read');
  await Promise.all([page.waitForLoadState('load'), page.click('#create button[type=submit]')]);
  await page.waitForSelector('.card-highlight');
  await shot(page, 'keys');
  const row = page.locator('tr', { hasText: 'mobile-app' }).first();
  await confirmSubmit(page, row.locator('button.btn-danger'));

  // The page of a key, with its buckets and scopes.
  await page.goto(`${baseUrl}/keys`);
  await page.locator('tbody a.cell-main').first().click();
  await page.waitForLoadState('load');
  await shot(page, 'key');

  // A file open in the preview drawer.
  await page.goto(`${baseUrl}/buckets/media-library?tab=objects&prefix=photos/2026/09/`);
  if (await page.locator('[data-fm-open]').count()) {
    await page.locator('[data-fm-open]').first().click();
    await page.waitForSelector('#fm-drawer:not([hidden])');
    await page.waitForTimeout(800);
    await shot(page, 'preview', { fullPage: false });
  }

  await page.goto(`${baseUrl}/dashboard`);
  await page.keyboard.press('Control+k');
  await page.waitForSelector('dialog.palette[open]');
  await page.locator('.palette-input').fill('me');
  await page.waitForTimeout(500);
  await shot(page, 'palette', { fullPage: false });

  await page.goto(`${baseUrl}/updates`);
  await shot(page, 'updates');

  // Transfers need another provider: the demo connects to itself, as "another Johnny", and keeps
  // one bucket in sync with a second one. Needs rclone; without it the pages are still captured.
  try {
    await transfers(page);
  } catch (error) {
    console.log(`  ! transfers not captured: ${error.message.split('\n')[0]}`);
  }

  // Start 2FA enrolment (password confirmation, then QR code), capture, then cancel it.
  await page.goto(`${baseUrl}/settings`);
  await page.click('form[action$="/user/two-factor-authentication"] button[type=submit]');
  if (page.url().includes('confirm-password')) {
    await page.fill('#password', password);
    await Promise.all([page.waitForURL(/\/settings/), page.click('form[action$="/user/confirm-password"] button[type=submit]')]);
    await page.click('form[action$="/user/two-factor-authentication"] button[type=submit]');
  }
  await page.waitForSelector('.qr-frame');
  await shot(page, 'settings');
  await page.locator('form[action$="/user/two-factor-authentication"]', { hasText: 'Cancel setup' }).locator('button').click();
  await page.waitForLoadState('load');

  await page.goto(`${baseUrl}/api/docs`);
  await page.waitForSelector('.opblock', { timeout: 30000 });
  await shot(page, 'api');

  await context.close();
}

// --- Dark theme -----------------------------------------------------------
{
  const context = await newContext('dark');
  const page = await context.newPage();
  await login(page);
  await page.goto(`${baseUrl}/dashboard`);
  await shot(page, 'dashboard-dark');
  await page.goto(`${baseUrl}/buckets/media-library?tab=objects&prefix=photos/2026/09/`);
  await shot(page, 'objects-dark', { fullPage: false });
  await page.goto(`${baseUrl}/transfers`);
  await shot(page, 'transfers-dark', { fullPage: false });
  await context.close();
}

await browser.close();
console.log('Done.');
