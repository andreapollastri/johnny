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
import { mkdir } from 'node:fs/promises';
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

// Native confirm() dialogs guard every destructive form: accept them.
function autoAccept(page) {
  page.on('dialog', (dialog) => dialog.accept());
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
  autoAccept(page);
  await login(page);

  await page.goto(`${baseUrl}/dashboard?refresh=1`);
  await shot(page, 'dashboard');

  await page.goto(`${baseUrl}/buckets`);
  await shot(page, 'buckets');

  await page.goto(`${baseUrl}/buckets/website-assets`);
  await shot(page, 'objects');

  await page.goto(`${baseUrl}/buckets/website-assets?tab=keys`);
  await shot(page, 'permissions');

  // Create a key so the one-time credentials card is visible, then remove it again.
  await page.goto(`${baseUrl}/keys`);
  await page.fill('input[name=name]', 'mobile-app');
  await Promise.all([page.waitForLoadState('load'), page.click('form[action$="/keys"] button[type=submit]')]);
  await page.waitForSelector('.card-highlight');
  await shot(page, 'keys');
  const row = page.locator('tr', { hasText: 'mobile-app' }).first();
  await Promise.all([page.waitForLoadState('load'), row.locator('button.danger').click()]);

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
  await context.close();
}

await browser.close();
console.log('Done.');
