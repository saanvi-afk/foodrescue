// Optional visual/E2E suite. Use an existing Chrome and playwright-core, not a downloaded browser.
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const {spawn,execFileSync} = require('node:child_process');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright-core');
const root = path.resolve(__dirname, '..');
const env = {...process.env};
for (const line of fs.readFileSync(path.join(root, '.env'), 'utf8').split('\n')) {
  if (line && !line.startsWith('#') && line.includes('=')) { const at = line.indexOf('='); const key = line.slice(0, at).trim(); env[key] ??= line.slice(at + 1).trim(); }
}
env.DB_DATABASE = env.TEST_DB_DATABASE || 'foodrescue_test';
if (!/^[a-zA-Z0-9_]+_test$/.test(env.DB_DATABASE)) throw new Error('Use disposable test database');
const php = env.PHP_BINARY || 'php';
let browser, server, checks = 0;
const errors = [];
function check(condition, label) { if (!condition) throw new Error(label); checks++; console.log('PASS ' + label); }
async function save(form, label, destination) {
  await Promise.all([form.page().waitForNavigation(), form.getByRole('button', {name: label, exact: true}).click()]);
  await form.page().waitForLoadState('domcontentloaded');
}
(async () => {
  const reservation = net.createServer(); await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve)); const port = reservation.address().port; await new Promise(resolve => reservation.close(resolve));
  execFileSync(php,['-r',"require 'config/bootstrap.php'; FoodRescue\\Core\\Database::connection()->exec('DELETE FROM auth_rate_limits');"],{cwd:root,env});
  const log = fs.openSync(path.join(root, '.runtime/browser-server.log'), 'w');
  server = spawn(php, ['-d','display_errors=0',path.join(root,'scripts/serve.php'),String(port)], {cwd: root, env, stdio: ['ignore',log,log]});
  const base = 'http://127.0.0.1:' + port;
  for (let i=0;i<60;i++) { try { if ((await fetch(base)).ok) break; } catch {} await new Promise(resolve => setTimeout(resolve,100)); }
  const executablePath = env.CHROME_BINARY || (process.platform === 'darwin' ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : '/usr/bin/google-chrome');
  browser = await chromium.launch({executablePath, headless:true});
  const pages = {};
  for (const role of ['donor','receiver','volunteer','admin','researcher']) {
    const context = await browser.newContext({viewport:{width:1440,height:1000}});
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message)); pages[role] = page;
    await page.goto(base + '/login');
    await page.getByLabel('Email address',{exact:true}).fill(role + '@foodrescue.test');
    await page.getByLabel('Password',{exact:true}).fill('Demo12345678!');
    await Promise.all([page.waitForURL('**/dashboard'),page.getByRole('button',{name:'Sign in →',exact:true}).click()]);
    check(await page.getByText('Account active',{exact:true}).isVisible(),role + ' signs in through the visible login form');
  }
  const tag = Date.now().toString();
  for (const role of ['donor','receiver']) {
    const page=pages[role]; await page.goto(base + '/organizations');
    const form=page.locator('form[data-api="/api/organizations/save"]').first();
    await form.getByLabel('Organization name',{exact:true}).fill('Browser ' + role + ' ' + tag);
    await form.getByLabel('Service address',{exact:true}).fill('Demonstration address, Delhi');
    await form.getByLabel('Latitude',{exact:true}).fill('28.61'); await form.getByLabel('Longitude',{exact:true}).fill('77.21');
    await save(form,'Create and link organization','/organizations');
    await page.getByRole('heading',{name:'Browser ' + role + ' ' + tag,exact:true}).waitFor();
    check(await page.getByRole('heading',{name:'Browser ' + role + ' ' + tag,exact:true}).isVisible(),role + ' creates and links organization through UI');
  }
  const receiver=pages.receiver;await receiver.goto(base + '/receiving');
  const receiving=receiver.locator('form[data-api="/api/receivers/save"]');
  await receiving.getByLabel('Receiving organization',{exact:true}).selectOption({label:'Browser receiver ' + tag});
  await receiving.getByLabel('Maximum concurrent capacity (kg)',{exact:true}).fill('100');
  await receiving.getByLabel('Bakery',{exact:true}).check();await receiving.getByLabel('Motorized transportation available',{exact:true}).check();
  await receiving.getByLabel('Collection base latitude',{exact:true}).fill('28.61');await receiving.getByLabel('Collection base longitude',{exact:true}).fill('77.21');
  const local = (date) => { date = new Date(date); date.setMinutes(date.getMinutes()-date.getTimezoneOffset());return date.toISOString().slice(0,16); };
  // Browser timezone defaults to the host; input conversion uses the same local-time helper.
  await receiving.getByLabel('Available from (your local time)',{exact:true}).fill(local(Date.now()-60000));
  await receiving.getByLabel('Available until (your local time)',{exact:true}).fill(local(Date.now()+86400000));
  await save(receiving,'Save receiving preferences','/receiving');check(await receiving.getByLabel('Bakery',{exact:true}).isChecked(),'Receiver categories persist after Fetch save');
  const donor=pages.donor;await donor.goto(base + '/donations/new');const form=donor.locator('form[data-api="/api/donations"]');
  await form.getByLabel('Donor organization',{exact:true}).selectOption({label:'Browser donor ' + tag});
  await form.getByLabel('Food name',{exact:true}).fill('Browser bread ' + tag);await form.getByLabel('Description',{exact:true}).fill('Browser-test food, not a real donation.');
  await form.getByLabel('Category',{exact:true}).selectOption({label:'Bakery'});await form.getByLabel('Quantity',{exact:true}).fill('5');
  await form.getByLabel('Prepared / packed at (local time)',{exact:true}).fill(local(Date.now()-1800000));await form.getByLabel('Use-by date and time (local time)',{exact:true}).fill(local(Date.now()+28800000));await form.getByLabel('Latest pickup (local time; no later than use-by)',{exact:true}).fill(local(Date.now()+21600000));
  await form.getByLabel('Measured storage temperature (°C)',{exact:true}).fill('25');await form.getByLabel('Ingredients',{exact:true}).fill('Wheat flour, water');await form.getByLabel('Allergens (write “None declared” if applicable)',{exact:true}).fill('Wheat');
  for (const label of ['Handled hygienically and stored separately from waste','Food has not been served to customers','No known contamination, spoilage or pest damage']) await form.getByLabel(label,{exact:true}).check();
  await form.getByLabel('Pickup address',{exact:true}).fill('Demonstration kitchen');await form.getByLabel('Latitude',{exact:true}).fill('28.61');await form.getByLabel('Longitude',{exact:true}).fill('77.21');
  await save(form,'Create donation and screen eligibility','/donations/detail');const donationURL=donor.url();
  check(await donor.getByText('Available',{exact:true}).first().isVisible(),'Donor creates a screened donation through UI');
  await donor.locator('form[data-api="/api/uploads"] input[type=file]').setInputFiles({name:'test.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+yRZkAAAAASUVORK5CYII=','base64')});
  await Promise.all([donor.waitForNavigation(),donor.getByRole('button',{name:'Upload photograph',exact:true}).click()]);
  check(await donor.locator('.food-images img').count()===1,'Food image upload is served through authenticated file route');
  await receiver.goto(donationURL);receiver.once('dialog',dialog=>dialog.accept());await Promise.all([receiver.waitForURL('**/pickups'),receiver.getByRole('button',{name:'Accept and reserve collection',exact:true}).click()]);
  check(await receiver.getByRole('heading',{name:'Browser bread ' + tag,exact:true}).count()===1,'Receiver accepts the matching donation');
  await donor.goto(base + '/pickups');const card=donor.locator('article.panel').filter({has:donor.getByRole('heading',{name:'Browser bread ' + tag,exact:true})});
  const schedule=card.locator('form[data-api="/api/pickups/schedule"]');await schedule.getByLabel('Collection starts at (local time)',{exact:true}).fill(local(Date.now()+300000));
  await schedule.getByRole('button',{name:'Schedule and generate one-use QR',exact:true}).click();await card.locator('.token-output code').waitFor();const token=await card.locator('.token-output code').textContent();
  check(/^[a-f0-9]{64}$/.test(token),'Scheduling exposes a usable one-time token and downloadable QR');
  check(await donor.evaluate(async expected => { const img=document.querySelector('.token-output img');await img.decode();const c=document.createElement('canvas');c.width=img.naturalWidth;c.height=img.naturalHeight;const ctx=c.getContext('2d');ctx.drawImage(img,0,0);const pixels=ctx.getImageData(0,0,c.width,c.height);return jsQR(pixels.data,c.width,c.height)?.data===expected; },token),'Generated pickup QR decodes to the exact token with the browser scanner library');
  await receiver.reload();const receiverCard=receiver.locator('article.panel').filter({has:receiver.getByRole('heading',{name:'Browser bread ' + tag,exact:true})});await receiverCard.getByText('Verify token and record collection',{exact:true}).click();const verifyForm=receiverCard.locator('form[data-api="/api/pickups/verify"]');
  await verifyForm.getByLabel('Pickup token',{exact:true}).fill(token);await verifyForm.getByLabel('Actual collected weight (kg)',{exact:true}).fill('4.5');await save(verifyForm,'Verify and record collection','/pickups');
  check(await receiver.getByText('Actual collected: 4.500 kg',{exact:true}).isVisible(),'Actual collection is recorded through UI');
  await donor.reload();donor.once('dialog',dialog=>dialog.accept());await Promise.all([donor.waitForNavigation(),card.locator('form[data-api="/api/pickups/complete"] button').click()]);
  await receiver.reload();const complete=receiverCard.locator('form[data-api="/api/pickups/complete"]');await complete.getByLabel('Weight actually distributed (kg; enter 0 if not yet distributed)',{exact:true}).fill('4');receiver.once('dialog',dialog=>dialog.accept());await save(complete,'Confirm collection','/pickups');
  check(await receiverCard.getByText('Completed',{exact:true}).isVisible(),'Both parties confirm and complete the pickup');
  await donor.goto(base + '/impact');check(await donor.locator('canvas').count()>=1,'Recorded impact charts render');
  const researcher=pages.researcher;await researcher.goto(base + '/research/experiments');const dataset=researcher.locator('form[data-api="/api/research/datasets"]');await dataset.getByLabel('Dataset name',{exact:true}).fill('Browser simulation ' + tag);await dataset.getByLabel('Donation count',{exact:true}).fill('20');await dataset.getByLabel('Receiver count',{exact:true}).fill('5');await save(dataset,'Generate reproducible dataset','/research/experiments');
  const experiment=researcher.locator('form[data-api="/api/research/experiments"]');await experiment.getByLabel('Experiment name',{exact:true}).fill('Browser comparison');await experiment.getByLabel('Dataset',{exact:true}).selectOption({label:'Browser simulation ' + tag});await Promise.all([researcher.waitForURL('**/experiments/*'),experiment.getByRole('button',{name:'Run all four approaches',exact:true}).click()]);
  check(await researcher.getByRole('heading',{name:'Recorded comparison',exact:true}).isVisible(),'Research comparison runs through UI');
  await pages.admin.goto(base + '/admin/manage');check(await pages.admin.getByRole('heading',{name:'Versioned safety-screening rules',exact:true}).isVisible(),'Admin has operational rule-management screens');
  await donor.setViewportSize({width:390,height:844});await donor.goto(base+'/donations');
  check(await donor.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Mobile donation page has no horizontal overflow');
  await donor.screenshot({path:path.join(root,'storage/exports/mobile-donations.png'),fullPage:true});
  await researcher.screenshot({path:path.join(root,'storage/exports/research-comparison.png'),fullPage:true});
  check(errors.length===0,'No uncaught browser JavaScript errors: ' + errors.join('; '));
  console.log(checks + ' browser checks passed');
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();if(server)server.kill('SIGTERM');});
